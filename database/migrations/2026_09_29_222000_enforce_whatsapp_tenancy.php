<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract: WhatsApp state belongs to one Business, enforced by the database.
 *
 *  - One connection per Business: UNIQUE(business_id). The provider identity stays global — a WABA
 *    (`waba_id`, already UNIQUE) and now a sending number (`phone_number_id`) can be held by only
 *    one Business, so no tenant can attach another's account.
 *  - Automations are per Business: UNIQUE(business_id, key) replaces the global key, so every
 *    Business has its own welcome, post-purchase, pickup and low-stock automation.
 *  - Recipients, messages, low-stock episodes and historical deliveries reference their automation,
 *    connection, customer, staff, product and sale through composite keys onto (business_id, id),
 *    so a row can never point into another Business. Existing delete behaviour is kept.
 *
 * Not expressible as keys: a message's polymorphic subject, and the SET NULL references (retried
 * original, retrying operator, connecting and updating operator). Those are verified here and
 * enforced by the application for new rows.
 */
return new class extends Migration
{
    private const TABLES = [
        'whatsapp_connection', 'whatsapp_automations', 'whatsapp_automation_recipients',
        'whatsapp_messages', 'whatsapp_low_stock_episodes', 'whatsapp_deliveries',
    ];

    /** @var array<string, list<array{0: string, 1: list<string>, 2: string, 3: string}>> table => [name, columns, parent, on delete] */
    private const TENANT_KEYS = [
        'whatsapp_automation_recipients' => [
            ['wa_recipients_automation_tenant_fk', ['business_id', 'whatsapp_automation_id'], 'whatsapp_automations', 'cascade'],
            ['wa_recipients_user_tenant_fk', ['business_id', 'user_id'], 'users', 'cascade'],
        ],
        'whatsapp_messages' => [
            ['wa_messages_automation_tenant_fk', ['business_id', 'whatsapp_automation_id'], 'whatsapp_automations', 'restrict'],
            ['wa_messages_connection_tenant_fk', ['business_id', 'whatsapp_connection_id'], 'whatsapp_connection', 'restrict'],
            ['wa_messages_customer_tenant_fk', ['business_id', 'customer_id'], 'customers', 'restrict'],
            ['wa_messages_user_tenant_fk', ['business_id', 'user_id'], 'users', 'restrict'],
        ],
        'whatsapp_low_stock_episodes' => [
            ['wa_episodes_product_tenant_fk', ['business_id', 'product_id'], 'products', 'cascade'],
        ],
        'whatsapp_deliveries' => [
            ['wa_deliveries_sale_tenant_fk', ['business_id', 'sale_id'], 'sales', 'restrict'],
            ['wa_deliveries_customer_tenant_fk', ['business_id', 'customer_id'], 'customers', 'restrict'],
            ['wa_deliveries_creator_tenant_fk', ['business_id', 'created_by'], 'users', 'restrict'],
            ['wa_deliveries_resolver_tenant_fk', ['business_id', 'resolved_by'], 'users', 'restrict'],
        ],
    ];

    /** @var array<string, string> */
    private const SUBJECTS = [
        'App\\Models\\Customer' => 'customers', 'customer' => 'customers',
        'App\\Models\\Sale' => 'sales', 'sale' => 'sales',
        'App\\Models\\Product' => 'products', 'product' => 'products',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->refuseWhen(DB::table($table)->whereNull('business_id')->exists(), "{$table} has rows without a business.");
        }

        $this->refuseWhen(DB::table('whatsapp_connection')->select('business_id')->groupBy('business_id')->havingRaw('COUNT(*) > 1')->exists(),
            'A business has more than one WhatsApp connection; one must be retired before tenancy can be enforced.');
        $this->refuseWhen(DB::table('whatsapp_connection')->whereNotNull('phone_number_id')->select('phone_number_id')->groupBy('phone_number_id')->havingRaw('COUNT(*) > 1')->exists(),
            'A WhatsApp sending number is recorded on more than one connection.');

        foreach (self::SUBJECTS as $type => $table) {
            $this->refuseWhen(DB::table('whatsapp_messages as m')->join("{$table} as s", 's.id', '=', 'm.subject_id')
                ->where('m.subject_type', $type)->whereColumn('s.business_id', '<>', 'm.business_id')->exists(),
                "A WhatsApp message is about a {$type} of another business.");
        }

        foreach ([
            ['whatsapp_messages', 'retry_of_id', 'whatsapp_messages'], ['whatsapp_messages', 'created_by', 'users'],
            ['whatsapp_connection', 'connected_by', 'users'], ['whatsapp_automations', 'updated_by', 'users'],
        ] as [$table, $column, $owner]) {
            $this->refuseWhen(DB::table("{$table} as t")->join("{$owner} as o", 'o.id', '=', "t.{$column}")
                ->whereColumn('o.business_id', '<>', 't.business_id')->exists(), "{$table}.{$column} names a record of another business.");
        }

        foreach (self::TABLES as $table) {
            // MySQL can refuse to tighten a column its own foreign key uses (error 1832).
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(['business_id']));
            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NOT NULL");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete());
        }

        Schema::table('whatsapp_connection', function (Blueprint $table): void {
            $table->unique('business_id', 'whatsapp_connection_business_id_unique');
            $table->unique(['business_id', 'id'], 'whatsapp_connection_business_id_id_unique');
            // Alongside, not instead of, the existing lookup index: the Meta identity migration
            // is re-runnable and expects that index to be present.
            $table->unique('phone_number_id', 'whatsapp_connection_phone_number_id_unique');
        });

        Schema::table('whatsapp_automations', function (Blueprint $table): void {
            $table->unique(['business_id', 'key'], 'whatsapp_automations_business_id_key_unique');
            $table->unique(['business_id', 'id'], 'whatsapp_automations_business_id_id_unique');
            $table->dropUnique(['key']);
        });

        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->index(['business_id', 'created_at', 'id'], 'whatsapp_messages_business_listing_index');
            $table->index(['business_id', 'status'], 'whatsapp_messages_business_status_index');
        });

        foreach (self::TENANT_KEYS as $table => $keys) {
            Schema::table($table, function (Blueprint $blueprint) use ($keys): void {
                foreach ($keys as [$name, $columns, $parent, $onDelete]) {
                    $blueprint->foreign($columns, $name)->references(['business_id', 'id'])->on($parent)->onDelete($onDelete);
                }
            });
        }
    }

    public function down(): void
    {
        $this->refuseWhen(DB::table('whatsapp_automations')->select('key')->groupBy('key')->havingRaw('COUNT(*) > 1')->exists(),
            'More than one business has its own WhatsApp automations; global automation keys cannot be restored.');

        foreach (self::TENANT_KEYS as $table => $keys) {
            foreach ($keys as [$name]) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
            }
        }

        // The business keys may have adopted a composite key's index when they were re-added, so
        // they go before those indexes do (MySQL error 1553 otherwise), and come back last.
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(['business_id']));
        }

        foreach (self::TENANT_KEYS as $table => $keys) {
            foreach ($keys as [$name]) {
                // MySQL keeps the index it created for each dropped key; left behind, it would
                // collide with the key's name on a re-apply.
                if ($this->indexExists($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }
        }

        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->dropIndex('whatsapp_messages_business_listing_index');
            $table->dropIndex('whatsapp_messages_business_status_index');
        });

        Schema::table('whatsapp_automations', function (Blueprint $table): void {
            $table->unique('key');
            $table->dropUnique('whatsapp_automations_business_id_key_unique');
            $table->dropUnique('whatsapp_automations_business_id_id_unique');
        });

        Schema::table('whatsapp_connection', function (Blueprint $table): void {
            $table->dropUnique('whatsapp_connection_phone_number_id_unique');
            $table->dropUnique('whatsapp_connection_business_id_id_unique');
        });

        foreach (self::TABLES as $table) {
            if ($table === 'whatsapp_connection') {
                // The per-business unique served the key; it can go only once the key has.
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique('whatsapp_connection_business_id_unique'));
            }

            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NULL");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete());
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }

    private function refuseWhen(bool $condition, string $reason): void
    {
        if ($condition) {
            throw new RuntimeException($reason);
        }
    }
};
