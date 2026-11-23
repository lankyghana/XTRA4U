<?php

namespace Tests\Feature\Ai\Integrity;

use App\Services\Ai\Integrity\AnchorException;
use App\Services\Ai\Integrity\FileIntegrityAnchor;
use App\Services\Ai\Integrity\IntegrityGuard;
use App\Services\Ai\Integrity\UnconfiguredIntegrityAnchor;
use Tests\TestCase;

/**
 * The reference anchor driver on its own (no database): monotonic sequence, idempotency, at-most-once closes, authentication,
 * refusal to overwrite, configuration rules, and correctness under concurrent PHP processes.
 */
class AnchorProtocolTest extends TestCase
{
    private string $dir;

    private const KEY = 'unit-test-key-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xtra4u-anchor-unit-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function anchor(?string $key = null): FileIntegrityAnchor
    {
        return new FileIntegrityAnchor($this->dir, $key ?? self::KEY);
    }

    /** @return array<string,mixed> */
    private function record(string $surface = 'vendor', int $tokens = 100, int $cost = 40): array
    {
        return ['day' => '20260101', 'surface' => $surface, 'requests' => 1, 'tokens' => $tokens, 'cost' => $cost, 'reserved_tokens' => $tokens, 'reserved_cost' => $cost, 'retry_of' => null];
    }

    public function test_a_new_anchor_starts_empty_with_the_requested_kill_state(): void
    {
        $snap = $this->anchor()->initialise('anchor-1', [], 'engaged', 'first');

        $this->assertSame('anchor-1', $snap->anchorId);
        $this->assertSame(0, $snap->seq);
        $this->assertSame('engaged', $snap->kill['state']);
        $this->assertTrue($snap->reconciliationOk());
        $this->assertSame($snap->anchorId, $this->anchor()->read()->anchorId);
    }

    public function test_sequence_numbers_only_increase_and_a_repeated_id_is_answered_not_charged_again(): void
    {
        $a = $this->anchor();
        $a->initialise('a', [], 'engaged', 'x');

        $this->assertSame(1, $a->reserve('r1', $this->record()));
        $this->assertSame(2, $a->reserve('r2', $this->record('customer')));
        $this->assertSame(1, $a->reserve('r1', $this->record()), 'asking again returns the first answer');

        $snap = $a->read();
        $this->assertSame(2, $snap->seq);
        $this->assertSame(2, $snap->totals('20260101')['global']['requests']);
        $this->assertSame(200, $snap->totals('20260101')['global']['tokens']);
        $this->assertSame(100, $snap->totals('20260101')['surface.customer']['tokens']);
    }

    public function test_an_id_reused_for_another_surface_is_refused(): void
    {
        $a = $this->anchor();
        $a->initialise('a', [], 'engaged', 'x');
        $a->reserve('r1', $this->record('vendor'));

        $this->expectException(AnchorException::class);
        $a->reserve('r1', $this->record('customer'));
    }

    public function test_a_close_happens_at_most_once_and_the_first_outcome_stands(): void
    {
        $a = $this->anchor();
        $a->initialise('a', [], 'engaged', 'x');
        $a->reserve('r1', $this->record());

        $a->close('r1', 'settled', 30, 12);
        $a->close('r1', 'released', 0, 0);
        $a->close('r1', 'settled', 999, 999);

        $r = $a->read()->reservation('r1');
        $this->assertSame('settled', $r['state']);
        $this->assertSame(30, $r['tokens']);
        $this->assertSame(12, $r['cost']);
        $this->assertSame(1, $r['requests'], 'a released attempt would still count as a request');
    }

    public function test_closing_a_reservation_the_anchor_never_accepted_is_refused_not_invented(): void
    {
        $a = $this->anchor();
        $a->initialise('a', [], 'engaged', 'x');

        $this->expectException(AnchorException::class);
        $a->close('ghost', 'settled', 1, 1);
    }

