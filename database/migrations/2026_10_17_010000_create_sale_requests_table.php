<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use Record Sale submissions.
 *
 * The same contract as every other request token: only the SHA-256 of the token is stored, the row
 * is bound to its Business, its operator and their session, and it expires. A used row names the
 * Sale it produced, and that Sale names the row back, so neither piece of evidence can be removed
 * without the other. Ownership is enforced by composite keys onto (business_id, id), as for the
 * return, refund and payment tokens.
 *
 * Existing Sales predate submissions and keep a null `sale_request_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->char('payload_hash', 64)->nullable();
            $table->unsignedBigInteger('actor_id');
            $table->string('session_id');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('sale_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['business_id', 'id'], 'sale_requests_business_id_id_unique');
            $table->foreign(['business_id', 'actor_id'], 'sale_requests_actor_tenant_fk')
                ->references(['business_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['business_id', 'sale_id'], 'sale_requests_sale_tenant_fk')
                ->references(['business_id', 'id'])->on('sales')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE sale_requests ADD CONSTRAINT sale_requests_use_complete CHECK ((used_at IS NULL AND sale_id IS NULL AND payload_hash IS NULL) OR (used_at IS NOT NULL AND sale_id IS NOT NULL AND payload_hash IS NOT NULL))');

        Schema::table('sales', function (Blueprint $table): void {
            $table->unsignedBigInteger('sale_request_id')->nullable()->unique()->after('id');
            $table->foreign(['business_id', 'sale_request_id'], 'sales_sale_request_tenant_fk')
                ->references(['business_id', 'id'])->on('sale_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('sales')->whereNotNull('sale_request_id')->exists()) {
            throw new RuntimeException('Cannot remove Record Sale submission evidence while Sales reference it.');
        }

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign('sales_sale_request_tenant_fk');
            $table->dropUnique(['sale_request_id']);
            $table->dropColumn('sale_request_id');
        });

        Schema::dropIfExists('sale_requests');
    }
};
