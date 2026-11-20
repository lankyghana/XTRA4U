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

    /** @template T @param \Closure():T $callback @return T */
    public function run(\Closure $callback): mixed
    {
        $this->install();
        $this->depth++;

        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    private function install(): void
    {
        $this->installed ??= new \WeakMap;
        $connection = DB::connection();

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
