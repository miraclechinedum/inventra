<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant. A Business is the ownership boundary every tenant record will eventually hang from;
 * its profile (letterhead, contact details, logo, alert number) stays in `business_settings`.
 *
 * Deliberately minimal. There is no slug because no route, host or subdomain resolves a tenant by
 * name, and no public identifier because no route exposes a Business yet — platform administration
 * will add one when it needs a URL. `status` is a short string with a CHECK rather than an ENUM so
 * a future state is a constraint change, not a column rebuild.
 *
 * An existing installation already has exactly one business, described by its settings row. That
 * row becomes the first Business, named from its own recorded business name — nothing is invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index('status');
        });

        DB::statement("ALTER TABLE businesses ADD CONSTRAINT businesses_status_valid CHECK (status IN ('active', 'suspended'))");
        DB::statement('ALTER TABLE businesses ADD CONSTRAINT businesses_name_not_blank CHECK (CHAR_LENGTH(TRIM(name)) > 0)');

        $settings = DB::table('business_settings')->orderBy('id')->get(['business_name']);

        if ($settings->count() !== 1) {
            throw new RuntimeException(
                'Expected exactly one business_settings row to become the first Business; found '.$settings->count().'.'
            );
        }

        DB::table('businesses')->insert([
            'name' => $settings->first()->business_name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Rolling back to a single implicit business is only truthful while there is one.
        if (Schema::hasTable('businesses') && DB::table('businesses')->count() > 1) {
            throw new RuntimeException('Refusing to drop businesses while more than one business exists.');
        }

        Schema::dropIfExists('businesses');
    }
};
