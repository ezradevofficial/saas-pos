<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// AUTO-01..AUTO-07: automation rules and their run log.
//
// automation_rules: a tenant's rule for a document type in one company or
// every company (company_id null): a trigger, conditions (the workflow
// condition JSON) and an ordered list of actions. Rules are archived,
// never deleted (TEN-06); every edit raises `version` and is audited.
// The webhook signing secret is stored encrypted and never returned.
//
// automation_runs: one row per triggered run (AUTO-05), with the rule
// version, what triggered it, the outcome, per-action results, a safe
// error, attempts and the loop-protection chain (AUTO-06). `dedupe_key`
// keeps date and schedule triggers from running twice for one occurrence.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name', 120);
            $table->string('document_type', 100);
            $table->foreignUuid('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('trigger_type', 30);
            $table->jsonb('trigger');
            $table->jsonb('conditions')->nullable();
            $table->jsonb('actions');
            $table->boolean('enabled')->default(false);
            $table->integer('version')->default(1);
            $table->text('webhook_secret')->nullable();
            // Schedule triggers: the next occurrence, in UTC.
            $table->timestampTz('next_run_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'document_type', 'trigger_type']);
        });

        DB::statement("alter table automation_rules add constraint automation_rules_trigger_type_check check (trigger_type in ('record_created', 'record_updated', 'record_archived', 'field_changed', 'stage_entered', 'stage_left', 'date', 'threshold', 'schedule'))");
        DB::statement('alter table automation_rules add constraint automation_rules_version_check check (version >= 1)');
        DB::statement('alter table automation_rules add constraint automation_rules_archived_check check (archived_at is null or enabled = false)');
        DB::statement('create index automation_rules_due on automation_rules (next_run_at) where enabled and next_run_at is not null');
        Rls::enable('automation_rules');

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('rule_id')->constrained('automation_rules')->restrictOnDelete();
            $table->integer('rule_version');
            $table->string('trigger_type', 30);
            $table->jsonb('trigger')->default('{}');
            $table->string('document_type', 100)->nullable();
            $table->uuid('document_id')->nullable();
            $table->string('outcome', 20);
            $table->jsonb('conditions')->nullable();
            $table->jsonb('actions')->default('[]');
            $table->text('error')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->uuid('chain_id');
            $table->smallInteger('depth')->default(1);
            $table->jsonb('chain')->default('[]');
            $table->string('dedupe_key', 200)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['rule_id', 'created_at']);
        });

        DB::statement("alter table automation_runs add constraint automation_runs_outcome_check check (outcome in ('queued', 'running', 'retrying', 'succeeded', 'skipped', 'failed', 'throttled', 'loop_blocked'))");
        DB::statement('create unique index automation_runs_dedupe on automation_runs (rule_id, dedupe_key) where dedupe_key is not null');
        Rls::enable('automation_runs');
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
