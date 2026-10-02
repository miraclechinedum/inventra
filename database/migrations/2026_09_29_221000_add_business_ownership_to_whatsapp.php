<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand and backfill: every WhatsApp record gains the Business it belongs to.
 *
 *  - The connection and the automations are installation configuration with no owner of their own
 *    to derive from. They are attributed only while exactly one Business exists — the installation
 *    they were configured for — and otherwise the migration stops.
 *  - Deliveries to the automation's recipients take the automation's Business; low-stock episodes
 *    their product's; historical receipt deliveries their sale's.
 *  - A message takes the Business of whatever it names: its customer, staff recipient, subject,
 *    automation, connection, retried original or retrying operator. Every source present must
 *    agree. A message pointing two ways is refused, not filed under one.
 *
 * Every referenced account must belong to the same Business as the row. Nothing is ever assigned to
 * a default, and a row nothing can attribute stops the migration with its type named.
 */
return new class extends Migration
{
    private const TABLES = [
        'whatsapp_connection', 'whatsapp_automations', 'whatsapp_automation_recipients',
        'whatsapp_messages', 'whatsapp_low_stock_episodes', 'whatsapp_deliveries',
    ];

    /** @var array<string, string> message column => owning table, in the order they are trusted */
    private const MESSAGE_OWNERS = [
        'customer_id' => 'customers',
        'user_id' => 'users',
        'whatsapp_automation_id' => 'whatsapp_automations',
        'whatsapp_connection_id' => 'whatsapp_connection',
        'created_by' => 'users',
    ];

    /** @var array<string, string> stored subject_type => owning table; both the class and its alias occur */
    private const SUBJECTS = [
        'App\\Models\\Customer' => 'customers', 'customer' => 'customers',
        'App\\Models\\Sale' => 'sales', 'sale' => 'sales',
        'App\\Models\\Product' => 'products', 'product' => 'products',
    ];

    public function up(): void
    {
        if (Schema::hasColumn('whatsapp_connection', 'business_id') && $this->columnType('whatsapp_connection', 'business_id') !== 'bigint') {
            throw new RuntimeException('whatsapp_connection.business_id still holds the Meta identifier; rename it to meta_business_id first.');
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->restrictOnDelete());
            }
        }

        foreach (['whatsapp_connection', 'whatsapp_automations'] as $table) {
            $this->attributeInstallationConfiguration($table);
        }

        $this->refuseForeignAccount('whatsapp_connection', 'connected_by');
        $this->refuseForeignAccount('whatsapp_automations', 'updated_by');

        DB::statement('UPDATE whatsapp_automation_recipients r JOIN whatsapp_automations a ON a.id = r.whatsapp_automation_id
            SET r.business_id = a.business_id WHERE r.business_id IS NULL');
        $this->refuseForeignAccount('whatsapp_automation_recipients', 'user_id');

        DB::statement('UPDATE whatsapp_low_stock_episodes e JOIN products p ON p.id = e.product_id
            SET e.business_id = p.business_id WHERE e.business_id IS NULL');
        $this->refuseDisagreement('whatsapp_low_stock_episodes', 'product_id', 'products', 'A low-stock episode belongs to a different business from its product.');

        DB::statement('UPDATE whatsapp_deliveries d JOIN sales s ON s.id = d.sale_id
            SET d.business_id = s.business_id WHERE d.business_id IS NULL');
        $this->refuseDisagreement('whatsapp_deliveries', 'customer_id', 'customers', 'A WhatsApp delivery was addressed to a customer of another business.');
        $this->refuseForeignAccount('whatsapp_deliveries', 'created_by');
        $this->refuseForeignAccount('whatsapp_deliveries', 'resolved_by');

        $this->attributeMessages();

        foreach (self::TABLES as $table) {
            $unowned = DB::table($table)->whereNull('business_id')->count();

            if ($unowned > 0) {
                throw new RuntimeException("{$unowned} {$table} rows have no record that can establish their business.");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('businesses')->count() > 1) {
            throw new RuntimeException('Refusing to drop WhatsApp ownership while more than one business exists.');
        }

        foreach (array_reverse(self::TABLES) as $table) {
            if (Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropConstrainedForeignId('business_id'));
            }
        }
    }

    /** Configuration with no owner of its own: attributable only to the one Business it served. */
    private function attributeInstallationConfiguration(string $table): void
    {
        $unowned = DB::table($table)->whereNull('business_id')->count();

        if ($unowned === 0) {
            return;
        }

        $businesses = DB::table('businesses')->limit(2)->pluck('id');

        if ($businesses->count() !== 1) {
            throw new RuntimeException("{$unowned} {$table} rows predate tenancy and {$businesses->count()} businesses exist; their owner cannot be determined.");
        }

        DB::table($table)->whereNull('business_id')->update(['business_id' => $businesses->first()]);
    }

    private function attributeMessages(): void
    {
        foreach (self::MESSAGE_OWNERS as $column => $table) {
            if ($column === 'whatsapp_automation_id') {
                // The subject is more specific than the automation, so it is trusted first.
                $this->attributeMessagesFromSubjects();
            }

            DB::statement("UPDATE whatsapp_messages m JOIN {$table} o ON o.id = m.{$column}
                SET m.business_id = o.business_id WHERE m.business_id IS NULL");
        }

        // A retry inherits its original. Chains are short; the bound only guarantees termination.
        for ($depth = 0; $depth < 20; $depth++) {
            $filled = DB::update('UPDATE whatsapp_messages m JOIN whatsapp_messages o ON o.id = m.retry_of_id
                SET m.business_id = o.business_id WHERE m.business_id IS NULL AND o.business_id IS NOT NULL');

            if ($filled === 0) {
                break;
            }
        }

        foreach (self::MESSAGE_OWNERS as $column => $table) {
            $this->refuseDisagreement('whatsapp_messages', $column, $table, "A WhatsApp message names a {$column} of another business.");
        }

        foreach (self::SUBJECTS as $type => $table) {
            $mismatched = DB::table('whatsapp_messages as m')->join("{$table} as s", 's.id', '=', 'm.subject_id')
                ->where('m.subject_type', $type)->whereColumn('s.business_id', '<>', 'm.business_id')->count();

            if ($mismatched > 0) {
                throw new RuntimeException("{$mismatched} WhatsApp messages are about a {$type} of another business.");
            }
        }

        $this->refuseDisagreement('whatsapp_messages', 'retry_of_id', 'whatsapp_messages', 'A WhatsApp retry belongs to a different business from its original.');

        $unowned = DB::table('whatsapp_messages')->whereNull('business_id')
            ->selectRaw('type, COUNT(*) AS total')->groupBy('type')->pluck('total', 'type');

        if ($unowned->isNotEmpty()) {
            throw new RuntimeException('WhatsApp messages with nothing to establish their business: '
                .$unowned->map(fn ($total, $type): string => "{$type} ({$total})")->implode(', ').'.');
        }
    }

    private function attributeMessagesFromSubjects(): void
    {
        foreach (self::SUBJECTS as $type => $table) {
            DB::statement(
                "UPDATE whatsapp_messages m JOIN {$table} s ON s.id = m.subject_id
                 SET m.business_id = s.business_id WHERE m.business_id IS NULL AND m.subject_type = ?",
                [$type]
            );
        }
    }

    private function refuseForeignAccount(string $table, string $column): void
    {
        $this->refuseDisagreement($table, $column, 'users', "{$table}.{$column} names a user of another business.");
    }

    private function refuseDisagreement(string $table, string $column, string $owner, string $reason): void
    {
        $mismatched = DB::table("{$table} as t")->join("{$owner} as o", 'o.id', '=', "t.{$column}")
            ->whereNotNull('t.business_id')->whereColumn('o.business_id', '<>', 't.business_id')->count();

        if ($mismatched > 0) {
            throw new RuntimeException("{$mismatched} rows: {$reason}");
        }
    }

    private function columnType(string $table, string $column): ?string
    {
        return DB::selectOne(
            'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )?->type;
    }
};
