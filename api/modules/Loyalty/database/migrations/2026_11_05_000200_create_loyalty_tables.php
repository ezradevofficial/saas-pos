<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Loyalty module (docs/modules/loyalty.md). Every table is the tenant's,
// under forced row-level security (TEN-01). Amounts are bigint minor
// units with their currency (ADR 003); points are bigint whole points.
// The points, credit and gift card ledgers and the promotion versions are
// append-only (a trigger refuses update and delete). POS sales are named
// by their id only: this module never references POS tables (CLAUDE.md,
// modular monolith). Users are referenced by (tenant_id, id).
return new class extends Migration
{
    private const LEDGERS = ['loyalty_points_entries', 'loyalty_credit_entries', 'loyalty_gift_card_entries', 'loyalty_promotion_versions'];

    public function up(): void
    {
        $userRef = function (Blueprint $table, string $column, bool $nullable = true): void {
            $definition = $table->uuid($column);

            if ($nullable) {
                $definition->nullable();
            }

            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };

        // LOY-01: one programme per company.
        Schema::create('loyalty_programmes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('active')->default(false);
            $table->char('currency', 3);
            $table->integer('earn_points')->default(1);
            $table->bigInteger('earn_spend_minor');
            $table->bigInteger('point_value_minor');
            $table->bigInteger('min_redeem_points')->default(0);
            $table->integer('expiry_months')->nullable();
            $table->integer('expiry_notice_days')->default(14);
            $table->integer('credit_warning_percent')->default(80);
            $table->bigInteger('gift_card_low_minor')->nullable();
            $table->foreignUuid('gift_card_item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        DB::statement('alter table loyalty_programmes add constraint loyalty_programmes_values_check check (earn_points >= 0 and earn_spend_minor > 0 and point_value_minor > 0 and min_redeem_points >= 0
            and (expiry_months is null or expiry_months between 1 and 120) and expiry_notice_days between 0 and 365
            and credit_warning_percent between 1 and 100 and (gift_card_low_minor is null or gift_card_low_minor >= 0))');
        Rls::enable('loyalty_programmes');

        // LOY-02, LOY-14: a customer's points or credit account at a company (synced to tills).
        Schema::create('loyalty_accounts', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('kind', 10);
            $table->char('currency', 3)->nullable();
            $table->bigInteger('balance')->default(0);
            $table->string('status', 10);
            $table->timestampTz('opened_at');
            $userRef($table, 'opened_by');
            $table->timestampTz('closed_at')->nullable();
            $userRef($table, 'closed_by');
            $table->bigInteger('sync_xid')->default(0);
            $table->bigInteger('sync_seq')->default(0);
            $table->timestampsTz();

            $table->unique(['company_id', 'party_id', 'kind']);
            $table->index('party_id');
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        DB::statement("alter table loyalty_accounts add constraint loyalty_accounts_kind_check check ((kind = 'points' and currency is null) or (kind = 'credit' and currency is not null))");
        DB::statement("alter table loyalty_accounts add constraint loyalty_accounts_status_check check (status in ('open', 'closed') and (status = 'closed') = (closed_at is not null))");
        DB::statement('create trigger sync_stamp before insert or update on loyalty_accounts for each row execute function sync_stamp()');
        DB::statement('create index loyalty_accounts_sync_cursor on loyalty_accounts (tenant_id, sync_xid, sync_seq)');
        Rls::enable('loyalty_accounts');

        // LOY-02..LOY-07: the points ledger.
        Schema::create('loyalty_points_entries', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('account_id')->constrained('loyalty_accounts')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('points');
            // An earn lot's expiry (LOY-06).
            $table->timestampTz('expires_at')->nullable();
            $table->string('source_type', 20);
            $table->uuid('source_id')->nullable();
            // The POS sale it came from, by id only.
            $table->uuid('sale_id')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('flags')->default('[]');
            $userRef($table, 'created_by');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['account_id', 'occurred_at']);
            $table->index('sale_id');
        });

        DB::statement("alter table loyalty_points_entries add constraint loyalty_points_entries_kind_check check (kind in ('earn', 'redeem', 'reverse_earn', 'reverse_redeem', 'refund_credit', 'expire', 'adjust') and points <> 0)");
        DB::statement('create unique index loyalty_points_entries_once on loyalty_points_entries (account_id, kind, source_type, source_id) where source_id is not null');
        Rls::enable('loyalty_points_entries');

        // LOY-14..LOY-16: the credit ledger (positive: the customer owes more).
        Schema::create('loyalty_credit_entries', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('account_id')->constrained('loyalty_accounts')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('occurred_at', 6);
            $table->string('source_type', 20);
            $table->uuid('source_id')->nullable();
            $table->uuid('sale_id')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->jsonb('flags')->default('[]');
            $userRef($table, 'created_by');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['account_id', 'occurred_at']);
            $table->index('sale_id');
        });

        DB::statement("alter table loyalty_credit_entries add constraint loyalty_credit_entries_kind_check check (kind in ('charge', 'payment', 'reverse_charge', 'refund_credit', 'adjust') and amount_minor <> 0)");
        DB::statement('create unique index loyalty_credit_entries_once on loyalty_credit_entries (account_id, kind, source_type, source_id) where source_id is not null');
        Rls::enable('loyalty_credit_entries');

        // LOY-08, LOY-09: promotions, their versions and coupons.
        Schema::create('loyalty_promotions', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->string('status', 10);
            $table->integer('priority')->default(0);
            $table->integer('version')->default(1);
            $table->boolean('requires_coupon')->default(false);
            $table->jsonb('definition');
            $userRef($table, 'created_by');
            $userRef($table, 'updated_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'status']);
        });

        DB::statement("alter table loyalty_promotions add constraint loyalty_promotions_check check (type in ('percent', 'amount_off', 'buy_x_get_y', 'bundle') and status in ('draft', 'active', 'archived') and version >= 1 and jsonb_typeof(definition) = 'object')");
        Rls::enable('loyalty_promotions');

        Schema::create('loyalty_promotion_versions', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('promotion_id')->constrained('loyalty_promotions')->restrictOnDelete();
            $table->integer('version');
            $table->string('status', 10);
            $table->jsonb('definition');
            $table->timestampTz('effective_at', 6);
            $userRef($table, 'created_by');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['promotion_id', 'version']);
            $table->index(['promotion_id', 'effective_at']);
        });

        Rls::enable('loyalty_promotion_versions');

        Schema::create('loyalty_coupons', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('promotion_id')->constrained('loyalty_promotions')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->integer('max_uses')->nullable();
            $table->integer('uses')->default(0);
            $userRef($table, 'created_by');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->index('promotion_id');
        });

        DB::statement('alter table loyalty_coupons add constraint loyalty_coupons_check check ((max_uses is null or max_uses >= 1) and uses >= 0)');
        Rls::enable('loyalty_coupons');

        // LOY-03, LOY-12: Loyalty's own copy of each POS sale it handled (POS-13 snapshot).
        Schema::create('loyalty_sales', function (Blueprint $table) {
            // The POS sale's id (no foreign key: another module's table).
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('receipt_number', 80);
            $table->char('currency', 3);
            $table->bigInteger('total_minor');
            $table->timestampTz('sold_at', 6);
            $table->string('status', 10);
            $table->bigInteger('points_earned')->default(0);
            $table->bigInteger('points_reversed')->default(0);
            $table->bigInteger('refunded_minor')->default(0);
            $table->jsonb('flags')->default('[]');
            $table->jsonb('snapshot');
            $table->text('error')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            // The POS void's id (by id only), also when it arrived before processing (H1).
            $table->uuid('void_id')->nullable();
            // Refund snapshots that arrived before processing, applied after it (H1).
            $table->jsonb('pending_refunds')->default('[]');
            // Refunds applied, so a replayed SaleRefunded changes nothing.
            $table->jsonb('refund_ids')->default('[]');
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['company_id', 'sold_at']);
            $table->index('party_id');
        });

        DB::statement("alter table loyalty_sales add constraint loyalty_sales_status_check check (status in ('pending', 'processed', 'failed'))");
        Rls::enable('loyalty_sales');

        Schema::create('loyalty_promotion_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('sale_id')->constrained('loyalty_sales')->restrictOnDelete();
            $table->foreignUuid('promotion_id')->nullable()->constrained('loyalty_promotions')->restrictOnDelete();
            // What the till named, kept as sent (an unknown promotion has no row).
            $table->string('reference', 100);
            $table->integer('version')->nullable();
            $table->bigInteger('applied_minor');
            $table->bigInteger('expected_minor');
            $table->string('coupon', 40)->nullable();
            $table->jsonb('flags')->default('[]');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('sale_id');
            $table->index('promotion_id');
        });

        Rls::enable('loyalty_promotion_applications');

        // LOY-20..LOY-24: gift cards and vouchers (synced to tills, hashes only).
        Schema::create('loyalty_gift_cards', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->char('code_hash', 64);
            $table->char('last4', 4);
            $table->char('currency', 3);
            $table->bigInteger('initial_minor');
            $table->bigInteger('balance_minor')->default(0);
            $table->string('status', 10);
            $table->date('expires_on')->nullable();
            $table->foreignUuid('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('sold_reference', 100)->nullable();
            $userRef($table, 'issued_by');
            $table->timestampTz('issued_at');
            $table->timestampTz('activated_at')->nullable();
            $table->bigInteger('sync_xid')->default(0);
            $table->bigInteger('sync_seq')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code_hash']);
            $table->index(['company_id', 'status']);
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        DB::statement("alter table loyalty_gift_cards add constraint loyalty_gift_cards_check check (kind in ('gift_card', 'voucher') and status in ('inactive', 'active', 'blocked') and initial_minor > 0)");
        DB::statement('create trigger sync_stamp before insert or update on loyalty_gift_cards for each row execute function sync_stamp()');
        DB::statement('create index loyalty_gift_cards_sync_cursor on loyalty_gift_cards (tenant_id, sync_xid, sync_seq)');
        Rls::enable('loyalty_gift_cards');

        Schema::create('loyalty_gift_card_entries', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('gift_card_id')->constrained('loyalty_gift_cards')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('source_type', 20);
            $table->uuid('source_id')->nullable();
            $table->uuid('sale_id')->nullable();
            $table->string('reference', 100)->nullable();
            $table->jsonb('flags')->default('[]');
            $userRef($table, 'created_by');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['gift_card_id', 'occurred_at']);
        });

        DB::statement("alter table loyalty_gift_card_entries add constraint loyalty_gift_card_entries_kind_check check (kind in ('load', 'redeem', 'reverse_redeem', 'refund_credit', 'adjust') and amount_minor <> 0)");
        DB::statement('create unique index loyalty_gift_card_entries_once on loyalty_gift_card_entries (gift_card_id, kind, source_type, source_id) where source_id is not null');
        Rls::enable('loyalty_gift_card_entries');

        // LOY-17: stored statements.
        Schema::create('loyalty_statements', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('account_id')->constrained('loyalty_accounts')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('number', 60);
            $table->date('period_from');
            $table->date('period_to');
            $table->char('currency', 3);
            $table->bigInteger('opening_minor');
            $table->bigInteger('closing_minor');
            $table->jsonb('ageing');
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index('account_id');
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });

        DB::statement('alter table loyalty_statements add constraint loyalty_statements_period_check check (period_from <= period_to)');
        Rls::enable('loyalty_statements');

        // LOY-06, LOY-19, LOY-24: notices sent once.
        Schema::create('loyalty_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('kind', 40);
            $table->uuid('subject_id');
            $table->string('key', 80);
            $table->timestampTz('sent_at');

            $table->unique(['tenant_id', 'kind', 'subject_id', 'key']);
        });

        Rls::enable('loyalty_notices');

        // Ledgers and promotion versions are append-only.
        DB::unprepared(<<<'SQL'
            create or replace function loyalty_append_only() returns trigger
            language plpgsql as $$
            begin
                raise exception '% is append-only', tg_table_name using errcode = 'insufficient_privilege';
            end;
            $$;
            SQL);

        foreach (self::LEDGERS as $table) {
            DB::statement("create trigger {$table}_append_only before update or delete on {$table} for each row execute function loyalty_append_only()");
        }
    }

    public function down(): void
    {
        foreach (['loyalty_notices', 'loyalty_statements', 'loyalty_gift_card_entries', 'loyalty_gift_cards', 'loyalty_promotion_applications', 'loyalty_sales',
            'loyalty_coupons', 'loyalty_promotion_versions', 'loyalty_promotions', 'loyalty_credit_entries', 'loyalty_points_entries', 'loyalty_accounts', 'loyalty_programmes'] as $table) {
            Schema::dropIfExists($table);
        }

        DB::unprepared('drop function if exists loyalty_append_only()');
    }
};
