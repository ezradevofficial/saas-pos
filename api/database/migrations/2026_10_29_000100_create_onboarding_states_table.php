<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ONB-01..ONB-08, TEN-01: one onboarding record per tenant, created at
// sign-up. It holds the business type chosen (ONB-02), the module
// selection the trial started with (ONB-03, ONB-04; billing reads it), the
// setup wizard's progress (ONB-05), the sample data batch (ONB-06), the
// checklist's dismissal (ONB-07) and, for an assisted sign-up, the
// handover to the customer (ONB-08). Never deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // business | modules | setup | done: the screen the owner is sent back to.
            $table->string('stage', 20)->default('business');
            $table->string('business_type', 40)->nullable();
            // {modules: [...], quantities: {outlet, device, employee, ...}, cycle, bundle}
            $table->jsonb('selection')->nullable();
            // What the business type applied: {pos_mode, role_templates, flows}.
            $table->jsonb('applied')->nullable();
            // {company: 'done'|'skipped', currencies: ..., ...}
            $table->jsonb('steps')->default('{}');
            $table->timestampTz('trial_started_at')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            // {batch_id, loaded_at, loaded_by, records: {item_category: [ids], item: [...], item_price: [...], party: [...], price_list: [...]}}
            $table->jsonb('sample')->nullable();
            $table->timestampTz('checklist_dismissed_at')->nullable();
            // ONB-08: {platform_user_id, staff_name, setup_user_id, invitation_id, contact, created_at, handed_over_at, owner_id}
            $table->jsonb('assisted')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        DB::statement("alter table onboarding_states add constraint onboarding_states_stage_check check (stage in ('business', 'modules', 'setup', 'done'))");
        Rls::enable('onboarding_states');
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_states');
    }
};
