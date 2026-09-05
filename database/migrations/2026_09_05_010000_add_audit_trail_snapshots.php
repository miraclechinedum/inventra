<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit evidence must survive the deletion or renaming of the people and records it describes.
 * `actor_id` is nullOnDelete and `auditable_id` has no constraint at all, so before this change a
 * removed User erased attribution and a removed subject left an event pointing at nothing.
 * Rows written before this migration keep NULL snapshots; they are not backfilled, because the
 * current name of a User is not evidence of the name they had when the event occurred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('actor_name_snapshot', 120)->nullable()->after('actor_id');
            $table->string('actor_role_snapshot', 32)->nullable()->after('actor_name_snapshot');
            $table->string('subject_label_snapshot', 191)->nullable()->after('auditable_id');

            // The trail is append-only and never pruned, so one actor's slice of it grows without
            // bound. EXPLAIN showed the actor filter reading that whole slice and sorting it to
            // return a single page; this index orders it in place instead. No matching index is
            // added for auditable_type: the existing audit_entity_date_index already leads on that
            // column, and MySQL selects it in preference to any parallel index.
            $table->index(['actor_id', 'created_at'], 'audit_actor_date_index');
        });
    }

    public function down(): void
    {
        // MySQL adopts audit_actor_date_index as the index backing the actor_id foreign key and
        // discards the original single-column one, so the constraint has to be released before the
        // index can be dropped. Re-adding it recreates the index the original schema had.
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropForeign(['actor_id']));
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropIndex('audit_actor_date_index'));

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->dropColumn(['actor_name_snapshot', 'actor_role_snapshot', 'subject_label_snapshot']);
        });
    }
};
