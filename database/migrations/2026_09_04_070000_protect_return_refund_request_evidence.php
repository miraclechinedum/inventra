<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->foreignId('sale_return_request_id')->nullable()->unique()->after('id')->constrained('sale_return_requests')->restrictOnDelete();
        });
        Schema::table('sale_refunds', function (Blueprint $table): void {
            $table->foreignId('sale_refund_request_id')->nullable()->unique()->after('id')->constrained('sale_refund_requests')->restrictOnDelete();
        });
        DB::table('sale_return_requests')->whereNotNull('sale_return_id')->orderBy('id')->each(fn (object $row) => DB::table('sale_returns')->where('id', $row->sale_return_id)->update(['sale_return_request_id' => $row->id]));
        DB::table('sale_refund_requests')->whereNotNull('sale_refund_id')->orderBy('id')->each(fn (object $row) => DB::table('sale_refunds')->where('id', $row->sale_refund_id)->update(['sale_refund_request_id' => $row->id]));
    }

    public function down(): void
    {
        if (DB::table('sale_returns')->exists() || DB::table('sale_refunds')->exists()) {
            throw new RuntimeException('Cannot remove Return and Refund evidence protection while history exists.');
        }
        Schema::table('sale_refunds', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_refund_request_id'));
        Schema::table('sale_returns', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_return_request_id'));
    }
};
