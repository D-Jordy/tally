<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Force a dedicated test database, independent of env precedence.
     *
     * The container's env_file (.env) injects DB_DATABASE as a real OS env var,
     * which wins over phpunit.xml's <env> entries — so the only reliable place
     * to pin the test DB is here, in code, before RefreshDatabase migrates.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $app['config']->set('database.connections.pgsql.database', 'portfolio_testing');
        DB::purge('pgsql');

        return $app;
    }
}
