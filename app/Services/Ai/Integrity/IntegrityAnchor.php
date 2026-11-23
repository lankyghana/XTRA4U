<?php

namespace App\Services\Ai\Integrity;

/**
 * An INDEPENDENT, authoritative record of the AI budget history and of the emergency stop (M5.7.2).
 *
 * Why it exists. The budget ledger, the counters and the kill switch all live in the application database. Anything stored
 * only there - however cleverly chained or checksummed - rolls back WITH the database: restore an older backup, run a
 * point-in-time recovery, truncate the tables, and every internal consistency check still passes against the older, smaller
 * history. Only a record held OUTSIDE the database's failure and restoration domain (different storage, different backup
 * lifecycle) can show that the database moved backwards. This contract is that record.
 *
 * What an implementation must guarantee (and what the repository can only assert, never prove - see docs/ai/06 section 19):
 *  - it is NOT restored together with the application database;
 *  - `seq` only ever increases, every change is durable before the method returns, and a failed change is reported as an
 *    exception (an unknown outcome is an exception too: callers treat it as "may have happened");
 *  - every read is authenticated; a record that fails authentication is AnchorException::CORRUPT, never "empty";
 *  - all methods are safe against concurrent callers in separate processes.
 *
 * Callers fail CLOSED on every AnchorException.
 */
interface IntegrityAnchor
{
    /**
     * Why this anchor cannot be used right now, from configuration alone (names only, never a value).
     *
     * @return list<string>
     */
    public function configurationProblems(): array;

    /** @throws AnchorException */
    public function read(): AnchorSnapshot;

    /**
     * Create the anchor. Refuses (CONFLICT) when one already exists: an existing anchor is never overwritten.
     *
     * @param  list<array<string,mixed>>  $records  existing reservations to take over, each with `id` and the record fields; they receive seq 1..n in the given order
     *
     * @throws AnchorException
     */
    public function initialise(string $anchorId, array $records, string $killState, string $reason): AnchorSnapshot;

    /**
     * Atomically accept a reservation (and, for a retry, close the previous attempt in the SAME write). Idempotent on the
     * id: asking again returns the first answer without charging twice.
     *
     * @param  array<string,mixed>  $record  day, surface, requests, tokens, cost, reserved_tokens, reserved_cost, retry_of
     * @param  ?array{id:string,state:string,tokens:int,cost:int}  $closePrevious
     * @return int the sequence number assigned to the reservation
     *
     * @throws AnchorException
     */
    public function reserve(string $id, array $record, ?array $closePrevious = null): int;

    /**
     * Move a reservation to its final state and charge. Idempotent: an already closed reservation keeps its first outcome.
     *
     * @throws AnchorException
     */
    public function close(string $id, string $state, int $tokens, int $cost): void;

    /**
     * Change the emergency stop; the generation increases by one on every change.
     *
     * @return array{state:string,generation:int,reason:?string,at:?string}
     *
     * @throws AnchorException
     */
    public function setKillSwitch(string $state, string $reason): array;

    /**
     * Mark the accounting as proven (`ok`) or as needing an explicit operator decision (`required`).
     *
     * @throws AnchorException
     */
    public function setReconciliation(string $status, string $reason): void;
}
