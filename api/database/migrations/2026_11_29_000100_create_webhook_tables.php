<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// HOOK-01, TEN-01 (docs/adr/021-webhooks.md): webhook subscriptions to the
// public event catalogue and their delivery log.
//
// - webhook_subscriptions: owned by a principal (a user now, an API key
//   once the public API lands). `url`, `secret` and `previous_secret` are
//   encrypted by the application and denied in the full export (EXP-02).
//   Archived, never deleted (TEN-06).
// - webhook_deliveries: one row per subscription and event (an outbox),
//   with the filtered body, the last attempt's answer and its duration. An
//   operational log kept 30 days (`webhooks:sweep` deletes older rows).
// - app_tenants_with_webhook_work(stale_before, prune_before) finds, before
//   any tenant is set (ADR 002), the tenants with deliveries a dead worker
//   left behind or past their retention.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('principal_type', 20);
            $table->uuid('principal_id');
            $table->text('url');
            $table->string('url_display', 300);
            $table->string('description', 200)->nullable();
            $table->jsonb('events');
            $table->boolean('active')->default(true);
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestampTz('previous_secret_expires_at')->nullable();
            $table->timestampTz('secret_rotated_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('disabled_reason', 20)->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampTz('last_delivery_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'active']);
        });

        DB::statement("alter table webhook_subscriptions add constraint webhook_subscriptions_principal_check check (principal_type in ('user', 'api_key'))");
        DB::statement("alter table webhook_subscriptions add constraint webhook_subscriptions_disabled_check check (disabled_reason is null or disabled_reason in ('failing'))");
        DB::statement('alter table webhook_subscriptions add constraint webhook_subscriptions_filter_check check (company_id is null or branch_id is null)');
        DB::statement("alter table webhook_subscriptions add constraint webhook_subscriptions_events_check check (jsonb_typeof(events) = 'array')");
        Rls::enable('webhook_subscriptions');

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('subscription_id')->constrained('webhook_subscriptions')->restrictOnDelete();
            $table->uuid('event_id');
            $table->string('event_type', 80);
            // json, not jsonb: the body keeps the documented key order.
            $table->json('payload');
            // Where the event happened (company, branch, location, document): the delivery is checked
            // again against it at send time and for each reader of the log (RBAC-04).
            $table->jsonb('occurrence')->nullable();
            $table->string('status', 20);
            $table->smallInteger('attempts')->default(0);
            $table->smallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->uuid('replay_of')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['subscription_id', 'created_at']);
            $table->index('created_at');
            $table->index('replay_of');
        });

        // The sweep's questions (app_tenants_with_webhook_work, SweepWebhooks), each with its own index.
        DB::statement("create index webhook_deliveries_stale_idx on webhook_deliveries (updated_at) where status in ('pending', 'sending')");
        DB::statement("create index webhook_deliveries_retry_idx on webhook_deliveries (next_attempt_at) where status = 'retrying'");
        // One delivery per subscription and event (a fan-out retried after its commit adds nothing); replays add rows.
        DB::statement('create unique index webhook_deliveries_once on webhook_deliveries (subscription_id, event_id) where replay_of is null');

        DB::statement("alter table webhook_deliveries add constraint webhook_deliveries_status_check check (status in ('pending', 'sending', 'retrying', 'delivered', 'failed'))");
        // A replay names a delivery of the same tenant (a plain FK is checked without RLS).
        DB::statement('alter table webhook_deliveries add constraint webhook_deliveries_replay_fk foreign key (replay_of) references webhook_deliveries (id) on delete set null');
        Rls::enable('webhook_deliveries');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_with_webhook_work(p_stale_before timestamptz, p_prune_before timestamptz) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $fn$
                select d.tenant_id from public.webhook_deliveries d
                where d.status in ('pending', 'sending') and d.updated_at < p_stale_before
                union
                select d.tenant_id from public.webhook_deliveries d
                where d.status = 'retrying' and d.next_attempt_at < p_stale_before
                union
                select d.tenant_id from public.webhook_deliveries d
                where d.created_at < p_prune_before
                order by 1
            $fn$;

            revoke all on function public.app_tenants_with_webhook_work(timestamptz, timestamptz) from public;
            SQL);

        DB::statement("grant execute on function public.app_tenants_with_webhook_work(timestamptz, timestamptz) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::unprepared('drop function if exists public.app_tenants_with_webhook_work(timestamptz, timestamptz)');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
    }
};
