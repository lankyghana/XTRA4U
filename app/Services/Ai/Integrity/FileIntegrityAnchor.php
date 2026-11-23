<?php

namespace App\Services\Ai\Integrity;

/**
 * Reference implementation of {@see IntegrityAnchor}: one authenticated JSON document in a directory that the OPERATOR
 * provides (config `ai.integrity.anchor.path`), plus a lock file, written atomically (temp file + fsync + rename) under an
 * exclusive `flock`.
 *
 * What this class IS: a correct, process-safe, tamper-evident (HMAC-SHA256 with a separate key) monotonic record.
 * What it is NOT: independent infrastructure. It is only as independent as the directory it is given. A path on the same
 * disk, the same account backup or the same restore job as the database is NOT independent, and the repository cannot tell.
 * That is why `ai:preflight` additionally demands an explicit operator attestation (with a reference to where the independence
 * is documented) and why the milestone stays blocked until such a location exists. See docs/ai/06 section 19.
 *
 * Failure behaviour: a missing, unreadable, unauthenticated or malformed document is an AnchorException, never an empty state.
 * An existing anchor is never overwritten by initialise().
 */
final class FileIntegrityAnchor implements IntegrityAnchor
{
    public const FILE = 'ai-anchor.json';

    public const LOCK = 'ai-anchor.lock';

    public const AUDIT = 'ai-anchor-events.log';

    private const VERSION = 1;

    private const LOCK_WAIT_SECONDS = 5.0;

    private const STATES = ['open', 'settled', 'settled_unknown', 'failed_unknown', 'released'];

    public function __construct(private readonly string $directory, private readonly string $key) {}

    public function configurationProblems(): array
    {
        $problems = [];

        if (strlen($this->key) < 32) {
            $problems[] = 'AI_INTEGRITY_ANCHOR_KEY must be a secret of at least 32 characters';
        }

        $dir = $this->directory;

        if ($dir === '' || ! $this->isAbsolute($dir)) {
            $problems[] = 'AI_INTEGRITY_ANCHOR_PATH must be an absolute directory path';

            return $problems;
        }

        if (! is_dir($dir)) {
            $problems[] = 'the anchor directory does not exist (it is never created automatically)';

            return $problems;
        }

        if (! is_writable($dir)) {
            $problems[] = 'the anchor directory is not writable';
        }

        $real = realpath($dir);
        foreach ([base_path(), database_path(), storage_path()] as $inside) {
            $root = realpath($inside);

            if ($real !== false && $root !== false && $this->isWithin($real, $root)) {
                $problems[] = 'the anchor directory is inside the application or database directories (a deploy or a restore of the application would take it along)';
                break;
            }
        }

        return $problems;
    }

    public function read(): AnchorSnapshot
    {
        return $this->locked(false, fn () => $this->snapshot($this->load()));
    }

    public function initialise(string $anchorId, array $records, string $killState, string $reason): AnchorSnapshot
    {
        $this->assertUsable();

        return $this->locked(true, function () use ($anchorId, $records, $killState, $reason): AnchorSnapshot {
            if (is_file($this->path(self::FILE))) {
                throw AnchorException::conflict('An integrity anchor already exists here; it is never overwritten.');
            }

            $now = gmdate('c');
            $state = [
                'v' => self::VERSION,
                'anchor_id' => $anchorId,
                'created_at' => $now,
                'seq' => 0,
                'epoch' => 1,
                'kill' => ['state' => $killState, 'generation' => 1, 'reason' => mb_substr($reason, 0, 255), 'at' => $now],
                'reconciliation' => ['status' => 'ok', 'reason' => null, 'at' => $now],
                'reservations' => [],
            ];

            foreach ($records as $r) {
                $state['reservations'][(string) $r['id']] = $this->recordFrom($r, ++$state['seq']);
            }

            $this->store($state);
            $this->audit('initialised', $state['seq']);

            return $this->snapshot($state);
        });
    }

