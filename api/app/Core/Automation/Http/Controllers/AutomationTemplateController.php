<?php

namespace App\Core\Automation\Http\Controllers;

use App\Core\Automation\Http\Requests\ListTemplatesRequest;
use App\Core\Automation\Http\Requests\UseTemplateRequest;
use App\Core\Automation\Http\Resources\AutomationRuleResource;
use App\Core\Automation\Runtime\Rules;
use App\Core\Automation\Templates\RuleTemplates;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Http\JsonResponse;

/**
 * AUTO-07: the template library (with the document types each can be used
 * with, and its parameters per type) and "use template", which saves the
 * built rule switched off, audited with the template's key.
 */
class AutomationTemplateController
{
    public function index(ListTemplatesRequest $request, RuleTemplates $templates, DocumentTypeRegistry $types): JsonResponse
    {
        $only = $request->validated('type');
        $candidates = $only === null ? $types->all() : [$only => $types->get($only)];

        $data = [];

        foreach ($templates->all() as $template) {
            $usable = array_filter($candidates, fn (DocumentType $type) => $template->appliesTo($type));

            if ($only !== null && $usable === []) {
                continue;
            }

            $data[] = [
                'key' => $template->key(),
                'label' => __($template->label()),
                'description' => __($template->description()),
                'document_types' => array_values(array_map(fn (DocumentType $type) => [
                    'key' => $type->key(),
                    'label' => __($type->label()),
                    'parameters' => $template->parameters($type),
                ], $usable)),
            ];
        }

        return new JsonResponse(['data' => $data]);
    }

    public function use(UseTemplateRequest $request, Rules $rules): JsonResponse
    {
        $template = $request->template();
        $rule = $rules->create([...$request->definition(), 'enabled' => false], $request->user(), ['template' => $template->key()]);

        return AutomationRuleResource::make($rule->fresh('company'))->response()->setStatusCode(201);
    }
}
