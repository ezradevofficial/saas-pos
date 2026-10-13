<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Accounting module (docs/modules/accounting.md). Every table is the
// tenant's, under forced row-level security (TEN-01). Amounts are bigint
// minor units next to a currency (ADR 003); rates numeric(18,8) through the
// fxSnapshot macro (CUR-04); base amounts are in the company's base
// currency. Users are referenced by (tenant_id, id). The ledger's own rules
// live here, so no code path can break them (ACC-03, ACC-08):
// acc_journal_balanced, acc_journal_immutable, acc_journal_period_open.
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

        $dimensions = function (Blueprint $table): void {
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->restrictOnDelete();
        };

        // ACC-01: the chart of accounts of a company.
        Schema::create('acc_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('type', 10);
            $table->string('subtype', 30);
            $table->uuid('parent_id')->nullable();
            $table->boolean('postable')->default(true);
            $table->char('currency', 3)->nullable();
            $table->boolean('is_control')->default(false);
            $table->string('cash_flow', 10)->nullable();
            $table->boolean('revalue')->default(false);
            $table->foreignUuid('counterparty_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('template_code', 20)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
        });

        DB::statement("alter table acc_accounts add constraint acc_accounts_type_check check (type in ('asset', 'liability', 'equity', 'income', 'expense'))");
        DB::statement("alter table acc_accounts add constraint acc_accounts_cash_flow_check check (cash_flow is null or cash_flow in ('cash', 'operating', 'non_cash', 'investing', 'financing'))");
        DB::statement('alter table acc_accounts add constraint acc_accounts_parent_fk foreign key (parent_id) references acc_accounts (id) on delete restrict');
        Rls::enable('acc_accounts');

        // ACC-02: the accounts each role posts to, per company.
        Schema::create('acc_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->unique()->constrained()->restrictOnDelete();
            $table->string('template', 20)->nullable();

            foreach (['receivable', 'payable', 'sales_tax', 'purchase_tax', 'fx_gain', 'fx_loss', 'rounding', 'retained_earnings', 'suspense'] as $role) {
                $table->foreignUuid("{$role}_account_id")->nullable()->constrained('acc_accounts')->restrictOnDelete();
            }

            $table->timestampsTz();
        });
        Rls::enable('acc_settings');

        // ACC-08: monthly periods of a company's fiscal years.
        Schema::create('acc_periods', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->integer('fiscal_year');
            $table->smallInteger('number');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 10)->default('open');
            $table->timestampTz('closed_at')->nullable();
            $userRef($table, 'closed_by');
            $table->timestampTz('reopened_at')->nullable();
            $userRef($table, 'reopened_by');
            $table->text('reopen_reason')->nullable();
            $table->uuid('closing_journal_id')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'starts_on']);
            $table->unique(['company_id', 'fiscal_year', 'number']);
        });

        DB::statement("alter table acc_periods add constraint acc_periods_status_check check (status in ('open', 'closed'))");
        DB::statement('alter table acc_periods add constraint acc_periods_dates_check check (starts_on <= ends_on and number between 1 and 12)');
        DB::statement("alter table acc_periods add constraint acc_periods_closed_check check ((status = 'closed') = (closed_at is not null))");
        Rls::enable('acc_periods');

        // ACC-06: recurring journal templates.
        Schema::create('acc_recurring_journals', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('frequency', 10);
            $table->date('next_run_on');
            $table->date('ends_on')->nullable();
            $table->date('last_run_on')->nullable();
            $table->string('status', 10)->default('active');
            $table->char('currency', 3);
            $table->text('memo')->nullable();
            $table->jsonb('lines');
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'next_run_on']);
        });

        DB::statement("alter table acc_recurring_journals add constraint acc_recurring_frequency_check check (frequency in ('monthly', 'quarterly', 'yearly'))");
        DB::statement("alter table acc_recurring_journals add constraint acc_recurring_status_check check (status in ('active', 'paused', 'ended'))");
        DB::statement("alter table acc_recurring_journals add constraint acc_recurring_lines_check check (jsonb_typeof(lines) = 'array')");
        Rls::enable('acc_recurring_journals');

        // ACC-06: the scheduler finds tenants with recurring journals due without reading their rows (ids only).
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::unprepared(<<<'SQL'
            create or replace function public.app_tenants_with_due_recurring_journals(p_on date) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $fn$
                select distinct r.tenant_id from public.acc_recurring_journals r
                where r.status = 'active' and r.archived_at is null and r.next_run_on <= p_on
                order by 1
            $fn$;

            revoke all on function public.app_tenants_with_due_recurring_journals(date) from public;
            SQL);
        DB::statement("grant execute on function public.app_tenants_with_due_recurring_journals(date) to {$runtimeRole}");

        // ACC-03: journal headers.
        Schema::create('acc_journals', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('number', 80)->nullable();
            $table->date('date');
            $table->foreignUuid('period_id')->constrained('acc_periods')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('status', 10);
            $table->string('source_type', 60)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('reference', 80)->nullable();
            $table->text('memo')->nullable();
            $table->char('base_currency', 3);
            $table->bigInteger('total_base_minor')->default(0);
            $table->uuid('reverses_journal_id')->nullable()->unique();
            $table->uuid('reversed_by_journal_id')->nullable()->unique();
            $table->foreignUuid('recurring_journal_id')->nullable()->constrained('acc_recurring_journals')->restrictOnDelete();
            $userRef($table, 'created_by');
            $userRef($table, 'posted_by');
            $table->timestampTz('posted_at')->nullable();
            $userRef($table, 'decided_by');
            $table->timestampTz('decided_at')->nullable();
            $table->string('post_error', 40)->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("alter table acc_journals add constraint acc_journals_kind_check check (kind in ('manual', 'system', 'recurring', 'reversal', 'revaluation', 'closing', 'opening'))");
        DB::statement("alter table acc_journals add constraint acc_journals_status_check check (status in ('draft', 'pending', 'approved', 'posted', 'rejected', 'cancelled'))");
        DB::statement("alter table acc_journals add constraint acc_journals_posted_check check ((status = 'posted') = (posted_at is not null))");
        DB::statement('alter table acc_journals add constraint acc_journals_source_check check ((source_type is null) = (source_id is null))');
        // ACC-07: a source posts once per company (idempotency key).
        DB::statement('create unique index acc_journals_source_unique on acc_journals (company_id, source_type, source_id) where source_type is not null');
        DB::statement('alter table acc_periods add constraint acc_periods_closing_journal_fk foreign key (closing_journal_id) references acc_journals (id) on delete restrict');
        DB::statement('alter table acc_journals add constraint acc_journals_reverses_fk foreign key (reverses_journal_id) references acc_journals (id) on delete restrict');
        DB::statement('alter table acc_journals add constraint acc_journals_reversed_by_fk foreign key (reversed_by_journal_id) references acc_journals (id) on delete restrict');
        Rls::enable('acc_journals');

        // ACC-03, ACC-21: journal lines.
        Schema::create('acc_journal_lines', function (Blueprint $table) use ($dimensions) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('journal_id')->constrained('acc_journals')->restrictOnDelete();
            $table->integer('line_no');
            $table->foreignUuid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->char('currency', 3);
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->fxSnapshot('fx');
            $table->bigInteger('base_debit_minor')->default(0);
            $table->bigInteger('base_credit_minor')->default(0);
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $dimensions($table);
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['journal_id', 'line_no']);
            $table->index(['company_id', 'account_id']);
            $table->index(['party_id']);
        });

        DB::statement('alter table acc_journal_lines add constraint acc_journal_lines_amounts_check check (
            debit_minor >= 0 and credit_minor >= 0 and base_debit_minor >= 0 and base_credit_minor >= 0
            and (debit_minor = 0 or credit_minor = 0) and (base_debit_minor = 0 or base_credit_minor = 0)
            and (debit_minor = 0 or base_credit_minor = 0) and (credit_minor = 0 or base_debit_minor = 0)
            and (debit_minor + credit_minor + base_debit_minor + base_credit_minor) > 0)');
        Rls::enable('acc_journal_lines');

        // ACC-03: a posted journal balances in base currency, checked at commit.
        DB::unprepared(<<<'SQL'
            create or replace function acc_journal_balanced() returns trigger language plpgsql as $$
            declare
                jid uuid;
                jstatus text;
                lines integer;
                debits bigint;
                credits bigint;
            begin
                if tg_table_name = 'acc_journals' then
                    jid := new.id;
                elsif tg_op = 'DELETE' then
                    jid := old.journal_id;
                else
                    jid := new.journal_id;
                end if;

                select status into jstatus from acc_journals where id = jid;

                if jstatus is distinct from 'posted' then
                    return null;
                end if;

                select count(*), coalesce(sum(base_debit_minor), 0), coalesce(sum(base_credit_minor), 0)
                    into lines, debits, credits
                    from acc_journal_lines where journal_id = jid;

                if lines < 2 or debits <> credits or debits = 0 then
                    raise exception 'acc_journal_unbalanced: journal % has % lines, base debits %, base credits %', jid, lines, debits, credits
                        using errcode = 'check_violation';
                end if;

                return null;
            end
            $$;

            create constraint trigger acc_journal_lines_balanced
                after insert or update or delete on acc_journal_lines
                deferrable initially deferred for each row execute function acc_journal_balanced();

            create constraint trigger acc_journals_balanced
                after insert or update on acc_journals
                deferrable initially deferred for each row execute function acc_journal_balanced();
            SQL);

        // ACC-03: posted journals are immutable; only the reversal link may be set.
        DB::unprepared(<<<'SQL'
            create or replace function acc_journal_lines_immutable() returns trigger language plpgsql as $$
            declare
                jid uuid;
            begin
                jid := case when tg_op = 'INSERT' then new.journal_id else old.journal_id end;

                if exists (select 1 from acc_journals where id = jid and status = 'posted') then
                    raise exception 'acc_journal_immutable: journal % is posted', jid using errcode = 'check_violation';
                end if;

                if tg_op = 'UPDATE' and new.journal_id <> old.journal_id
                    and exists (select 1 from acc_journals where id = new.journal_id and status = 'posted') then
                    raise exception 'acc_journal_immutable: journal % is posted', new.journal_id using errcode = 'check_violation';
                end if;

                return case when tg_op = 'DELETE' then old else new end;
            end
            $$;

            create trigger acc_journal_lines_immutable
                before insert or update or delete on acc_journal_lines
                for each row execute function acc_journal_lines_immutable();

            create or replace function acc_journal_immutable() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    if old.status = 'posted' then
                        raise exception 'acc_journal_immutable: journal % is posted', old.id using errcode = 'check_violation';
                    end if;

                    return old;
                end if;

                if old.status = 'posted' and (
                    new.status is distinct from old.status or new.company_id is distinct from old.company_id
                    or new.date is distinct from old.date or new.period_id is distinct from old.period_id
                    or new.number is distinct from old.number or new.kind is distinct from old.kind
                    or new.source_type is distinct from old.source_type or new.source_id is distinct from old.source_id
                    or new.base_currency is distinct from old.base_currency or new.total_base_minor is distinct from old.total_base_minor
                    or new.reverses_journal_id is distinct from old.reverses_journal_id
                    or new.posted_at is distinct from old.posted_at or new.posted_by is distinct from old.posted_by
                    or (old.reversed_by_journal_id is not null and new.reversed_by_journal_id is distinct from old.reversed_by_journal_id)
                ) then
                    raise exception 'acc_journal_immutable: journal % is posted', old.id using errcode = 'check_violation';
                end if;

                return new;
            end
            $$;

            create trigger acc_journal_immutable
                before update or delete on acc_journals
                for each row execute function acc_journal_immutable();
            SQL);

        // ACC-08: nothing is posted into a closed period (the row lock waits for a concurrent close).
        DB::unprepared(<<<'SQL'
            create or replace function acc_journal_period_open() returns trigger language plpgsql as $$
            declare
                p record;
            begin
                if new.status <> 'posted' or (tg_op = 'UPDATE' and old.status = 'posted') then
                    return new;
                end if;

                select company_id, starts_on, ends_on, status into p from acc_periods where id = new.period_id for share;

                if p is null or p.company_id <> new.company_id or new.date < p.starts_on or new.date > p.ends_on then
                    raise exception 'acc_journal_period_mismatch: journal % is not in period %', new.id, new.period_id using errcode = 'check_violation';
                end if;

                -- Nothing is dated before the start of the latest closed period (back-dating).
                if p.status <> 'open' or exists (select 1 from acc_periods c where c.company_id = new.company_id and c.status = 'closed' and c.starts_on > new.date) then
                    raise exception 'acc_period_closed: journal % is dated in a closed period', new.id using errcode = 'check_violation';
                end if;

                return new;
            end
            $$;

            create trigger acc_journal_period_open
                before insert or update on acc_journals
                for each row execute function acc_journal_period_open();
            SQL);

        // ACC-07: posting rules (configuration, not code).
        Schema::create('acc_posting_rules', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 60);
            $table->string('description', 255)->nullable();
            $table->jsonb('lines');
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("alter table acc_posting_rules add constraint acc_posting_rules_lines_check check (jsonb_typeof(lines) = 'array')");
        DB::statement('create unique index acc_posting_rules_one_active on acc_posting_rules (company_id, event_type) where archived_at is null');
        Rls::enable('acc_posting_rules');

        // ACC-07: sources that could not post yet, kept to retry.
        Schema::create('acc_posting_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 60);
            $table->uuid('source_id');
            $table->jsonb('payload');
            $table->string('error_code', 40);
            $table->text('error_message')->nullable();
            $table->integer('attempts')->default(1);
            $table->string('status', 10)->default('open');
            $table->foreignUuid('journal_id')->nullable()->constrained('acc_journals')->restrictOnDelete();
            $table->timestampTz('last_attempt_at');
            $table->timestampsTz();

            $table->unique(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("alter table acc_posting_exceptions add constraint acc_posting_exceptions_status_check check (status in ('open', 'resolved'))");
        Rls::enable('acc_posting_exceptions');

        // ACC-09: customer and supplier invoices and credit notes.
        Schema::create('acc_documents', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('number', 80)->nullable();
            $table->string('supplier_reference', 80)->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->char('currency', 3);
            $table->boolean('tax_inclusive')->default(false);
            $table->fxSnapshot('fx');
            $table->char('base_currency', 3)->nullable();
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('base_subtotal_minor')->default(0);
            $table->bigInteger('base_tax_minor')->default(0);
            $table->bigInteger('base_total_minor')->default(0);
            $table->bigInteger('open_minor')->default(0);
            $table->string('status', 10)->default('draft');
            $table->uuid('credits_document_id')->nullable();
            $table->foreignUuid('journal_id')->nullable()->constrained('acc_journals')->restrictOnDelete();
            $table->text('memo')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $userRef($table, 'posted_by');
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['company_id', 'kind', 'status']);
            $table->index(['party_id']);
        });

        DB::statement("alter table acc_documents add constraint acc_documents_kind_check check (kind in ('customer_invoice', 'customer_credit', 'supplier_invoice', 'supplier_credit'))");
        DB::statement("alter table acc_documents add constraint acc_documents_status_check check (status in ('draft', 'posted', 'cancelled'))");
        DB::statement('alter table acc_documents add constraint acc_documents_amounts_check check (subtotal_minor >= 0 and tax_minor >= 0 and total_minor >= 0 and open_minor >= 0 and open_minor <= total_minor and due_date >= issue_date)');
        DB::statement("alter table acc_documents add constraint acc_documents_posted_check check ((status = 'posted') = (journal_id is not null and number is not null and posted_at is not null))");
        DB::statement('create unique index acc_documents_number_unique on acc_documents (company_id, kind, number) where number is not null');
        DB::statement('alter table acc_documents add constraint acc_documents_credits_fk foreign key (credits_document_id) references acc_documents (id) on delete restrict');
        Rls::enable('acc_documents');

        Schema::create('acc_document_lines', function (Blueprint $table) use ($dimensions) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('document_id')->constrained('acc_documents')->restrictOnDelete();
            $table->integer('line_no');
            $table->string('description', 255);
            $table->foreignUuid('item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->foreignUuid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->bigInteger('unit_price_minor');
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate', 7, 4)->nullable();
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $dimensions($table);
            $table->timestampsTz();

            $table->unique(['document_id', 'line_no']);
        });

        DB::statement('alter table acc_document_lines add constraint acc_document_lines_amounts_check check (qty > 0 and unit_price_minor >= 0 and net_minor >= 0 and tax_minor >= 0 and total_minor >= 0)');
        Rls::enable('acc_document_lines');

        // ACC-12: bank, mobile money and cash accounts.
        Schema::create('acc_bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('kind', 15);
            $table->foreignUuid('account_id')->unique()->constrained('acc_accounts')->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('bank_name', 120)->nullable();
            $table->string('account_number', 40)->nullable();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        DB::statement("alter table acc_bank_accounts add constraint acc_bank_accounts_kind_check check (kind in ('bank', 'mobile_money', 'cash'))");
        Rls::enable('acc_bank_accounts');

        // ACC-10: receipts from customers and payments to suppliers.
        Schema::create('acc_payments', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignUuid('bank_account_id')->constrained('acc_bank_accounts')->restrictOnDelete();
            $table->string('number', 80);
            $table->date('date');
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->fxSnapshot('fx');
            $table->char('base_currency', 3);
            $table->bigInteger('base_amount_minor');
            $table->bigInteger('unallocated_minor');
            $table->string('reference', 80)->nullable();
            $table->text('memo')->nullable();
            $table->string('status', 10)->default('posted');
            $table->foreignUuid('journal_id')->constrained('acc_journals')->restrictOnDelete();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['company_id', 'kind', 'number']);
            $table->index(['party_id']);
        });

        DB::statement("alter table acc_payments add constraint acc_payments_kind_check check (kind in ('receipt', 'payment'))");
        DB::statement("alter table acc_payments add constraint acc_payments_status_check check (status in ('posted'))");
        DB::statement('alter table acc_payments add constraint acc_payments_amounts_check check (amount_minor > 0 and unallocated_minor >= 0 and unallocated_minor <= amount_minor and base_amount_minor >= 0)');
        Rls::enable('acc_payments');

        // ACC-10: what a receipt, payment or credit note settles.
        Schema::create('acc_allocations', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained('acc_payments')->restrictOnDelete();
            $table->foreignUuid('credit_document_id')->nullable()->constrained('acc_documents')->restrictOnDelete();
            $table->foreignUuid('document_id')->constrained('acc_documents')->restrictOnDelete();
            $table->date('date');
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('base_at_document_minor');
            $table->bigInteger('base_at_source_minor');
            $table->bigInteger('fx_difference_minor')->default(0);
            $table->foreignUuid('journal_id')->nullable()->constrained('acc_journals')->restrictOnDelete();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->index(['document_id']);
        });

        DB::statement('alter table acc_allocations add constraint acc_allocations_source_check check ((payment_id is null) <> (credit_document_id is null) and amount_minor > 0)');
        Rls::enable('acc_allocations');

        // ACC-13: imported statements and their lines.
        Schema::create('acc_bank_statements', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bank_account_id')->constrained('acc_bank_accounts')->restrictOnDelete();
            $table->string('format', 10);
            $table->string('file_name', 255);
            $table->integer('line_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $userRef($table, 'imported_by');
            $table->timestampsTz();
        });

        DB::statement("alter table acc_bank_statements add constraint acc_bank_statements_format_check check (format in ('csv', 'mpesa'))");
        Rls::enable('acc_bank_statements');

        Schema::create('acc_bank_statement_lines', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bank_account_id')->constrained('acc_bank_accounts')->restrictOnDelete();
            $table->foreignUuid('statement_id')->constrained('acc_bank_statements')->restrictOnDelete();
            $table->date('date');
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('balance_minor')->nullable();
            $table->string('status', 10)->default('unmatched');
            $table->string('fingerprint', 64);
            $table->timestampTz('matched_at')->nullable();
            $userRef($table, 'matched_by');
            $table->timestampsTz();

            $table->unique(['bank_account_id', 'fingerprint']);
            $table->index(['bank_account_id', 'status', 'date']);
        });

        DB::statement("alter table acc_bank_statement_lines add constraint acc_bank_statement_lines_status_check check (status in ('unmatched', 'matched'))");
        DB::statement('alter table acc_bank_statement_lines add constraint acc_bank_statement_lines_amount_check check (amount_minor <> 0)');
        Rls::enable('acc_bank_statement_lines');

        // ACC-14: statement lines matched to ledger lines (the ledger is never touched).
        Schema::create('acc_bank_matches', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('statement_line_id')->constrained('acc_bank_statement_lines')->restrictOnDelete();
            $table->foreignUuid('journal_line_id')->constrained('acc_journal_lines')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $userRef($table, 'matched_by');
            $table->timestampTz('unmatched_at')->nullable();
            $userRef($table, 'unmatched_by');
            $table->timestampsTz();
        });

        DB::statement('create unique index acc_bank_matches_line_once on acc_bank_matches (journal_line_id) where unmatched_at is null');
        Rls::enable('acc_bank_matches');

        Schema::create('acc_bank_rules', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bank_account_id')->nullable()->constrained('acc_bank_accounts')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('contains', 100);
            $table->string('direction', 5)->default('any');
            $table->foreignUuid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("alter table acc_bank_rules add constraint acc_bank_rules_direction_check check (direction in ('in', 'out', 'any'))");
        Rls::enable('acc_bank_rules');

        // ACC-16: budgets in base currency, per account, cost centre and month.
        Schema::create('acc_budgets', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->integer('fiscal_year');
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });
        Rls::enable('acc_budgets');

        Schema::create('acc_budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('budget_id')->constrained('acc_budgets')->restrictOnDelete();
            $table->foreignUuid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->smallInteger('month');
            $table->bigInteger('amount_minor');
            $table->timestampsTz();
        });

        DB::statement('alter table acc_budget_lines add constraint acc_budget_lines_month_check check (month between 1 and 12)');
        DB::statement('create unique index acc_budget_lines_cell on acc_budget_lines (budget_id, account_id, cost_centre_id, month) nulls not distinct');
        Rls::enable('acc_budget_lines');

        // ACC-15: revaluation runs.
        Schema::create('acc_revaluations', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('period_id')->constrained('acc_periods')->restrictOnDelete();
            $table->date('as_of');
            $table->jsonb('rates');
            $table->jsonb('items');
            $table->foreignUuid('journal_id')->nullable()->constrained('acc_journals')->restrictOnDelete();
            $table->foreignUuid('reversal_journal_id')->nullable()->constrained('acc_journals')->restrictOnDelete();
            $userRef($table, 'run_by');
            $table->timestampsTz();
        });
        Rls::enable('acc_revaluations');
    }

    public function down(): void
    {
        foreach (['acc_revaluations', 'acc_budget_lines', 'acc_budgets', 'acc_bank_rules', 'acc_bank_matches', 'acc_bank_statement_lines',
            'acc_bank_statements', 'acc_allocations', 'acc_payments', 'acc_bank_accounts', 'acc_document_lines', 'acc_documents',
            'acc_posting_exceptions', 'acc_posting_rules'] as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('alter table acc_periods drop constraint if exists acc_periods_closing_journal_fk');
        Schema::dropIfExists('acc_journal_lines');
        Schema::dropIfExists('acc_journals');
        DB::unprepared('drop function if exists acc_journal_balanced() cascade; drop function if exists acc_journal_lines_immutable() cascade; drop function if exists acc_journal_immutable() cascade; drop function if exists acc_journal_period_open() cascade;');

        DB::unprepared('drop function if exists public.app_tenants_with_due_recurring_journals(date)');

        foreach (['acc_recurring_journals', 'acc_periods', 'acc_settings', 'acc_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
