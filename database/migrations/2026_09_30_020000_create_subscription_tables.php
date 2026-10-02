<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The commercial domain, independent of any payment provider.
 *
 *  - `plans` is platform-level system data and deliberately has no business_id: every Business
 *    chooses from the same catalogue. Plans are identified by a stable `key`, never by name or id
 *    order. Amounts are integer minor units (kobo) — never floating point — and NULL means the plan
 *    has not been priced yet, which is honest until commercial prices are decided.
 *  - `business_subscriptions` is Business-owned: exactly one row per Business (UNIQUE business_id),
 *    holding the authoritative commercial state and dates. Business.status stays the platform's
 *    administrative switch and is not touched by anything commercial.
 *  - `users.email_verification_required` marks accounts that must prove their email before they
 *    operate a Business: public signup owners. Existing accounts are not marked, so nobody who could
 *    sign in before can be locked out by this change.
 *
 * Existing Businesses — whatever their number — each receive the `legacy` plan in the `active`
 * state with no period end: they keep exactly the access they had. The two system plans are written
 * here from literal definitions so the backfill and provisioning never depend on later
 * configuration; `inventra:sync-plans` maintains plans from config after that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->char('currency', 3)->default('NGN');
            $table->enum('billing_interval', ['month', 'year'])->default('month');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->json('entitlements');
            $table->timestamps();
        });

        Schema::create('business_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained('businesses')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->enum('status', ['trialing', 'active', 'grace', 'suspended']);
            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'id'], 'business_subscriptions_business_id_id_unique');
        });

        DB::statement("ALTER TABLE business_subscriptions ADD CONSTRAINT business_subscriptions_trial_is_dated CHECK (status <> 'trialing' OR (trial_starts_at IS NOT NULL AND trial_ends_at IS NOT NULL AND trial_ends_at >= trial_starts_at))");
        DB::statement("ALTER TABLE business_subscriptions ADD CONSTRAINT business_subscriptions_grace_is_dated CHECK (status <> 'grace' OR grace_ends_at IS NOT NULL)");

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('email_verification_required')->default(false)->after('email_verified_at');
        });

        $now = now();
        foreach ([
            ['key' => 'legacy', 'name' => 'Legacy', 'is_active' => false, 'trial_days' => 0],
            ['key' => 'standard', 'name' => 'Standard', 'is_active' => true, 'trial_days' => 14],
        ] as $plan) {
            DB::table('plans')->insert($plan + [
                'price_minor' => null, 'currency' => 'NGN', 'billing_interval' => 'month',
                'entitlements' => json_encode(['max_staff' => null, 'whatsapp_automation' => true]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $legacy = DB::table('plans')->where('key', 'legacy')->value('id');

        DB::statement(
            "INSERT INTO business_subscriptions (business_id, plan_id, status, created_at, updated_at)
             SELECT b.id, ?, 'active', ?, ? FROM businesses b
             WHERE NOT EXISTS (SELECT 1 FROM business_subscriptions s WHERE s.business_id = b.id)",
            [$legacy, $now, $now]
        );

        $uncovered = DB::table('businesses as b')->leftJoin('business_subscriptions as s', 's.business_id', '=', 'b.id')->whereNull('s.id')->count();

        if ($uncovered > 0) {
            throw new RuntimeException("{$uncovered} businesses were left without a subscription.");
        }
    }

    public function down(): void
    {
        // Rolling back would discard commercial state that cannot be reconstructed.
        if (DB::table('business_subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where(fn ($query) => $query->where('p.key', '<>', 'legacy')->orWhere('s.status', '<>', 'active'))->exists()) {
            throw new RuntimeException('Refusing to drop subscriptions while any Business has commercial state beyond the legacy grant.');
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_verification_required'));
        Schema::dropIfExists('business_subscriptions');
        Schema::dropIfExists('plans');
    }
};
