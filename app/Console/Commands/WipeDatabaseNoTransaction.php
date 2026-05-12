<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WipeDatabaseNoTransaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:wipe-no-transaction {--drop-views} {--drop-types}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Wipe the database without a transaction.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ($this->input->hasOption('database') && $this->input->getOption('database')) {
            $database = $this->input->getOption('database');
        } else {
            $database = DB::getDatabaseName();
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::connection()->getSchemaBuilder()->dropAllTables();
            DB::connection()->getSchemaBuilder()->dropAllViews();
        } else {
            DB::connection()->getSchemaBuilder()->dropAllTables();
            DB::connection()->getSchemaBuilder()->dropAllViews();
        }

        return 0;
    }
}