    public function reserve(string $id, array $record, ?array $closePrevious = null): int
    {
        return $this->locked(true, function () use ($id, $record, $closePrevious): int {
            $state = $this->load();

            if (isset($state['reservations'][$id])) {
                $existing = $state['reservations'][$id];

                if ($existing['day'] !== $record['day'] || $existing['surface'] !== $record['surface']) {
                    throw AnchorException::conflict('The reservation id conflicts with an existing anchor record.');
                }

                return (int) $existing['seq'];
            }

            if ($closePrevious !== null) {
                $this->applyClose($state, $closePrevious['id'], $closePrevious['state'], (int) $closePrevious['tokens'], (int) $closePrevious['cost']);
            }

            $state['seq'] = (int) $state['seq'] + 1;
            $state['reservations'][$id] = $this->recordFrom($record + ['state' => 'open'], $state['seq']);

            $this->store($state);
            $this->audit('reserve', $state['seq']);

            return $state['seq'];
        });
    }

    public function close(string $id, string $state, int $tokens, int $cost): void
    {
        $this->locked(true, function () use ($id, $state, $tokens, $cost): void {
            $doc = $this->load();

            if ($this->applyClose($doc, $id, $state, $tokens, $cost)) {
                $this->store($doc);
                $this->audit('close', (int) $doc['seq']);
            }
        });
    }

    public function setKillSwitch(string $state, string $reason): array
    {
        if (! in_array($state, ['engaged', 'released'], true)) {
            throw AnchorException::conflict('Unknown kill-switch state.');
        }

        return $this->locked(true, function () use ($state, $reason): array {
            $doc = $this->load();
            $doc['kill'] = [
                'state' => $state,
                'generation' => (int) $doc['kill']['generation'] + 1,
                'reason' => mb_substr($reason, 0, 255),
                'at' => gmdate('c'),
            ];

            $this->store($doc);
            $this->audit('kill_'.$state, (int) $doc['seq']);

            return $doc['kill'];
        });
    }

