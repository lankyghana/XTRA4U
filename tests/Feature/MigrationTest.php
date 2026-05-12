<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_migrations_run_without_error()
    {
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }
}
