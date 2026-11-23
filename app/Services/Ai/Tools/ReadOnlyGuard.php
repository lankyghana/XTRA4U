<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Tools\Exceptions\ToolWriteBlockedException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Makes "tools are read-only" a runtime fact instead of a convention.
 *
 * While a tool's handle() runs (see run()), every SQL statement sent through the default database connection must be
 * a plain read (SELECT / SHOW / EXPLAIN / DESCRIBE, or a WITH that contains no write keyword). Anything else - an
 * INSERT/UPDATE/DELETE, DDL, `SELECT ... FOR UPDATE`, `INTO OUTFILE`, a stacked second statement - throws
 * ToolWriteBlockedException before it reaches the database. Outside run() the guard does nothing at all, so the rest
 * of the application is unaffected.
 *
 * Scope, stated honestly: it covers the DB connection the application uses (cache, session and queue writes that go
 * through it are blocked too, which is intended: a tool has no business writing them). It cannot see writes to files,
 * HTTP calls or other connections - the architecture test and code review cover those.
 */
class ReadOnlyGuard
{
    private int $depth = 0;

    /** @var \WeakMap<Connection,bool>|null */
    private ?\WeakMap $installed = null;

    /** @var ?string the session variable that bounds statement time on this server, once detected */
    private ?string $timeoutVariable = null;

    /** @template T @param \Closure():T $callback @return T */
    public function run(\Closure $callback): mixed
    {
        $this->install();
        $outermost = $this->depth === 0;
        $this->depth++;
        $bounded = $outermost && $this->boundStatementTime();

        try {
            return $callback();
        } finally {
            $this->depth--;

            if ($bounded) {
                $this->releaseStatementTime();
            }
        }
    }

    /**
     * Gives every statement a tool runs a server-side time limit (`ai.tools.limits.max_execution_seconds`), so one
     * slow query cannot hold a PHP worker: MariaDB `max_statement_time` (seconds) or MySQL `max_execution_time` (ms).
     * It goes straight to PDO on purpose (the read-only hook would rightly refuse a SET), is a session variable that is
     * reset afterwards, and is best effort: SQLite (tests, local dev) has no such limit, and a server that supports
     * neither variable simply runs without one (the post-hoc per-call time check still applies).
     */
    private function boundStatementTime(): bool
    {
        $seconds = (int) config('ai.tools.limits.max_execution_seconds', 5);
        $connection = $this->connection();

        if ($seconds < 1 || ! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }

        $pdo = $connection->getPdo();

        foreach ($this->timeoutVariable !== null ? [$this->timeoutVariable] : ['max_statement_time', 'max_execution_time'] as $variable) {
            $value = $variable === 'max_statement_time' ? $seconds : $seconds * 1000;

            try {
                $pdo->exec("SET SESSION {$variable} = {$value}");
                $this->timeoutVariable = $variable;

                return true;
            } catch (\Throwable) {
                // Not this server's variable name; try the other one.
            }
        }

        return false;
    }

    private function connection(): Connection
    {
        return DB::connection();
    }

    private function releaseStatementTime(): void
    {
        try {
            $this->connection()->getPdo()->exec('SET SESSION '.$this->timeoutVariable.' = 0');
        } catch (\Throwable) {
            // The connection is gone or unusable; nothing is left to reset.
        }
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    private function install(): void
    {
        $this->installed ??= new \WeakMap;
        $connection = $this->connection();

        if (isset($this->installed[$connection])) {
            return;
        }

        $connection->beforeExecuting(function (string $query): void {
            if ($this->depth > 0 && ! self::isReadOnlySql($query)) {
                throw new ToolWriteBlockedException('A tool attempted a database statement that is not a plain read.');
            }
        });

        $this->installed[$connection] = true;
    }

    public static function isReadOnlySql(string $sql): bool
    {
        // Drop comments, which could hide a keyword from the first-word test.
        $s = preg_replace('~/\*.*?\*/~s', ' ', $sql) ?? $sql;
        $s = preg_replace('~(--|#)[^\r\n]*~', ' ', $s) ?? $s;
        $s = strtolower(trim($s));
        $s = ltrim($s, "( \t\r\n");

        if ($s === '') {
            return false;
        }

        // One statement only.
        $body = rtrim($s, "; \t\r\n");
        if (str_contains($body, ';')) {
            return false;
        }

        // Row locks and file output are not "plain reads".
        if (preg_match('/\b(for\s+(update|share|no\s+key\s+update)|lock\s+in\s+share\s+mode|into\s+(outfile|dumpfile))\b/', $body) === 1) {
            return false;
        }

        $first = strtok($body, " \t\r\n(") ?: '';

        if (in_array($first, ['select', 'show', 'explain', 'describe', 'desc'], true)) {
            return true;
        }

        if ($first === 'with') {
            return preg_match('/\b(insert|update|delete|replace|merge|drop|alter|create|truncate|attach|detach|vacuum|pragma|grant|revoke|call)\b/', $body) !== 1;
        }

        return false;
    }
}