    public function setReconciliation(string $status, string $reason): void
    {
        if (! in_array($status, ['ok', 'required'], true)) {
            throw AnchorException::conflict('Unknown reconciliation status.');
        }

        $this->locked(true, function () use ($status, $reason): void {
            $doc = $this->load();
            $doc['reconciliation'] = ['status' => $status, 'reason' => mb_substr($reason, 0, 255), 'at' => gmdate('c')];
            $doc['epoch'] = (int) $doc['epoch'] + 1;

            $this->store($doc);
            $this->audit('reconciliation_'.$status, (int) $doc['seq']);
        });
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @param array<string,mixed> $doc */
    private function applyClose(array &$doc, string $id, string $state, int $tokens, int $cost): bool
    {
        if (! in_array($state, self::STATES, true) || $state === 'open') {
            throw AnchorException::conflict('Unknown reservation state.');
        }

        $r = $doc['reservations'][$id] ?? null;
        if ($r === null) {
            // The database claims a reservation this anchor never accepted: that is never silently invented here.
            throw AnchorException::conflict('The anchor has no such reservation.');
        }

        if ($r['state'] !== 'open') {
            return false; // at most once: the first outcome stands
        }

        $doc['reservations'][$id]['state'] = $state;
        $doc['reservations'][$id]['tokens'] = max(0, $tokens);
        $doc['reservations'][$id]['cost'] = max(0, $cost);

        return true;
    }

    /**
     * @param  array<string,mixed>  $r
     * @return array<string,mixed>
     */
    private function recordFrom(array $r, int $seq): array
    {
        foreach (['day', 'surface'] as $k) {
            if (! isset($r[$k]) || ! is_string($r[$k]) || $r[$k] === '') {
                throw AnchorException::conflict('An anchor record needs a day and a surface.');
            }
        }

        $state = (string) ($r['state'] ?? 'open');
        if (! in_array($state, self::STATES, true)) {
            throw AnchorException::conflict('Unknown reservation state.');
        }

        return [
            'seq' => $seq,
            'day' => $r['day'],
            'surface' => $r['surface'],
            'state' => $state,
            'requests' => (int) ($r['requests'] ?? 1),
            'tokens' => max(0, (int) ($r['tokens'] ?? 0)),
            'cost' => max(0, (int) ($r['cost'] ?? 0)),
            'reserved_tokens' => max(0, (int) ($r['reserved_tokens'] ?? 0)),
            'reserved_cost' => isset($r['reserved_cost']) ? max(0, (int) $r['reserved_cost']) : null,
            'retry_of' => isset($r['retry_of']) && $r['retry_of'] !== '' ? (string) $r['retry_of'] : null,
        ];
    }

    /** @param array<string,mixed> $doc */
    private function snapshot(array $doc): AnchorSnapshot
    {
        return new AnchorSnapshot(
            (string) $doc['anchor_id'],
            (int) $doc['seq'],
            (int) $doc['epoch'],
            $doc['kill'],
            $doc['reconciliation'],
            $doc['reservations'],
        );
    }

    /** @throws AnchorException */
    private function assertUsable(): void
    {
        $problems = $this->configurationProblems();

        if ($problems !== []) {
            throw AnchorException::unavailable('The anchor is not usable: '.$problems[0].'.');
        }
    }

    /**
     * Run `$fn` holding the anchor lock (shared for reads, exclusive for writes). The wait is bounded: a lock that cannot
     * be had in a few seconds is an UNAVAILABLE anchor, which callers treat as "do not proceed".
     *
     * @template T
     *
     * @param  \Closure():T  $fn
     * @return T
     */
    private function locked(bool $exclusive, \Closure $fn): mixed
    {
        $this->assertUsable();

        $handle = @fopen($this->path(self::LOCK), 'c');
        if ($handle === false) {
            throw AnchorException::unavailable('The anchor lock cannot be opened.');
        }

        try {
            $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
            while (! flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
                if (microtime(true) > $deadline) {
                    throw AnchorException::unavailable('The anchor is locked by another process.');
                }
                usleep(2000);
            }

            return $fn();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * @return array<string,mixed>
     *
     * @throws AnchorException
     */
    private function load(): array
    {
        $file = $this->path(self::FILE);

        if (! is_file($file)) {
            throw AnchorException::missing('The integrity anchor does not exist.');
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            throw AnchorException::corrupt('The integrity anchor cannot be read.');
        }

        $doc = json_decode($raw, true);
        if (! is_array($doc) || ! isset($doc['mac']) || ! is_string($doc['mac'])) {
            throw AnchorException::corrupt('The integrity anchor is malformed.');
        }

        $mac = $doc['mac'];
        unset($doc['mac']);

        if (! hash_equals($this->mac($doc), $mac)) {
            throw AnchorException::corrupt('The integrity anchor failed authentication.');
        }

        if (($doc['v'] ?? null) !== self::VERSION
            || ! is_string($doc['anchor_id'] ?? null)
            || ! is_int($doc['seq'] ?? null) || $doc['seq'] < 0
            || ! is_int($doc['epoch'] ?? null)
            || ! is_array($doc['kill'] ?? null) || ! in_array($doc['kill']['state'] ?? null, ['engaged', 'released'], true) || ! is_int($doc['kill']['generation'] ?? null)
            || ! is_array($doc['reconciliation'] ?? null) || ! in_array($doc['reconciliation']['status'] ?? null, ['ok', 'required'], true)
            || ! is_array($doc['reservations'] ?? null)) {
            throw AnchorException::corrupt('The integrity anchor has an unexpected structure.');
        }

        return $doc;
    }

    /** @param array<string,mixed> $doc */
    private function store(array $doc): void
    {
        unset($doc['mac']);
        $doc['mac'] = $this->mac($doc);

        $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tmp = $this->path(self::FILE.'.'.getmypid().'.tmp');

        $h = @fopen($tmp, 'wb');
        if ($h === false) {
            throw AnchorException::unavailable('The anchor cannot be written.');
        }

        $ok = fwrite($h, $json) === strlen($json) && fflush($h) && (! function_exists('fsync') || fsync($h));
        fclose($h);

        if (! $ok || ! @rename($tmp, $this->path(self::FILE))) {
            @unlink($tmp);

            throw AnchorException::unavailable('The anchor cannot be written.');
        }
    }

    /** @param array<string,mixed> $doc document WITHOUT its `mac` */
    private function mac(array $doc): string
    {
        return hash_hmac('sha256', json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $this->key);
    }

    /** Best-effort, human-readable trail; never authoritative, never read back, and it holds no text beyond event names. */
    private function audit(string $event, int $seq): void
    {
        try {
            @file_put_contents($this->path(self::AUDIT), gmdate('c').' '.$event.' seq='.$seq."\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
        }
    }

    private function path(string $name): string
    {
        return rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.$name;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    private function isWithin(string $path, string $root): bool
    {
        $a = rtrim(str_replace('\\', '/', strtolower($path)), '/').'/';
        $b = rtrim(str_replace('\\', '/', strtolower($root)), '/').'/';

        return str_starts_with($a, $b);
    }
}
