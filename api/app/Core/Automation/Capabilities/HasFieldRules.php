<?php

namespace App\Core\Automation\Capabilities;

/**
 * RBAC-05 for automation: the field rules resource whose rules apply to a
 * document type's fields (e.g. `party` for the party credit type). A type
 * without it is ruled by its own key (`core.credit_limit_change`).
 */
interface HasFieldRules
{
    public function fieldRulesResource(): string;
}
