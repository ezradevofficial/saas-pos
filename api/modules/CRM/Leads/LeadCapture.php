<?php

namespace Modules\CRM\Leads;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\CRM\CrmServiceProvider;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadForm;
use Modules\CRM\Support\Tx;

/**
 * CRM-06: a lead typed into a public web form. The 40-character token is
 * the credential: its tenant is found by the security-definer function
 * `crm_lead_form_tenant` (ADR 002), entered, and the form read under that
 * tenant's row-level security. An unknown token, an archived form, a
 * tenant that is not active or without the CRM module: nothing (404).
 */
class LeadCapture
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly Leads $leads,
    ) {}

    /** The form a public token names, with its tenant entered; null when it may not take leads. */
    public function form(string $token): ?LeadForm
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $tenantId = DB::selectOne('select crm_lead_form_tenant(?) as tenant_id', [$hash])?->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        $this->tenants->set($tenantId);

        if (Tenant::query()->whereKey($tenantId)->value('status') !== 'active' || ! $this->modules->isActive(CrmServiceProvider::MODULE)) {
            return null;
        }

        return LeadForm::query()->where('token_hash', $hash)->whereNull('archived_at')->first();
    }

    /** @param array<string, mixed> $values the form's fields, validated */
    public function submit(LeadForm $form, array $values): Lead
    {
        return Tx::run(function () use ($form, $values) {
            $input = array_intersect_key($values, array_flip($form->fields['shown'] ?? ['name']));
            $lead = $this->leads->create(['branch_id' => $form->branch_id, ...$input], null, 'form', $form);
            $form->forceFill(['submissions' => $form->submissions + 1, 'last_submitted_at' => now()])->save();

            return $lead;
        });
    }
}
