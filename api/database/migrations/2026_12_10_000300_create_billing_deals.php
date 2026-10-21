<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADM-02 (docs/modules/platform-admin.md): custom Enterprise deals. A deal
// is a negotiated price for one tenant that overrides catalogue lines, in
// force over an effective period. It lives in the customer's own tenant
// (row-level security), where the subscription engine prices with it.
// Staff draft it (sales, finance) and finance approves it; an approved deal
// is never edited, it can only be ended (a trigger binds every role).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_deals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('status', 10)->default('pending');
            $table->char('country', 2);
            $table->char('currency', 3);
            // The bundle the deal is sold with (Enterprise), informational.
            $table->string('bundle', 60)->nullable();
            // [{type: module|bundle, key, amount, extra_outlet_amount?, minimum_amount?}], minor units a month.
            $table->jsonb('lines');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('reason');
            $table->uuid('created_by_staff');
            $table->string('created_by_name', 120);
            $table->uuid('decided_by_staff')->nullable();
            $table->string('decided_by_name', 120)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('ended_by_staff')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'effective_from']);
        });

        DB::statement("alter table billing_deals add constraint billing_deals_status_check check (status in ('pending', 'approved', 'rejected', 'ended'))");
        DB::statement('alter table billing_deals add constraint billing_deals_period_check check (effective_to is null or effective_to >= effective_from)');
        DB::statement('alter table billing_deals add constraint billing_deals_approver_check check (decided_by_staff is null or decided_by_staff <> created_by_staff)');
        Rls::enable('billing_deals');

        // A decided deal never changes its terms; an approved deal may only be ended (its end date set, never later).
        DB::unprepared(<<<'SQL'
            create or replace function billing_deals_guard() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'deals are never deleted' using errcode = 'insufficient_privilege';
                end if;
                if new.tenant_id is distinct from old.tenant_id
                    or new.lines is distinct from old.lines
                    or new.currency is distinct from old.currency
                    or new.country is distinct from old.country
                    or new.effective_from is distinct from old.effective_from
                    or new.created_by_staff is distinct from old.created_by_staff then
                    raise exception 'a deal''s terms never change: draft a new deal' using errcode = 'insufficient_privilege';
                end if;
                if old.status in ('rejected', 'ended') then
                    raise exception 'a rejected or ended deal never changes' using errcode = 'insufficient_privilege';
                end if;
                if old.status = 'approved' and (new.status <> 'ended'
                        or new.effective_to is null
                        or (old.effective_to is not null and new.effective_to > old.effective_to)) then
                    raise exception 'an approved deal can only be ended' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger billing_deals_guard
                before update or delete on billing_deals
                for each row execute function billing_deals_guard();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_deals');
        DB::statement('drop function if exists billing_deals_guard()');
    }
};
