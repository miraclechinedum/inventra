<?php

use App\Enums\OperationalAlertSeverity;
use App\Enums\OperationalAlertStatus;
use App\Enums\OperationalAlertType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent operational alerts: one row per lifecycle occurrence of a condition, plus one
 * recipient row per delivered user.
 *
 * The dedupe invariant is `active_key`. While an alert is active the column holds
 * "type:subject_type:subject_id" and is UNIQUE, so a second active alert for the same condition and
 * subject cannot be inserted by any code path or race. Resolving sets it to NULL, and MySQL permits
 * unlimited NULLs in a UNIQUE index, so history accumulates freely and a recurrence simply opens a
 * new row with the next `occurrence`. That is why dedupe is not a check-then-insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40);
            $table->string('severity', 16);
            $table->string('status', 16);
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label_snapshot', 191);
            $table->string('title', 191);
            $table->string('message', 500);
            // NULL once resolved; UNIQUE while active. This is the deduplication invariant.
            $table->string('active_key', 191)->nullable()->unique();
            $table->unsignedInteger('occurrence')->default(1);
            $table->timestamp('first_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Reconciliation sweeps the active alerts of one type to resolve the stale ones; with
            // subject_type and subject_id included, EXPLAIN reports a covering index lookup instead
            // of filtering status after reading rows through the subject index.
            $table->index(['status', 'type', 'subject_type', 'subject_id'], 'operational_alerts_sweep_index');
            // Subject pages and recurrence counting look an alert up by its subject.
            $table->index(['subject_type', 'subject_id', 'type']);
            // Subject pages and the audit-style view read an alert by when it was raised.
            $table->index(['created_at', 'id']);
        });

        DB::statement("ALTER TABLE operational_alerts ADD CONSTRAINT operational_alerts_type_valid CHECK (type IN ('".implode("','", OperationalAlertType::values())."'))");
        DB::statement("ALTER TABLE operational_alerts ADD CONSTRAINT operational_alerts_severity_valid CHECK (severity IN ('".implode("','", OperationalAlertSeverity::values())."'))");
        DB::statement("ALTER TABLE operational_alerts ADD CONSTRAINT operational_alerts_status_valid CHECK (status IN ('".implode("','", OperationalAlertStatus::values())."'))");
        // An active alert always holds its dedupe key and no resolution time; a resolved one is the
        // exact inverse. This makes "resolved but still blocking a new occurrence" unrepresentable.
        DB::statement("ALTER TABLE operational_alerts ADD CONSTRAINT operational_alerts_lifecycle_valid CHECK (
            (status = 'active' AND active_key IS NOT NULL AND resolved_at IS NULL)
            OR (status = 'resolved' AND active_key IS NULL AND resolved_at IS NOT NULL)
        )");
        DB::statement('ALTER TABLE operational_alerts ADD CONSTRAINT operational_alerts_occurrence_valid CHECK (occurrence >= 1)');

        Schema::create('operational_alert_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operational_alert_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            // One delivery per user per alert, enforced against concurrent evaluators.
            $table->unique(['operational_alert_id', 'user_id']);
            // The unread badge counts by user with read_at IS NULL.
            $table->index(['user_id', 'read_at']);
            // The notification list pages one user's rows newest-alert-first.
            $table->index(['user_id', 'operational_alert_id']);
        });

        // Acknowledging means the operator saw it, so an acknowledged row is never unread.
        DB::statement('ALTER TABLE operational_alert_recipients ADD CONSTRAINT operational_alert_recipients_ack_implies_read CHECK (acknowledged_at IS NULL OR read_at IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_alert_recipients');
        Schema::dropIfExists('operational_alerts');
    }
};
