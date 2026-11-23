<?php

namespace App\Console\Commands;

use App\Services\Ai\Preflight\AiPreflight as Preflight;
use Illuminate\Console\Command;

/**
 * Read-only AI production preflight. Makes no network request, sends no prompt, creates no order and changes no
 * configuration, flag, table or cache key: it only reports. A passing run does NOT enable AI and is NOT a statement
 * that the system is "production ready" - activation is a separate, explicitly approved step.
 */
class AiPreflight extends Command
{
    protected $signature = 'ai:preflight {--live : Strict mode: exit non-zero unless every automatic live-provider gate passes} {--json : Machine-readable output}';

    protected $description = 'Read-only report on whether the AI engine is safely configured (add --live to test live-provider readiness). Never calls a provider.';

    private const LABELS = [
        Preflight::PASS => 'PASS  ',
        Preflight::WARN => 'WARN  ',
        Preflight::FAIL => 'FAIL  ',
        Preflight::MANUAL => 'MANUAL',
        Preflight::INFO => 'INFO  ',
    ];

    public function handle(Preflight $preflight): int
    {
        $report = $preflight->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($report + ['mode' => $this->option('live') ? 'live' : 'standard'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        if ($this->option('live')) {
            return $report['live_ready'] ? self::SUCCESS : self::FAILURE;
        }

        // Standard mode reports state and always exits 0 unless the engine is selected for live use but blocked while it
        // is actually switched on (a configuration that would refuse every request).
        return $report['state'] === 'misconfigured' ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string,mixed> $report */
    private function render(array $report): void
    {
        $this->line('AI preflight (read-only: no network request, no prompt, no change)');
        $this->newLine();
        $this->line('State  : '.$report['state']);
        $this->line('Verdict: '.$report['verdict']);
        $this->newLine();

        foreach ($report['checks'] as $check) {
            $line = sprintf('[%s] %s - %s', self::LABELS[$check['status']], $check['title'], $check['detail']);

            match ($check['status']) {
                Preflight::FAIL => $this->line("<fg=red>{$line}</>"),
                Preflight::WARN, Preflight::MANUAL => $this->line("<fg=yellow>{$line}</>"),
                Preflight::PASS => $this->line("<fg=green>{$line}</>"),
                default => $this->line($line),
            };
        }

        $this->newLine();
        $this->line(sprintf('Summary: %d failing check(s) (these are LIVE-provider gates: they are not unsafe while AI stays disabled or on the mock), %d warning(s), %d manual item(s). Live-provider readiness (automatic gates): %s.', $report['failures'], $report['warnings'], $report['manual'], $report['live_ready'] ? 'all pass' : 'BLOCKED'));
        $this->line('A pass never enables AI. Activation needs the separate approvals listed in docs/ai/06-production-hardening-and-launch-readiness.md.');
    }
}
