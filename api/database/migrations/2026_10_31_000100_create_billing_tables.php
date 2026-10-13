<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SUB-02..SUB-07, LIM-05 (ADR 016): subscriptions and the platform's own
// billing.
//
// Customer side (tenant tables, RLS):
// - subscriptions: one per tenant; the plan (modules, quantities, cycle,
//   bundle), the catalogue price rows it was sold at (locked for the
//   period), its status and period, downgrades waiting for the period end
//   and the dunning state.
// - billing_invoices: the tenant's read model of the invoices the
//   operator issued to it (header and lines copied by the billing service
//   in the tenant's own context; never read across tenants at runtime).
// - billing_payment_methods: saved payment methods (a wallet phone number,
//   or a card token from the hosted checkout; never card data).
// - billing_payments: the tenant's view of each payment of its invoices.
// - tenant_modules gains switched_off_at and purge_flagged_at (SUB-07).
//
// Operator side (tenant tables of the operator tenant, RLS):
// - tenants.is_operator flags the one internal tenant whose companies sell
//   the platform (KE, CD).
// - operator_invoices: the invoices it issues, fiscal source documents of
//   its companies (eTIMS, DGI through the fiscal queue).
// - operator_accounts: its record of each customer (plan, status, MRR,
//   trial end) for the platform console.
//
// Global: platform_settings (grace period, POS window, trial days), staff-edited.
//
// Security-definer functions (ADR 002) give the scheduler and the billing
// service tenant ids only: the operator tenant, the tenants with billing
// work due, and the tenants with switched-off modules due for purge.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_operator')->default(false);
        });
        DB::statement('create unique index tenants_one_operator on tenants (is_operator) where is_operator');

        // SUB-04: a hosted card checkout (or a saved card charged) is a payment intent mode.
        DB::statement('alter table payment_intents drop constraint payment_intents_mode_check');
        DB::statement("alter table payment_intents add constraint payment_intents_mode_check check (mode in ('direct', 'stk', 'manual', 'payout', 'checkout'))");

        Schema::table('tenant_modules', function (Blueprint $table) {
            $table->timestampTz('switched_off_at')->nullable();
            $table->timestampTz('purge_flagged_at')->nullable();
        });

        $userRef = function (Blueprint $table, string $column): void {
            $table->uuid($column)->nullable();
            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };

        Schema::create('billing_payment_methods', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('kind', 20);
            $table->string('provider', 40);
            $table->string('label', 60);
            $table->text('phone')->nullable();
            $table->text('token')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('auto_renew')->default(false);
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("alter table billing_payment_methods add constraint billing_payment_methods_kind_check check (kind in ('mobile_money', 'card'))");
        DB::statement('create unique index billing_payment_methods_one_default on billing_payment_methods (tenant_id) where is_default and archived_at is null');
        Rls::enable('billing_payment_methods');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->char('country', 2);
            $table->char('currency', 3);
            $table->string('cycle', 10);
            $table->string('bundle', 60)->nullable();
            $table->jsonb('modules')->default('[]');
            $table->jsonb('quantities')->default('{}');
            // "module:key" / "bundle:key" => catalogue_prices.id the period is priced at.
            $table->jsonb('price_ids')->default('{}');
            $table->uuid('coupon_id')->nullable();
            $table->string('coupon_code', 40)->nullable();
            // The last day of the period the coupon applies to.
            $table->date('coupon_until')->nullable();
            $table->string('status', 12);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->bigInteger('period_amount_minor')->default(0);
            $table->bigInteger('monthly_amount_minor')->default(0);
            $table->jsonb('pending_change')->nullable();
            $table->timestampTz('past_due_since')->nullable();
            $table->timestampTz('read_only_since')->nullable();
            $table->timestampTz('pos_stops_at')->nullable();
            // Staff override of the platform grace period, in days.
            $table->integer('grace_days')->nullable();
            $table->jsonb('reminders_sent')->default('[]');
            // LIM-05: usage overage is charged for periods ending on or before this date (once each).
            $table->date('overage_billed_through')->nullable();
            $table->uuid('default_payment_method_id')->nullable();
            $table->timestampsTz();

            $table->unique('tenant_id');
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('coupon_id')->references('id')->on('catalogue_coupons')->restrictOnDelete();
            $table->foreign(['tenant_id', 'default_payment_method_id'])->references(['tenant_id', 'id'])->on('billing_payment_methods')->restrictOnDelete();
        });
        DB::statement("alter table subscriptions add constraint subscriptions_status_check check (status in ('trial', 'active', 'past_due', 'read_only', 'suspended', 'cancelled'))");
        DB::statement("alter table subscriptions add constraint subscriptions_cycle_check check (cycle in ('monthly', 'annual'))");
        DB::statement('alter table subscriptions add constraint subscriptions_period_check check ((current_period_start is null) = (current_period_end is null) and (current_period_end is null or current_period_end > current_period_start))');
        DB::statement('alter table subscriptions add constraint subscriptions_grace_check check (grace_days is null or grace_days between 0 and 90)');
        Rls::enable('subscriptions');

        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            // The operator's invoice this row copies (another tenant's id; never joined).
            $table->uuid('operator_invoice_id')->unique();
            $table->string('number', 80);
            $table->string('kind', 10);
            $table->string('status', 10);
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->date('period_start');
            $table->date('period_end');
            $table->timestampTz('issued_at');
            $table->timestampTz('due_at');
            $table->timestampTz('paid_at')->nullable();
            $table->jsonb('lines');
            // The seller (the operator's company): name, legal name, tax id, address, country.
            $table->jsonb('seller');
            $table->jsonb('customer');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'issued_at']);
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });
        DB::statement("alter table billing_invoices add constraint billing_invoices_status_check check (status in ('open', 'paid', 'void'))");
        DB::statement("alter table billing_invoices add constraint billing_invoices_kind_check check (kind in ('first', 'renewal', 'upgrade'))");
        Rls::enable('billing_invoices');

        Schema::create('billing_payments', function (Blueprint $table) use ($userRef) {
            // The operator's payment intent id (another tenant's id; never joined).
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('billing_invoice_id');
            $table->uuid('payment_method_id')->nullable();
            $table->string('provider', 40);
            $table->string('label', 60);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('status', 12);
            $table->string('redirect_url', 500)->nullable();
            $table->string('message', 255)->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'billing_invoice_id'])->references(['tenant_id', 'id'])->on('billing_invoices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_method_id'])->references(['tenant_id', 'id'])->on('billing_payment_methods')->restrictOnDelete();
            $table->index(['tenant_id', 'billing_invoice_id']);
        });
        Rls::enable('billing_payments');

        Schema::create('operator_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_tenant_id')->constrained('tenants')->restrictOnDelete();
            // The customer's subscription (another tenant's id; never joined).
            $table->uuid('subscription_id');
            $table->string('number', 80);
            $table->string('kind', 10);
            $table->string('status', 10);
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->uuid('tax_code_id')->nullable();
            $table->decimal('tax_rate', 7, 4)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->timestampTz('issued_at');
            $table->timestampTz('due_at');
            $table->timestampTz('paid_at')->nullable();
            // `intent` (a payment intent id) or `bank_transfer` (marked by staff).
            $table->string('paid_via', 20)->nullable();
            $table->string('payment_reference', 100)->nullable();
            // SUB-04: money received for the invoice once it was already paid, for staff to refund.
            $table->jsonb('overpayments')->default('[]');
            $table->jsonb('lines');
            $table->jsonb('customer');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'customer_tenant_id', 'issued_at']);
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });
        DB::statement("alter table operator_invoices add constraint operator_invoices_status_check check (status in ('open', 'paid', 'void'))");
        DB::statement("alter table operator_invoices add constraint operator_invoices_kind_check check (kind in ('first', 'renewal', 'upgrade'))");
        // One invoice per subscription period start for the first and renewal invoices (the cycle may run twice).
        DB::statement("create unique index operator_invoices_one_per_period on operator_invoices (tenant_id, subscription_id, period_start) where kind in ('first', 'renewal') and status <> 'void'");
        Rls::enable('operator_invoices');

        Schema::create('operator_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('customer_tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name');
            $table->char('country', 2);
            $table->char('currency', 3);
            $table->string('plan', 255);
            $table->string('cycle', 10);
            $table->string('status', 12);
            $table->bigInteger('mrr_minor')->default(0);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->date('current_period_end')->nullable();
            $table->integer('grace_days')->nullable();
            // SUB-07: switched-off modules whose data is due for purge (a report; nothing is deleted).
            $table->jsonb('purge_due')->default('[]');
            // What staff must look at (an overpayment to refund).
            $table->jsonb('alerts')->default('[]');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'customer_tenant_id']);
        });
        Rls::enable('operator_accounts');

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->jsonb('value');
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('updated_by')->references('id')->on('platform_users')->restrictOnDelete();
        });

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        $platformRole = '"'.str_replace('"', '""', config('database.connections.pgsql_platform.username') ?: 'app_platform').'"';
        // ADR 013: staff write platform settings on their own connection; the tenant runtime only reads them (dunning).
        DB::statement("revoke all on platform_settings from {$runtimeRole}");
        DB::statement("grant select on platform_settings to {$runtimeRole}");
        DB::statement("grant select, insert, update on platform_settings to {$platformRole}");

        foreach (self::FUNCTIONS as $signature => $body) {
            $name = strstr($signature, '(', true);
            $types = $this->types($signature);

            DB::unprepared(<<<SQL
                create or replace function public.{$signature} returns setof uuid
                language sql stable security definer
                set search_path = pg_catalog, public
                as \$fn\$
                {$body}
                \$fn\$;

                revoke all on function public.{$name}({$types}) from public;
                SQL);

            DB::statement("grant execute on function public.{$name}({$types}) to {$runtimeRole}");
        }
    }

    /** @var array<string, string> signature => body */
    private const FUNCTIONS = [
        // The operator tenant (one row at most): the seller of the platform.
        'app_operator_tenant_id()' => <<<'SQL'
            select t.id from public.tenants t
            where t.is_operator
            limit 1
            SQL,
        // SUB-03, SUB-05: subscriptions with billing work at p_at: a trial or
        // period ending within 7 days (reminders, invoices, renewals), or a
        // subscription past due or read-only (grace, POS window), or an open
        // invoice already due (it turns past due).
        'app_tenants_with_billing_due(p_at timestamptz)' => <<<'SQL'
            select s.tenant_id from public.subscriptions s
            join public.tenants t on t.id = s.tenant_id
            where t.status = 'active' and not t.is_operator
              and s.status in ('trial', 'active', 'past_due', 'read_only')
              and (
                (s.status = 'trial' and s.current_period_start is null and s.trial_ends_at <= p_at + interval '7 days')
                or (s.current_period_end is not null and s.current_period_end <= (p_at + interval '7 days')::date)
                or s.status in ('past_due', 'read_only')
                or exists (select 1 from public.billing_invoices i
                           where i.tenant_id = s.tenant_id and i.status = 'open' and i.due_at <= p_at)
              )
            order by 1
            SQL,
        // SUB-07: modules switched off before p_before whose data has not been flagged for purge yet.
        'app_tenants_with_modules_to_purge(p_before timestamptz)' => <<<'SQL'
            select distinct m.tenant_id from public.tenant_modules m
            where m.status = 'inactive'
              and m.switched_off_at is not null and m.switched_off_at <= p_before
              and m.purge_flagged_at is null
            order by 1
            SQL,
    ];

    private function types(string $signature): string
    {
        $params = trim(substr($signature, strpos($signature, '(') + 1), ') ');

        if ($params === '') {
            return '';
        }

        return implode(', ', array_map(fn (string $p) => trim(substr(trim($p), strpos(trim($p), ' ') + 1)), explode(',', $params)));
    }

    public function down(): void
    {
        foreach (array_keys(self::FUNCTIONS) as $signature) {
            DB::statement('drop function if exists public.'.strstr($signature, '(', true).'('.$this->types($signature).')');
        }

        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('operator_accounts');
        Schema::dropIfExists('operator_invoices');
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('billing_payment_methods');

        Schema::table('tenant_modules', function (Blueprint $table) {
            $table->dropColumn(['switched_off_at', 'purge_flagged_at']);
        });

        DB::statement('alter table payment_intents drop constraint payment_intents_mode_check');
        DB::statement("alter table payment_intents add constraint payment_intents_mode_check check (mode in ('direct', 'stk', 'manual', 'payout'))");

        DB::statement('drop index if exists tenants_one_operator');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('is_operator');
        });
    }
};
