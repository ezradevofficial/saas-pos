<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Payments at the till (concept note 7.1; App\Core\Payments).
//
// payment_methods gains the provider callback token (MD-04): a random
// token in the callback URLs Safaricom calls, stored encrypted (to build
// the URLs again) and as a sha256 hash (to find the method; unique across
// tenants). A callback carries no session: the tenant is found by
// payment_tenant_for_callback_token (security definer, ADR 002), then
// everything runs under that tenant's row-level security.
//
// payment_intents: one request for money (statuses pending, unknown: the
// provider may have received the push but did not answer, succeeded,
// failed, cancelled, timeout) through a provider (an STK push
// for a sale, a manual M-Pesa code typed by the cashier, a B2C refund),
// with its status, the provider's references and the minimum of what the
// provider answered (result code and text, amount, receipt; never names).
// The phone number is stored encrypted and shown masked.
//
// payment_receipts: money the provider reports as received outside an
// intent (M-Pesa C2B confirmations on the Till or Paybill), matched to an
// open intent or left for the back office to match. Idempotent by the
// provider's transaction id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->char('callback_token_hash', 64)->nullable()->unique();
            $table->text('callback_token')->nullable();
        });

        $userRef = function (Blueprint $table, string $column): void {
            $table->uuid($column)->nullable();
            $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        };

        Schema::create('payment_intents', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('driver', 30);
            $table->string('purpose', 10);
            $table->string('mode', 10);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->text('phone')->nullable();
            // What the money is for: the module's document type and id (a
            // sale made on the till, its uuid given by the device).
            $table->string('reference_type', 40);
            $table->string('reference', 100);
            $table->string('account_reference', 20)->nullable();
            $table->string('status', 12);
            // Manual payments: unverified until the provider confirms the code.
            $table->string('verification', 12)->nullable();
            $table->string('provider_request_id', 100)->nullable();
            $table->string('provider_checkout_id', 100)->nullable();
            $table->string('provider_receipt', 40)->nullable();
            $table->string('verification_ref', 100)->nullable();
            $table->string('result_code', 40)->nullable();
            $table->string('result_message', 255)->nullable();
            $table->jsonb('provider_data')->default('{}');
            $table->uuid('original_intent_id')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('verify_after')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $userRef($table, 'created_by');
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['company_id', 'created_at']);
            $table->index(['reference_type', 'reference']);
            $table->index('payment_method_id');
        });

        Schema::table('payment_intents', function (Blueprint $table) {
            $table->foreign('original_intent_id')->references('id')->on('payment_intents')->restrictOnDelete();
        });

        DB::statement("alter table payment_intents add constraint payment_intents_purpose_check check (purpose in ('sale', 'refund'))");
        DB::statement("alter table payment_intents add constraint payment_intents_mode_check check (mode in ('direct', 'stk', 'manual', 'payout'))");
        DB::statement("alter table payment_intents add constraint payment_intents_status_check check (status in ('pending', 'unknown', 'succeeded', 'failed', 'cancelled', 'timeout'))");
        DB::statement("alter table payment_intents add constraint payment_intents_verification_check check (verification is null or verification in ('unverified', 'verified', 'mismatch'))");
        DB::statement('alter table payment_intents add constraint payment_intents_amount_check check (amount_minor > 0)');
        DB::statement("alter table payment_intents add constraint payment_intents_refund_check check (mode <> 'payout' or (purpose = 'refund' and original_intent_id is not null))");
        // Callbacks find their intent by the provider's checkout id; a receipt
        // (an M-Pesa code) pays for one sale only.
        DB::statement('create unique index payment_intents_checkout_unique on payment_intents (tenant_id, provider, provider_checkout_id) where provider_checkout_id is not null');
        DB::statement("create unique index payment_intents_receipt_unique on payment_intents (tenant_id, provider, provider_receipt) where provider_receipt is not null and purpose = 'sale' and status = 'succeeded'");
        DB::statement('create unique index payment_intents_verification_ref_unique on payment_intents (tenant_id, provider, verification_ref) where verification_ref is not null');
        DB::statement("create index payment_intents_due on payment_intents (expires_at) where status in ('pending', 'unknown')");
        DB::statement("create index payment_intents_verify_due on payment_intents (verify_after) where verification = 'unverified' and verification_ref is null");
        Rls::enable('payment_intents');

        Schema::create('payment_receipts', function (Blueprint $table) use ($userRef) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('receipt', 40);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('account_reference', 40)->nullable();
            $table->string('shortcode', 20)->nullable();
            $table->timestampTz('transacted_at')->nullable();
            $table->string('status', 10);
            // `late_or_unmatched`: a paid result whose intent was unknown or already final.
            $table->string('flag', 30)->nullable();
            $table->foreignUuid('payment_intent_id')->nullable()->constrained()->restrictOnDelete();
            $userRef($table, 'matched_by');
            $table->timestampTz('matched_at')->nullable();
            $table->jsonb('provider_data')->default('{}');
            $table->timestampsTz();

            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['tenant_id', 'provider', 'receipt']);
            $table->index(['company_id', 'status', 'created_at']);
        });

        DB::statement("alter table payment_receipts add constraint payment_receipts_status_check check (status in ('matched', 'unmatched'))");
        DB::statement("alter table payment_receipts add constraint payment_receipts_matched_check check ((status = 'matched') = (payment_intent_id is not null))");
        DB::statement('alter table payment_receipts add constraint payment_receipts_amount_check check (amount_minor > 0)');
        Rls::enable('payment_receipts');

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';

        // A provider callback has no session: find the tenant of a callback
        // token hash (nothing else), then read the method under RLS.
        DB::unprepared(<<<'SQL'
            create or replace function public.payment_tenant_for_callback_token(p_token_hash text) returns uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select m.tenant_id from public.payment_methods m
                where m.callback_token_hash = p_token_hash
                limit 1
            $$;

            revoke all on function public.payment_tenant_for_callback_token(text) from public;

            -- The scheduler (payments:process-timers) finds tenants with an STK
            -- push past its timeout, a payout past its give-up time or a manual
            -- code due for a check, before any tenant is set (ADR 002).
            create or replace function public.app_tenants_with_due_payment_intents(p_at timestamptz) returns setof uuid
            language sql stable security definer
            set search_path = pg_catalog, public
            as $$
                select t.tenant_id from (
                    select i.tenant_id from public.payment_intents i
                    where i.status in ('pending', 'unknown') and i.expires_at <= p_at
                    union
                    select i.tenant_id from public.payment_intents i
                    where i.verification = 'unverified' and i.verification_ref is null and i.verify_after <= p_at
                ) t
                order by 1
            $$;

            revoke all on function public.app_tenants_with_due_payment_intents(timestamptz) from public;
            SQL);

        DB::statement("grant execute on function public.payment_tenant_for_callback_token(text) to {$runtimeRole}");
        DB::statement("grant execute on function public.app_tenants_with_due_payment_intents(timestamptz) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.app_tenants_with_due_payment_intents(timestamptz)');
        DB::statement('drop function if exists public.payment_tenant_for_callback_token(text)');
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('payment_intents');
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['callback_token_hash']);
            $table->dropColumn(['callback_token_hash', 'callback_token']);
        });
    }
};
