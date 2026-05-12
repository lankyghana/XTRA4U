<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MigrateFreshNoTransaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:fresh-no-transaction {--seed} {--drop-views} {--drop-types}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Drop all tables and re-run all migrations without a transaction.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->call('db:wipe-no-transaction', [
            '--drop-views' => $this->input->hasOption('drop-views') ? $this->input->getOption('drop-views') : false,
            '--drop-types' => $this->input->hasOption('drop-types') ? $this->input->getOption('drop-types') : false,
        ]);

        $this->call('migrate', [
            '--seed' => $this->input->hasOption('seed') ? $this->input->getOption('seed') : false,
        ]);

        return 0;
    }
}
