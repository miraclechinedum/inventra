<?php

namespace Tests;

use App\Models\Business;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** The only database the suite may ever write to, migrate or rebuild. */
    public const TEST_DATABASE = 'inventra_test';

    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /**
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        static::assertSafeTestDatabase($app['db']->connection());

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Tests call models and actions directly, outside any request, so they get the context a
        // request's middleware would establish: the installation's Business, named explicitly. A test
        // that works for another tenant enters it through a request or CurrentBusiness::run().
        if (Schema::hasTable('businesses') && ($business = Business::query()->orderBy('id')->first()) !== null) {
            app(CurrentBusiness::class)->set($business);
        }
    }

    /**
     * Refuses to continue unless the connection is the local MySQL test database, confirmed both by
     * configuration and by asking the server itself. Configuration alone is not trusted: the live
     * `SELECT DATABASE()` is what proves where a destructive statement would actually land.
     */
    public static function assertSafeTestDatabase(Connection $connection): void
    {
        if (filled($connection->getConfig('url')) || filled(getenv('DATABASE_URL')) || filled(getenv('DB_URL'))) {
            throw new RuntimeException('Database URLs are prohibited during automated tests.');
        }

        if (config('database.default') !== 'mysql' || $connection->getName() !== 'mysql' || $connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Automated tests require the mysql connection and driver.');
        }

        if (! in_array((string) $connection->getConfig('host'), self::LOCAL_HOSTS, true)) {
            throw new RuntimeException('Automated tests require a local database host.');
        }

        if (config('database.connections.mysql.database') !== self::TEST_DATABASE
            || $connection->getDatabaseName() !== self::TEST_DATABASE) {
            throw new RuntimeException('Automated tests may only be configured for the '.self::TEST_DATABASE.' database.');
        }

        $live = $connection->selectOne('SELECT DATABASE() AS database_name');

        if (($live->database_name ?? null) !== self::TEST_DATABASE) {
            throw new RuntimeException('The live test connection is not using '.self::TEST_DATABASE.'.');
        }
    }

    /**
     * Rebuilds the schema after a probe that had to commit, such as a forked-process concurrency test.
     * The connection is purged first, so the guard verifies the connection migrate:fresh will use.
     */
    protected function rebuildTestSchema(): void
    {
        DB::purge();
        static::assertSafeTestDatabase(DB::connection());
        Artisan::call('migrate:fresh', ['--force' => true]);
        DB::connection()->beginTransaction();
    }
}
