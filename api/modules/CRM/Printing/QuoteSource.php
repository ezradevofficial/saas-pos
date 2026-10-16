<?php

namespace Modules\CRM\Printing;

use App\Core\DocumentTemplates\DocumentData;
use App\Core\DocumentTemplates\RecordSource;
use App\Core\DocumentTemplates\SampleSource;
use Modules\CRM\Models\Quote;

/**
 * TPL-01, CRM-22: the live data source of `sales.quote` (CRM's quotes),
 * with the core's sample for the designer. Shared links and emails load
 * only quotes released to the customer (approved, sent, accepted,
 * converted).
 */
final class QuoteSource implements RecordSource
{
    public const TYPE = 'sales.quote';

    public function type(): string
    {
        return self::TYPE;
    }

    public function documentFields(): array
    {
        return ['document.valid_until' => 'date', 'document.reference' => 'text', 'document.terms' => 'text'];
    }

    public function lineColumns(): array
    {
        return SampleSource::CATALOGUE[self::TYPE][1];
    }

    public function live(): bool
    {
        return true;
    }

    public function load(string $recordId): ?DocumentData
    {
        $quote = Quote::query()->whereIn('status', Quote::RELEASED)->find($recordId);

        return $quote === null ? null : app(QuotePrints::class)->quote($quote);
    }

    public function sample(?string $country): array
    {
        return (new SampleSource(self::TYPE))->sample($country);
    }
}
