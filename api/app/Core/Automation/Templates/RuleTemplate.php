<?php

namespace App\Core\Automation\Templates;

use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * AUTO-07: a ready-made rule (reorder alert, overdue credit hold, contract
 * expiry ...). Modules register theirs in RuleTemplates; "use template"
 * builds the rule for a document type from a few parameters and saves it
 * switched off, for the admin to check, test and enable. The texts it
 * writes into the rule are taken in the user's language at that moment
 * and are the tenant's own text from then on.
 */
interface RuleTemplate
{
    /** `{module}.{name}`, e.g. `core.remind_before_date`. */
    public function key(): string;

    /** Translation key of the name; `{key}_description` style keys are the template's own. */
    public function label(): string;

    public function description(): string;

    /** Whether the template can be used with the type. */
    public function appliesTo(DocumentType $type): bool;

    /**
     * The parameters, for the editor: `name`, `kind` (field, days, value,
     * recipients), `fields` (for kind field: the names allowed) and
     * `default`.
     *
     * @return list<array<string, mixed>>
     */
    public function parameters(DocumentType $type): array;

    /**
     * The rule: `name`, `trigger`, `conditions`, `actions` (validated by
     * RuleValidator before saving).
     *
     * @param  array<string, mixed>  $params
     * @return array{name: string, trigger: array, conditions: ?array, actions: list<array>}
     */
    public function build(DocumentType $type, array $params): array;
}
