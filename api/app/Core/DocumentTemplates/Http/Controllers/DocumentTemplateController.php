<?php

namespace App\Core\DocumentTemplates\Http\Controllers;

use App\Core\Configuration\Models\ConfigDocument;
use App\Core\DocumentTemplates\DataSources;
use App\Core\DocumentTemplates\DefaultTemplates;
use App\Core\DocumentTemplates\DocumentTypes;
use App\Core\DocumentTemplates\FiscalRules;
use App\Core\DocumentTemplates\Http\Requests\ListTemplateTypesRequest;
use App\Core\DocumentTemplates\Http\Requests\PreviewTemplateRequest;
use App\Core\DocumentTemplates\Http\Requests\ShowTemplatePreviewRequest;
use App\Core\DocumentTemplates\PdfRenderer;
use App\Core\DocumentTemplates\TemplateRenderer;
use App\Core\DocumentTemplates\TemplateResolver;
use App\Core\DocumentTemplates\TemplateSchema;
use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * TPL-01..TPL-03: what the template designer needs besides the versioned
 * configuration store (config/template, LAY-06): the document types with
 * their merge fields, columns, locked blocks and default template, and a
 * live preview with sample data (HTML, and a PDF for PREVIEW_MINUTES).
 */
class DocumentTemplateController
{
    public const PREVIEW_MINUTES = 10;

    public function __construct(
        private readonly DataSources $sources,
        private readonly FiscalRules $fiscal,
    ) {}

    public function types(ListTemplateTypesRequest $request): JsonResponse
    {
        $scope = $request->scopeDocument($request->validated('scope_type'), $request->validated('scope_id'));
        $country = $this->country($scope);
        // A tenant-wide template may print in several countries: name one authority only when they agree.
        $countries = $this->fiscal->countriesOf($scope);
        $language = in_array(app()->getLocale(), ['en', 'fr'], true) ? app()->getLocale() : 'en';
        $custom = $this->sources->customLabels();

        $types = array_map(function (string $type) use ($scope, $country, $countries, $language, $custom) {
            $required = $this->fiscal->requiredAt($type, $scope);
            $fields = [];

            foreach ($this->sources->fields($type) as $path => $kind) {
                $group = strstr($path, '.', true);
                $fields[] = [
                    'path' => $path,
                    'group' => str_contains($path, '.custom.') ? 'custom' : $group,
                    'type' => $kind,
                    'label' => $custom[$path] ?? ($path === 'document.number' ? __('templates.print.numbers.'.TemplateRenderer::typeKey($type)) : __('templates.print.fields.'.$path)),
                ];
            }

            return [
                'key' => $type,
                'label' => DocumentTypes::label($type),
                'paper' => DocumentTypes::paper($type),
                'live' => $this->sources->get($type)->live(),
                'fiscal' => [
                    'allowed' => DocumentTypes::TYPES[$type]['fiscal'],
                    'required' => $required,
                    'authority' => count(array_unique(array_map(fn (string $c) => (string) $this->fiscal->authorityFor($type, $c), $countries))) === 1 ? $this->fiscal->authorityFor($type, $country) : null,
                ],
                // TPL-03: the fiscal block and the totals (with the tax lines) stay.
                'locked' => $required ? ['fiscal', 'totals'] : [],
                'fields' => $fields,
                'columns' => array_map(fn (string $column, string $kind) => [
                    'key' => $column,
                    'type' => $kind,
                    'label' => $custom[$column] ?? __('templates.print.columns.'.$column),
                    'numeric' => in_array($column, TemplateRenderer::NUMERIC_COLUMNS, true) || $kind === 'money',
                ], array_keys($this->sources->columns($type)), $this->sources->columns($type)),
                'default' => DefaultTemplates::for($type, $language),
            ];
        }, DocumentTypes::keys());

        return new JsonResponse(['data' => $types, 'meta' => [
            'country' => $country,
            'papers' => DocumentTypes::PAPERS,
            'languages' => TemplateSchema::LANGUAGES,
            'blocks' => TemplateSchema::BLOCKS,
            'operators' => TemplateSchema::OPERATORS,
        ]]);
    }

    public function preview(PreviewTemplateRequest $request): JsonResponse
    {
        $type = $request->validated('type');
        $scope = $request->scopeDocument($request->validated('scope_type'), $request->validated('scope_id'));
        $payload = $request->payload();
        $required = $this->fiscal->requiredAt($type, $scope);
        $data = $this->sources->get($type)->sample($this->country($scope));
        $template = $this->variant($payload, $request->validated('variant'));

        $key = Str::random(40);
        Cache::put($this->cacheKey($request, $key), compact('type', 'template', 'data', 'required'), now()->addMinutes(self::PREVIEW_MINUTES));

        return new JsonResponse(['data' => [
            'html' => app(TemplateRenderer::class)->html($type, $template, $data, $required),
            'pdf_url' => url('api/v1/templates/previews/'.$key.'/pdf'),
            'problems' => TemplateSchema::problems($payload, $type, $required),
        ]]);
    }

    public function previewPdf(ShowTemplatePreviewRequest $request, string $preview, PdfRenderer $pdf): Response
    {
        $stored = Cache::get($this->cacheKey($request, $preview));

        if (! is_array($stored)) {
            throw new ApiException(404, 'preview_expired', __('templates.errors.preview_expired'));
        }

        return new Response($pdf->pdf($stored['type'], $stored['template'], $stored['data'], $stored['required']), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The template with one of its variants applied (the designer previews each). */
    private function variant(array $payload, ?string $variantId): array
    {
        $base = $payload;
        unset($base['variants']);

        foreach ((array) ($payload['variants'] ?? []) as $variant) {
            if (is_array($variant) && ($variant['id'] ?? null) === $variantId) {
                return TemplateResolver::applyVariant([...$base, 'variants' => [[...$variant, 'applies_when' => []]]], [])[0];
            }
        }

        return $base;
    }

    /** The country the sample prints for: the scope's company, else the tenant's first company. */
    private function country(?ConfigDocument $scope): ?string
    {
        return $this->fiscal->countriesOf($scope)[0] ?? null;
    }

    private function cacheKey(ShowTemplatePreviewRequest|PreviewTemplateRequest $request, string $key): string
    {
        return 'template-preview:'.app(TenantContext::class)->require().':'.$request->user()->id.':'.$key;
    }
}
