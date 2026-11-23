<?php

namespace App\Console\Commands;

use App\Services\Ai\Exceptions\AiIntegrityException;
use App\Services\Ai\Safety\KillSwitch;
use Illuminate\Console\Command;

class AiKillSwitch extends Command
{
    protected $signature = 'ai:kill-switch {action : engage | release | status} {--reason= : Optional note stored with the change}';

    protected $description = 'Durable emergency stop: engage switches ALL AI off, release removes the stop. The state is stored in the database, so clearing the cache or restarting cannot undo it.';

    public function handle(KillSwitch $switch): int
    {
        $action = $this->argument('action');
        $reason = is_string($this->option('reason')) && $this->option('reason') !== '' ? $this->option('reason') : null;

        if (! in_array($action, ['engage', 'release', 'status'], true)) {
            $this->error('Action must be one of: engage, release, status.');

            return self::INVALID;
        }

        if ($action !== 'status') {
            try {
                $action === 'engage' ? $switch->engage($reason) : $switch->release($reason);
            } catch (AiIntegrityException $e) {
                // These messages are written by this code (never a database or file-system message), so they are safe to show.
                // An engage that reached only one store still leaves AI off; a release is refused or half-done only on the safe side.
                $this->error($e->getMessage());

                return self::FAILURE;
            } catch (\Throwable) {
                // Nothing about the cause is printed (a database message can contain SQL). A failed engage still leaves AI
                // stopped wherever the state cannot be read; a failed release leaves it as it was.
                $this->error("The kill switch could not be {$action}d: the database state could not be written. Nothing was changed.");

                return self::FAILURE;
            }

            $action === 'engage'
                ? $this->warn('AI kill switch ENGAGED: every AI request is refused. This survives cache clears and restarts.')
                : $this->info('AI kill switch released. The engine still stays off unless config ai.enabled (AI_ENABLED) is true.');
        }

        $status = $switch->status();

        $this->line('config ai.enabled: '.(config('ai.enabled') === true ? 'true' : 'false'));
        $this->line('runtime kill switch: '.($status['engaged'] ? 'ENGAGED' : 'not engaged').($status['readable'] ? '' : ' (state unreadable: failing closed)'));
        if ($status['anchor']['configured']) {
            $a = $status['anchor'];
            $this->line('independent anchor: '.($a['readable'] ? "{$a['state']} (generation {$a['generation']}; database generation ".($a['database_generation'] ?? 'missing').($a['agrees'] ? ', in agreement' : ', DISAGREES: AI stays off until a release').')' : 'UNREADABLE: failing closed'));
        }
        $this->line('engine accepts requests: '.($switch->engineEnabled() ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
