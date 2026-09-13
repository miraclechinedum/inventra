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

        // Exactly two targets are permitted, each pinned to its own driver so a
        // MariaDB run can never fall back to the MySQL test database and a MySQL
        // run can never reach the disposable MariaDB compatibility database.
        // `inventra` (development) is reachable by neither.
        $allowedTargets = [
            'mysql' => 'inventra_test',
            'mariadb' => 'inventra_mariadb_compat',
        ];

        $driver = $connection->getDriverName();

        if (! array_key_exists($driver, $allowedTargets) || ! in_array($host, $allowedHosts, true)) {
            throw new RuntimeException('Automated tests require MySQL or MariaDB on an explicitly permitted local host.');
        }

        $expectedDatabase = $allowedTargets[$driver];

        if ($connection->getDatabaseName() !== $expectedDatabase) {
            throw new RuntimeException("Automated tests on {$driver} may only use the {$expectedDatabase} database.");
        }

        $activeDatabase = $connection->selectOne('select database() as database_name');

        if (($activeDatabase->database_name ?? null) !== $expectedDatabase) {
            throw new RuntimeException("The resolved test connection is not using {$expectedDatabase}.");
        }

        return $app;
    }
}