    public function test_a_retry_closes_the_previous_attempt_in_the_same_write(): void
    {
        $a = $this->anchor();
        $a->initialise('a', [], 'engaged', 'x');
        $a->reserve('first', $this->record());

        $a->reserve('second', ['retry_of' => 'first'] + $this->record(), ['id' => 'first', 'state' => 'released', 'tokens' => 0, 'cost' => 0]);

        $snap = $a->read();
        $this->assertSame('released', $snap->reservation('first')['state']);
        $this->assertSame('open', $snap->reservation('second')['state']);
        $this->assertSame(2, $snap->totals('20260101')['global']['requests']);
        $this->assertSame(100, $snap->totals('20260101')['global']['tokens'], 'only the new attempt holds tokens');
    }

    public function test_the_kill_switch_generation_and_the_reconciliation_epoch_only_increase(): void
    {
        $a = $this->anchor();
        $a->initialise('a', [], 'engaged', 'x');

        $this->assertSame(2, $a->setKillSwitch('released', 'go')['generation']);
        $this->assertSame(3, $a->setKillSwitch('engaged', 'stop')['generation']);

        $a->setReconciliation('required', 'why');
        $this->assertFalse($a->read()->reconciliationOk());
        $a->setReconciliation('ok', 'done');
        $this->assertTrue($a->read()->reconciliationOk());
        $this->assertSame(3, $a->read()->epoch);
    }

    public function test_an_existing_anchor_is_never_overwritten(): void
    {
        $a = $this->anchor();
        $a->initialise('original', [], 'engaged', 'x');
        $a->reserve('r1', $this->record());

        try {
            $a->initialise('replacement', [], 'released', 'x');
            $this->fail('must refuse');
        } catch (AnchorException $e) {
            $this->assertSame(AnchorException::CONFLICT, $e->kind);
        }

        $this->assertSame('original', $a->read()->anchorId);
        $this->assertSame(1, $a->read()->seq);
    }

    public function test_every_kind_of_tampering_is_reported_as_corrupt_never_as_empty(): void
    {
        $a = $this->anchor();
        $a->initialise('a', [], 'engaged', 'x');
        $a->reserve('r1', $this->record());
        $file = $this->dir.DIRECTORY_SEPARATOR.FileIntegrityAnchor::FILE;
        $good = (string) file_get_contents($file);

        $variants = [
            'one digit changed' => str_replace('"tokens":100', '"tokens":10', $good),
            'seq lowered' => str_replace('"seq":1,"epoch"', '"seq":0,"epoch"', $good),
            'kill state flipped' => str_replace('"state":"engaged"', '"state":"released"', $good),
            'mac removed' => (string) preg_replace('/,"mac":"[0-9a-f]+"/', '', $good),
            'mac zeroed' => (string) preg_replace('/"mac":"[0-9a-f]+"/', '"mac":"'.str_repeat('0', 64).'"', $good),
            'empty' => '',
            'not json' => 'hello',
            'json but not an object' => '[1,2,3]',
        ];

        foreach ($variants as $label => $content) {
            $this->assertNotSame($good, $content, "{$label}: the variant must differ");
            file_put_contents($file, $content);

            try {
                $this->anchor()->read();
                $this->fail("{$label}: must not authenticate");
            } catch (AnchorException $e) {
                $this->assertSame(AnchorException::CORRUPT, $e->kind, $label);
            }
        }

        file_put_contents($file, $good);
        $this->assertSame(1, $this->anchor()->read()->seq);

        try {
            $this->anchor('another-key-0123456789abcdef0123456789abc')->read();
            $this->fail('a different key must not authenticate');
        } catch (AnchorException $e) {
            $this->assertSame(AnchorException::CORRUPT, $e->kind);
        }
    }

    public function test_a_missing_document_is_missing_not_empty(): void
    {
        try {
            $this->anchor()->read();
            $this->fail('nothing to read');
        } catch (AnchorException $e) {
            $this->assertSame(AnchorException::MISSING, $e->kind);
        }
    }

