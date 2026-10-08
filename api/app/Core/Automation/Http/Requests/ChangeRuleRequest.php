<?php

namespace App\Core\Automation\Http\Requests;

/**
 * POST automation-rules/{rule}/enable|disable|archive (no body):
 * `core.automation.edit` at the rule's company. Enabling checks the rule
 * again (its type's module may have been switched off, a role archived).
 */
class ChangeRuleRequest extends RuleRequest
{
    protected bool $edit = true;
}
