<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'svil_app_testing') {
            throw new \RuntimeException('Tests must use the dedicated svil_app_testing MySQL database.');
        }

        return $app;
    }
}