    public function test_configuration_rules(): void
    {
        $this->assertSame([], $this->anchor()->configurationProblems());

        $this->assertNotSame([], (new FileIntegrityAnchor($this->dir, 'short'))->configurationProblems(), 'a short key');
        $this->assertNotSame([], (new FileIntegrityAnchor('relative/path', self::KEY))->configurationProblems(), 'a relative path');
        $this->assertNotSame([], (new FileIntegrityAnchor($this->dir.DIRECTORY_SEPARATOR.'nope', self::KEY))->configurationProblems(), 'a missing directory (never created automatically)');
        $this->assertNotSame([], (new FileIntegrityAnchor('', self::KEY))->configurationProblems(), 'no path');

        foreach ([base_path(), storage_path('app'), database_path()] as $inside) {
            $this->assertNotSame([], (new FileIntegrityAnchor($inside, self::KEY))->configurationProblems(), 'inside the application: '.$inside);
        }
    }

    public function test_an_unusable_configuration_refuses_to_operate(): void
    {
        $this->expectException(AnchorException::class);
        (new FileIntegrityAnchor('relative', self::KEY))->read();
    }

    public function test_the_default_anchor_fails_every_operation(): void
    {
        $none = new UnconfiguredIntegrityAnchor;

        $this->assertNotSame([], $none->configurationProblems());
        $this->assertSame(AnchorException::NOT_CONFIGURED, $this->kindOf(fn () => $none->read()));
        $this->assertSame(AnchorException::NOT_CONFIGURED, $this->kindOf(fn () => $none->reserve('x', $this->record())));
        $this->assertSame(AnchorException::NOT_CONFIGURED, $this->kindOf(fn () => $none->setKillSwitch('released', 'x')));
        $this->assertFalse((new IntegrityGuard)->configured(), 'and by default no anchor is configured');
        $this->assertSame('none', (new IntegrityGuard)->driver());
    }

    public function test_no_secret_is_ever_written_to_the_anchor_or_its_trail(): void
    {
        $a = $this->anchor();
        $a->initialise('a', [], 'engaged', 'x');
        $a->reserve('r1', $this->record());
        $a->close('r1', 'settled', 1, 1);

        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') as $file) {
            $this->assertStringNotContainsString(self::KEY, (string) file_get_contents($file), basename($file));
        }

        $this->assertMatchesRegularExpression('/^[0-9T:+\-]+ (initialised|reserve|close) seq=\d+$/m', (string) file_get_contents($this->dir.DIRECTORY_SEPARATOR.FileIntegrityAnchor::AUDIT));
    }

    public function test_separate_php_processes_get_unique_gap_free_sequence_numbers_and_lose_no_update(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available');
        }

        $this->anchor()->initialise('a', [], 'engaged', 'x');

        $go = $this->dir.DIRECTORY_SEPARATOR.'go.flag';
        $procs = [];
        $workers = 5;
        $each = 12;

        for ($w = 0; $w < $workers; $w++) {
            $ready = $this->dir.DIRECTORY_SEPARATOR."ready-{$w}.flag";
            $cmd = [PHP_BINARY, __DIR__.'/anchor_worker.php', $this->dir, self::KEY, (string) $each, "w{$w}", $go, $ready];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $procs[] = [$p, $pipes, $ready];
        }

        $deadline = microtime(true) + 60;
        while (count(array_filter($procs, fn ($p) => is_file($p[2]))) < $workers && microtime(true) < $deadline) {
            usleep(20000);
        }
        file_put_contents($go, '1');

        $all = [];
        foreach ($procs as [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($p);
            $decoded = json_decode(trim($out), true);
            $this->assertIsArray($decoded, 'worker output: '.$out.' '.$err);
            $all = array_merge($all, $decoded['seqs']);
        }

        sort($all);
        $this->assertSame(range(1, $workers * $each), $all, 'every reservation got its own sequence number, with no gap');

        $snap = $this->anchor()->read();
        $this->assertSame($workers * $each, $snap->seq);
        $this->assertSame($workers * $each, count($snap->reservations), 'no concurrent write overwrote another');
        $this->assertSame($workers * $each * 2, $snap->totals('20260101')['global']['cost_micro_usd'], 'each was closed exactly once at cost 2');
    }

    private function kindOf(\Closure $fn): string
    {
        try {
            $fn();
        } catch (AnchorException $e) {
            return $e->kind;
        }

        return 'no exception';
    }
}
