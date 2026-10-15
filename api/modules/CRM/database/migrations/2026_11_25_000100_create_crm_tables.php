<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CRM and sales module (docs/modules/crm.md). Every table is the tenant's,
// under forced row-level security (TEN-01, CRM-35). Money is bigint minor
// units next to its currency; base amounts in the company's base currency
// with the rate used in an fx snapshot (numeric(18,8), ADR 003, CUR-04);
// quantities numeric(20,6). Users are referenced by (tenant_id, id), so a
// row never points at another tenant's user. Records are archived or
// cancelled, never deleted (TEN-06). The public lead form (CRM-06) finds
// the tenant of a token hash through a security-definer function that
// returns nothing but the tenant id (ADR 002).
return new class extends Migration
{
    public function up(): void
    {
        $userRef = function (Blueprint $table, string $column, bool $nullable = true): void {
            $definition = $table->uuid($column);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };
        $currency = function (Blueprint $table, string $column, bool $nullable = false): void {
            $definition = $table->char($column, 3);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign($column)->references('code')->on('currencies')->restrictOnDelete();
        };
        $check = fn (string $table, string $name, string $condition) => DB::statement("alter table {$table} add constraint {$table}_{$name}_check check ({$condition})");
        $in = fn (string $column, array $values) => "{$column} in ('".implode("', '", $values)."')";
        // CRM-08: what every scoped record carries.
        $place = function (Blueprint $table) use ($userRef): void {
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $userRef($table, 'owner_id');
            $table->foreignUuid('team_id')->nullable()->constrained('crm_teams')->restrictOnDelete();
        };

        // CRM-04: teams and their members.
        Schema::create('crm_teams', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $userRef($table, 'leader_id');
            // CRM-11: the member whose turn is next (an index into the members ordered by user id).
            $table->integer('turn')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        Rls::enable('crm_teams');

        Schema::create('crm_team_members', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('team_id')->constrained('crm_teams')->restrictOnDelete();
            $userRef($table, 'user_id', nullable: false);
            $table->timestampsTz();

            $table->unique(['team_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
        Rls::enable('crm_team_members');

        // CRM-01: settings per company.
        Schema::create('crm_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('quote_approval_minor')->nullable();
            $table->integer('quote_validity_days')->default(30);
            $table->foreignUuid('default_team_id')->nullable()->constrained('crm_teams')->restrictOnDelete();
            $table->boolean('lost_reason_required')->default(true);
            $table->timestampsTz();
        });
        $check('crm_settings', 'values', 'quote_validity_days between 1 and 365 and (quote_approval_minor is null or quote_approval_minor >= 0)');
        Rls::enable('crm_settings');

        // CRM-02: pipelines and their open stages.
        Schema::create('crm_pipelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement('create unique index crm_pipelines_one_default on crm_pipelines (company_id) where is_default and archived_at is null');
        Rls::enable('crm_pipelines');

        Schema::create('crm_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('pipeline_id')->constrained('crm_pipelines')->restrictOnDelete();
            $table->string('name', 120);
            $table->integer('position');
            $table->smallInteger('probability');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'pipeline_id']);
        });
        $check('crm_stages', 'probability', 'probability between 0 and 100');
        Rls::enable('crm_stages');

        // CRM-03: sources, won reasons and lost reasons.
        Schema::create('crm_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('name', 120);
            $table->integer('position')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'kind']);
        });
        $check('crm_options', 'kind', $in('kind', ['source', 'won_reason', 'lost_reason']));
        Rls::enable('crm_options');

        // CRM-11: assignment rules, in order.
        Schema::create('crm_assignment_rules', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->integer('position')->default(0);
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->jsonb('source_ids')->default('[]');
            $table->jsonb('channels')->default('[]');
            $table->string('assignee_type', 10);
            $userRef($table, 'user_id');
            $table->foreignUuid('team_id')->nullable()->constrained('crm_teams')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        $check('crm_assignment_rules', 'assignee', "(assignee_type = 'user' and user_id is not null and team_id is null) or (assignee_type = 'team' and team_id is not null and user_id is null)");
        Rls::enable('crm_assignment_rules');

        // CRM-06: public lead forms (the token is stored only as its sha256).
        Schema::create('crm_lead_forms', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->foreignUuid('source_id')->nullable()->constrained('crm_options')->restrictOnDelete();
            $table->foreignUuid('team_id')->nullable()->constrained('crm_teams')->restrictOnDelete();
            $table->jsonb('fields');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('token_rotated_at')->nullable();
            $table->integer('submissions')->default(0);
            $table->timestampTz('last_submitted_at')->nullable();
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });
        Rls::enable('crm_lead_forms');

        // CRM-05: leads.
        Schema::create('crm_leads', function (Blueprint $table) use ($userRef, $currency, $place) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->string('status', 20);
            $table->string('name', 200);
            $table->string('organisation', 200)->nullable();
            $table->string('title', 120)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignUuid('source_id')->nullable()->constrained('crm_options')->restrictOnDelete();
            $table->string('channel', 10);
            $table->foreignUuid('form_id')->nullable()->constrained('crm_lead_forms')->restrictOnDelete();
            $table->bigInteger('estimated_value_minor')->nullable();
            $currency($table, 'currency', nullable: true);
            $table->text('notes')->nullable();
            $table->text('disqualify_reason')->nullable();
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            // The opportunity a conversion made (its foreign key is added below, once the table exists).
            $table->uuid('opportunity_id')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $userRef($table, 'converted_by');
            $table->jsonb('custom')->nullable();
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'party_id']);
        });
        $check('crm_leads', 'status', $in('status', ['new', 'contacted', 'qualified', 'converted', 'disqualified']));
        $check('crm_leads', 'channel', $in('channel', ['manual', 'form', 'import', 'api']));
        $check('crm_leads', 'value', '(estimated_value_minor is null or (estimated_value_minor >= 0 and currency is not null))');
        DB::statement('create index crm_leads_custom on crm_leads using gin (custom jsonb_path_ops)');
        Rls::enable('crm_leads');

        // CRM-14, CRM-15: opportunities.
        Schema::create('crm_opportunities', function (Blueprint $table) use ($userRef, $currency, $place) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $place($table);
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
            $table->foreignUuid('pipeline_id')->constrained('crm_pipelines')->restrictOnDelete();
            $table->foreignUuid('stage_id')->constrained('crm_stages')->restrictOnDelete();
            $table->string('name', 200);
            $table->bigInteger('value_minor');
            $currency($table, 'currency');
            $table->date('expected_close_on')->nullable();
            $table->smallInteger('probability');
            $table->string('status', 10);
            $table->foreignUuid('reason_id')->nullable()->constrained('crm_options')->restrictOnDelete();
            $table->text('close_note')->nullable();
            $table->date('closed_on')->nullable();
            $userRef($table, 'closed_by');
            $table->fxSnapshot('fx');
            $currency($table, 'base_currency', nullable: true);
            $table->bigInteger('base_value_minor')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('custom')->nullable();
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'pipeline_id', 'status']);
            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'party_id']);
        });
        $check('crm_opportunities', 'status', $in('status', ['open', 'won', 'lost']));
        $check('crm_opportunities', 'values', 'value_minor >= 0 and probability between 0 and 100 and (base_value_minor is null or base_value_minor >= 0)');
        $check('crm_opportunities', 'closed', "(status = 'open') = (closed_on is null)");
        DB::statement('create index crm_opportunities_custom on crm_opportunities using gin (custom jsonb_path_ops)');
        Rls::enable('crm_opportunities');
        DB::statement('alter table crm_leads add constraint crm_leads_opportunity_fk foreign key (opportunity_id) references crm_opportunities (id) on delete restrict');

        // CRM-17 to CRM-24: quotes and their lines.
        Schema::create('crm_quotes', function (Blueprint $table) use ($userRef, $currency, $place) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('number', 80)->nullable();
            $place($table);
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('opportunity_id')->nullable()->constrained('crm_opportunities')->restrictOnDelete();
            $table->string('status', 12);
            $currency($table, 'currency');
            $table->foreignUuid('price_list_id')->nullable()->constrained('price_lists')->restrictOnDelete();
            $table->boolean('tax_inclusive')->default(false);
            $table->date('quote_date');
            $table->date('valid_until');
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->fxSnapshot('fx');
            $currency($table, 'base_currency', nullable: true);
            $table->bigInteger('base_subtotal_minor')->nullable();
            $table->bigInteger('base_discount_minor')->nullable();
            $table->bigInteger('base_tax_minor')->nullable();
            $table->bigInteger('base_total_minor')->nullable();
            $table->decimal('max_discount_percent', 5, 2)->default(0);
            $table->boolean('needs_approval')->default(false);
            $userRef($table, 'requested_by', nullable: false);
            $table->timestampTz('submitted_at')->nullable();
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $userRef($table, 'answered_by');
            $table->text('decline_reason')->nullable();
            $table->string('converted_to', 20)->nullable();
            $table->jsonb('converted_ref')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $userRef($table, 'converted_by');
            $table->timestampTz('cancelled_at')->nullable();
            $userRef($table, 'cancelled_by');
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'party_id']);
            $table->index(['tenant_id', 'opportunity_id']);
        });
        $check('crm_quotes', 'status', $in('status', ['draft', 'pending', 'approved', 'rejected', 'sent', 'accepted', 'declined', 'converted', 'cancelled']));
        $check('crm_quotes', 'amounts', 'subtotal_minor >= 0 and discount_minor >= 0 and tax_minor >= 0 and total_minor >= 0 and valid_until >= quote_date and max_discount_percent between 0 and 100');
        $check('crm_quotes', 'converted', $in('converted_to', ['invoice', 'pos_order', 'ecommerce_order']).' or converted_to is null');
        DB::statement('create unique index crm_quotes_number_unique on crm_quotes (company_id, number) where number is not null');
        Rls::enable('crm_quotes');

        Schema::create('crm_quote_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('quote_id')->constrained('crm_quotes')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 500);
            $table->foreignUuid('uom_id')->nullable()->constrained('uoms')->restrictOnDelete();
            $table->decimal('qty', 20, 6);
            $table->bigInteger('list_price_minor')->nullable();
            $table->bigInteger('unit_price_minor');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate', 9, 4)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'quote_id']);
        });
        $check('crm_quote_lines', 'amounts', 'qty > 0 and unit_price_minor >= 0 and discount_percent between 0 and 100 and gross_minor >= 0 and discount_minor >= 0 and discount_minor <= gross_minor and tax_minor >= 0 and total_minor >= 0');
        Rls::enable('crm_quote_lines');

        // CRM-26 to CRM-29: activities (and logged emails).
        Schema::create('crm_activities', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $userRef($table, 'owner_id');
            $table->foreignUuid('team_id')->nullable()->constrained('crm_teams')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('subject', 200);
            $table->text('notes')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('remind_at')->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->string('status', 10);
            $table->timestampTz('done_at')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignUuid('lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
            $table->foreignUuid('opportunity_id')->nullable()->constrained('crm_opportunities')->restrictOnDelete();
            $table->foreignUuid('quote_id')->nullable()->constrained('crm_quotes')->restrictOnDelete();
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->jsonb('email')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index(['tenant_id', 'branch_id', 'starts_at']);
            $table->index(['tenant_id', 'owner_id', 'starts_at']);
            $table->index(['tenant_id', 'party_id']);
        });
        $check('crm_activities', 'kind', $in('kind', ['call', 'meeting', 'email', 'task']));
        $check('crm_activities', 'status', $in('status', ['open', 'done', 'cancelled']));
        $check('crm_activities', 'times', 'ends_at is null or ends_at >= starts_at');
        DB::statement("create index crm_activities_due_reminders on crm_activities (remind_at) where reminded_at is null and status = 'open'");
        Rls::enable('crm_activities');

        // CRM-30: sales targets.
        Schema::create('crm_targets', function (Blueprint $table) use ($userRef, $currency) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('subject_type', 10);
            $table->uuid('subject_id');
            $table->string('metric', 20);
            $table->date('period_start');
            $table->date('period_end');
            $currency($table, 'currency');
            $table->bigInteger('amount_minor');
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'period_start']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });
        $check('crm_targets', 'subject', $in('subject_type', ['user', 'team', 'branch']));
        $check('crm_targets', 'metric', $in('metric', ['won_value', 'pos_sales', 'invoiced']));
        $check('crm_targets', 'values', 'amount_minor >= 0 and period_end >= period_start');
        Rls::enable('crm_targets');

        // CRM-06: the tenant of a lead form's token hash, across tenants, for the
        // public route. Runs as the schema owner and returns nothing but the id.
        DB::unprepared(<<<'SQL'
            create or replace function public.crm_lead_form_tenant(p_token_hash text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select f.tenant_id from public.crm_lead_forms f
                where f.token_hash = p_token_hash
                limit 1
            $$;

            revoke all on function public.crm_lead_form_tenant(text) from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function public.crm_lead_form_tenant(text) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.crm_lead_form_tenant(text)');
        DB::statement('alter table if exists crm_leads drop constraint if exists crm_leads_opportunity_fk');

        foreach (['crm_targets', 'crm_activities', 'crm_quote_lines', 'crm_quotes', 'crm_opportunities', 'crm_leads', 'crm_lead_forms',
            'crm_assignment_rules', 'crm_options', 'crm_stages', 'crm_pipelines', 'crm_settings', 'crm_team_members', 'crm_teams'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
