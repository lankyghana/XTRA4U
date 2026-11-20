<?php

namespace App\Console\Commands;

use App\Services\Ai\Safety\KillSwitch;
use Illuminate\Console\Command;

class AiKillSwitch extends Command
{
    protected $signature = 'ai:kill-switch {action : engage | release | status}';

    protected $description = 'Instantly switch the AI engine off (engage) or remove the runtime switch (release). Does not change config.';

    public function handle(KillSwitch $switch): int
    {
        switch ($this->argument('action')) {
            case 'engage':
                $switch->engage();
                $this->warn('AI kill switch ENGAGED: every AI request is refused.');
                break;

            case 'release':
                $switch->release();
                $this->info('AI kill switch released. The engine still stays off unless config ai.enabled (AI_ENABLED) is true.');
                break;

            case 'status':
                break;

            default:
                $this->error('Action must be one of: engage, release, status.');

                return self::INVALID;
        }

        $this->line('config ai.enabled: '.(config('ai.enabled') === true ? 'true' : 'false'));
        $this->line('runtime kill switch: '.($switch->engaged() ? 'ENGAGED' : 'not engaged'));
        $this->line('engine accepts requests: '.($switch->engineEnabled() ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
