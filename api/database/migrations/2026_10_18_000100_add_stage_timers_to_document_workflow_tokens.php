<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// WF-09: reminders, the overdue notice and escalation for plain stages
// (StageTimers). `next_timer_at` is when the position's next timer falls
// due (null: none left); `reminders_sent`, `overdue_notified_at` and
// `escalated_at` record what was sent, so a rerun never sends it twice.
// Active positions with a time limit start with their due time; the first
// run works out what else is due (approval positions are cleared then:
// approvals have their own timers, APR-05).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table document_workflow_tokens add column next_timer_at timestamptz null');
        DB::statement('alter table document_workflow_tokens add column reminders_sent smallint not null default 0');
        DB::statement('alter table document_workflow_tokens add column overdue_notified_at timestamptz null');
        DB::statement('alter table document_workflow_tokens add column escalated_at timestamptz null');
        DB::statement("create index document_workflow_tokens_timers on document_workflow_tokens (next_timer_at) where status = 'active' and next_timer_at is not null");
        DB::statement("update document_workflow_tokens set next_timer_at = due_at where status = 'active' and due_at is not null");
    }

    public function down(): void
    {
        DB::statement('drop index if exists document_workflow_tokens_timers');
        DB::statement('alter table document_workflow_tokens drop column escalated_at');
        DB::statement('alter table document_workflow_tokens drop column overdue_notified_at');
        DB::statement('alter table document_workflow_tokens drop column reminders_sent');
        DB::statement('alter table document_workflow_tokens drop column next_timer_at');
    }
};
