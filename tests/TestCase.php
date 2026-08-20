<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['db']->connection();

        if (filled($connection->getConfig('url')) || filled(getenv('DATABASE_URL'))) {
            throw new RuntimeException('Database URLs are prohibited during automated tests.');
        }

        $allowedHosts = ['127.0.0.1', 'localhost', '::1'];
        $host = (string) $connection->getConfig('host');

        if ($connection->getDriverName() !== 'mysql' || ! in_array($host, $allowedHosts, true)) {
            throw new RuntimeException('Automated tests require MySQL on an explicitly permitted local host.');
        }

        if ($connection->getDatabaseName() !== 'inventra_test') {
            throw new RuntimeException('Automated tests may only use the inventra_test database.');
        }

        $activeDatabase = $connection->selectOne('select database() as database_name');

        if (($activeDatabase->database_name ?? null) !== 'inventra_test') {
            throw new RuntimeException('The resolved test connection is not using inventra_test.');
        }

        return $app;
    }
}
