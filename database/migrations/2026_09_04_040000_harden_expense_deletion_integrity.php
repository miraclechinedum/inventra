<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $unlinkedExpenses = DB::table('expenses')
            ->leftJoin('expense_requests', 'expense_requests.expense_id', '=', 'expenses.id')
            ->whereNull('expense_requests.id')
            ->exists();

        if ($unlinkedExpenses) {
            throw new RuntimeException('Cannot harden Expense deletion integrity while an Expense lacks its retained request record.');
        }

        Schema::table('expense_requests', function (Blueprint $table): void {
            $table->dropForeign(['expense_id']);
            $table->foreign('expense_id')->references('id')->on('expenses')->restrictOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('expense_request_id')->nullable()->unique()->after('id');
        });

        DB::statement('UPDATE expenses e JOIN expense_requests r ON r.expense_id = e.id SET e.expense_request_id = r.id');
        DB::statement('ALTER TABLE expenses MODIFY expense_request_id BIGINT UNSIGNED NOT NULL');

        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreign('expense_request_id')->references('id')->on('expense_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign(['expense_request_id']);
            $table->dropUnique(['expense_request_id']);
            $table->dropColumn('expense_request_id');
        });

        Schema::table('expense_requests', function (Blueprint $table): void {
            $table->dropForeign(['expense_id']);
            $table->foreign('expense_id')->references('id')->on('expenses')->nullOnDelete();
        });
    }
};
