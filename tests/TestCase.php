<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([config('database.default'), config('sender.connection')] as $connection) {
            $database = DB::connection($connection)->getDatabaseName();

            if (! str_ends_with($database, '_test')) {
                throw new RuntimeException("Тесты запускаются только на тестовых базах (*_test), получена: {$database}");
            }
        }
    }

    protected function migrateSenderDatabase(): void
    {
        Artisan::call('migrate:fresh', [
            '--database' => config('sender.connection'),
            '--path' => 'app/Sender/Database/Migrations',
            '--force' => true,
        ]);
    }
}
