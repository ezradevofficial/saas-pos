<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;

/**
 * AUTO-04: POST automation-rules/{rule}/test {values?, old_values?,
 * document_id?}: what the saved rule would do, with no side effects and no
 * run logged. Seeing the rule is enough; a real document must be one the
 * user may see.
 */
class TestRuleRequest extends RuleRequest
{
    use TestsRules;

    public function rules(): array
    {
        return $this->testRules();
    }

    protected function testedType(): ?DocumentType
    {
        return app(DocumentTypeRegistry::class)->find($this->rule()->document_type);
    }
}
