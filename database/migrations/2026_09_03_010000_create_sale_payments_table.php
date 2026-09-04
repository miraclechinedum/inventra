<?php

use App\Support\PaymentNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_number', 32)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->enum('payment_method', ['cash', 'transfer', 'pos'])->index();
            $table->enum('payment_type', ['initial', 'settlement'])->index();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('recorded_by_name_snapshot');
            $table->timestamp('paid_at')->index();
            $table->string('note', 500)->nullable();
            $table->decimal('cumulative_paid_after', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->enum('payment_status_after', ['paid', 'partial', 'unpaid']);
            $table->unsignedBigInteger('initial_sale_guard')->nullable()->unique();
            $table->timestamps();

            $table->index(['sale_id', 'paid_at']);
            $table->index(['customer_id', 'paid_at']);
            $table->index(['recorded_by', 'paid_at']);
        });

        DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_snapshots_nonnegative CHECK (cumulative_paid_after >= 0 AND balance_after >= 0)');

        DB::transaction(function (): void {
            DB::table('sales')->where('amount_paid', '>', 0)->orderBy('id')->each(function (object $sale): void {
                $id = DB::table('sale_payments')->insertGetId([
                    'payment_number' => 'PENDING-'.Str::random(20),
                    'sale_id' => $sale->id,
                    'customer_id' => $sale->customer_id,
                    'amount' => $sale->amount_paid,
                    'payment_method' => $sale->payment_method,
                    'payment_type' => 'initial',
                    'recorded_by' => $sale->sold_by,
                    'recorded_by_name_snapshot' => $sale->sold_by_name_snapshot,
                    'paid_at' => $sale->created_at,
                    'note' => null,
                    'cumulative_paid_after' => $sale->amount_paid,
                    'balance_after' => $sale->balance_due,
                    'payment_status_after' => $sale->payment_status,
                    'initial_sale_guard' => $sale->id,
                    'created_at' => $sale->created_at,
                    'updated_at' => $sale->created_at,
                ]);
                DB::table('sale_payments')->where('id', $id)->update(['payment_number' => PaymentNumber::fromId($id)]);
            });
        });

        Schema::create('sale_payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_id');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('sale_payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_payments') && DB::table('sale_payments')->exists()) {
            throw new RuntimeException('Cannot roll back the Sale payment ledger while payment history exists.');
        }

        Schema::dropIfExists('sale_payment_requests');
        Schema::dropIfExists('sale_payments');
    }
};
