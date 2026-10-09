<?php

namespace Modules\POS\Sync\Sources;

use App\Core\DocumentTemplates\DefaultTemplates;
use App\Core\DocumentTemplates\FiscalRules;
use App\Core\DocumentTemplates\TemplateRenderer;
use App\Core\DocumentTemplates\TemplateResolver;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\Models\Tenant;
use Modules\POS\PosServiceProvider;

/**
 * NFR-04, TPL-01, TPL-05: the receipt templates that apply at the till's
 * branch (branch → company → tenant, published versions only), with their
 * variants (the till picks one per sale, as TemplateResolver does), else
 * the default template (the receipt the till printed before templates).
 * Each row carries whether the fiscal block is locked on (TPL-03) and the
 * app's printed wording in English and French (TPL-02), so the till
 * prints the same words as the server.
 */
class TemplateSource implements SnapshotSource
{
    public const TYPES = ['pos.receipt', 'pos.refund_receipt'];

    public function key(): string
    {
        return 'templates';
    }

    public function module(): string
    {
        return PosServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $resolver = app(TemplateResolver::class);
        $rules = app(FiscalRules::class);
        $language = (string) (Tenant::query()->whereKey($scope->tenantId)->value('default_locale') ?? 'en');
        $labels = TemplateRenderer::printLabels();

        return array_map(function (string $type) use ($resolver, $rules, $scope, $language, $labels) {
            ['payload' => $payload, 'document' => $document] = $resolver->published($type, $scope->company->id, $scope->branch->id);

            return [
                'id' => $type,
                'payload' => $payload ?? DefaultTemplates::for($type, $language),
                'source' => $document === null ? null : [
                    'document_id' => $document->id,
                    'scope' => ['type' => $document->scope_type, 'id' => $document->scope_id],
                    'version' => $document->published->version,
                ],
                'fiscal' => [
                    'required' => $rules->requiredFor($type, $scope->company->country),
                    'authority' => $rules->authorityFor($type, $scope->company->country),
                ],
                'labels' => $labels,
            ];
        }, self::TYPES);
    }
}
