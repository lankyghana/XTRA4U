<?php

namespace Tests\Feature\Ai\Support;

use App\Services\Ai\Integrity\FileIntegrityAnchor;
use App\Services\Ai\Integrity\IntegrityAnchor;
use App\Services\Ai\Integrity\IntegrityGuard;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\DB;

/**
 * A throw-away independent anchor for tests: a fresh directory under the system temp dir (outside the repository, so the
 * anchor's own "not inside the application" rule holds) and a fixture key. Nothing here is a credential.
 *
 * The anchor lives in a separate FILE while the test database is in memory or a throw-away database, which is exactly what makes
 * "restore an older database" testable: snapshot the database tables, keep going, put the snapshot back, and the anchor - which
 * the snapshot does not contain - remembers the newer history.
 */
trait UsesIntegrityAnchor
{
    protected ?string $anchorDir = null;

    public const ANCHOR_KEY = 'test-anchor-key-0123456789abcdef0123456789';

    public const INTEGRITY_MIGRATION = 'database/migrations/2026_11_24_000001_add_integrity_anchor_sequence_to_ai_budget_reservations.php';

    protected function integrityMigration(): object
    {
        return require base_path(self::INTEGRITY_MIGRATION);
    }

    /** Configure an anchor in a fresh directory and bind the (empty) test database to it. The kill switch ends engaged. */
    protected function useIntegrityAnchor(): void
    {
        $this->removeAnchorDir();

        $this->anchorDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xtra4u-anchor-'.bin2hex(random_bytes(6));
        mkdir($this->anchorDir);

        $this->integrityMigration()->up();

        $this->configureAnchor($this->anchorDir);

        app(IntegrityGuard::class)->initialise('test setup', true, true);
    }

    protected function configureAnchor(string $dir): void
    {
        config([
            'ai.integrity.anchor.driver' => 'file',
            'ai.integrity.anchor.path' => $dir,
            'ai.integrity.anchor.key' => self::ANCHOR_KEY,
            'ai.integrity.anchor.independence_attested' => true,
            'ai.integrity.anchor.independence_reference' => 'TEST-INDEPENDENCE-REF',
        ]);
    }

    /** Start the anchor over together with an emptied database (used by prepareDurableAiState). */
    protected function rebuildTestAnchor(): void
    {
        $this->app->forgetInstance(IntegrityAnchor::class);

        foreach (glob($this->anchorDir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }

        $this->configureAnchor($this->anchorDir);
        app(IntegrityGuard::class)->initialise('test reset', true, true);
    }

    protected function anchor(): FileIntegrityAnchor
    {
        return new FileIntegrityAnchor((string) $this->anchorDir, self::ANCHOR_KEY);
    }

    protected function removeAnchorDir(): void
    {
        if ($this->anchorDir !== null && is_dir($this->anchorDir)) {
            foreach (glob($this->anchorDir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->anchorDir);
        }

        $this->anchorDir = null;
    }

    /**
     * "Restore an older database backup": capture every AI table (not the anchor), and later put it back exactly as it was.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    protected function snapshotDatabase(): array
    {
        $out = [];
        foreach ([UsageMeter::COUNTERS_TABLE, UsageMeter::LEDGER_TABLE, UsageMeter::FLAGS_TABLE] as $t) {
            $out[$t] = DB::table($t)->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    /** @param array<string,list<array<string,mixed>>> $snapshot */
    protected function restoreDatabase(array $snapshot): void
    {
        foreach ($snapshot as $table => $rows) {
            DB::table($table)->delete();
            foreach ($rows as $row) {
                DB::table($table)->insert($row);
            }
        }
    }

    /** @return array<string,mixed> the anchor's document, for tests that need to look inside or tamper with it */
    protected function anchorDocument(): array
    {
        return json_decode((string) file_get_contents($this->anchorDir.DIRECTORY_SEPARATOR.FileIntegrityAnchor::FILE), true);
    }

    protected function writeAnchorDocumentRaw(string $contents): void
    {
        file_put_contents($this->anchorDir.DIRECTORY_SEPARATOR.FileIntegrityAnchor::FILE, $contents);
    }
}
