<?php

namespace App\Core\Limits;

/**
 * LIM-01: the allowed outlets, devices, users and employees of a tenant's
 * plan. The default (SelectionAllowances) reads the onboarding or trial
 * selection; billing (SUB-02) binds its own, from the subscription.
 */
interface PlanAllowances
{
    public function for(string $tenantId): Allowances;
}
