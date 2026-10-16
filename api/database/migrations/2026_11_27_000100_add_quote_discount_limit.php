<?php

use App\Core\Audit\AuditContext;
use App\Core\Rbac\Models\Role;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// RBAC-06, CRM-20 (owner decision A, 2026-10-16): quotes get their own limit,
// `max_quote_discount_percent`; the till keeps `max_discount_percent`.
//
// Existing tenants keep what CRM allowed: every role holding
// `crm.quote.discount` with a till limit and no quote limit gets a quote
// limit of the same value; the system Sales Rep's till limit (seeded for
// CRM) goes. Each change is audited on its role as the system
// (`rbac.role.limits_update`, RBAC-12). The template defaults missing from
// system roles (Branch Manager: quote discount 10 %, refund 10,000) arrive with `permissions:sync`
// (RoleTemplates::refresh), which every migrate ends with.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table limit_rules drop constraint limit_rules_key_check');
        DB::statement("alter table limit_rules add constraint limit_rules_key_check check (key in ('max_discount_percent', 'max_quote_discount_percent', 'max_refund_amount', 'max_approval_amount', 'credit_limit_override'))");

        $tenantIds = DB::table('tenants')->orderBy('id')->pluck('id');

        if ($tenantIds->isEmpty()) {
            return;
        }

        app(AuditContext::class)->reset();
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(TenantContext::CONNECTION);

        try {
            foreach ($tenantIds as $tenantId) {
                app(TenantContext::class)->run($tenantId, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () {
                    $roles = Role::query()
                        ->whereHas('permissions', fn ($query) => $query->where('name', 'crm.quote.discount'))
                        ->whereHas('limitRules', fn ($query) => $query->where('key', 'max_discount_percent'))
                        ->get();

                    foreach ($roles as $role) {
                        $limits = $role->limits();
                        $changes = isset($limits['max_quote_discount_percent']) ? [] : ['max_quote_discount_percent' => $limits['max_discount_percent']];

                        if ($role->is_system && $role->template_key === 'sales_rep') {
                            $changes['max_discount_percent'] = null;
                        }

                        if ($changes !== []) {
                            $role->setLimits($changes);
                        }
                    }
                }));
            }
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    public function down(): void
    {
        DB::statement("delete from limit_rules where key = 'max_quote_discount_percent'");
        DB::statement('alter table limit_rules drop constraint limit_rules_key_check');
        DB::statement("alter table limit_rules add constraint limit_rules_key_check check (key in ('max_discount_percent', 'max_refund_amount', 'max_approval_amount', 'credit_limit_override'))");
    }
};
