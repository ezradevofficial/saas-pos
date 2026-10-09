<?php

namespace Tests\Support\Fiscal;

use App\Core\Fiscal\Contracts\FiscalDocumentSource;
use App\Core\Fiscal\Contracts\ListsFiscalDocuments;
use App\Core\Fiscal\FiscalDocument;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A module's documents for the fiscal queue tests, held in memory: what a
 * module's FiscalDocumentSource builds from its own tables (the POS does
 * this for sales, refunds and voids).
 */
final class TestFiscalSource implements FiscalDocumentSource, ListsFiscalDocuments
{
    public const KEY = 'test';

    /** @var array<string, array<string, mixed>> "type:id" => document */
    private static array $documents = [];

    public static function reset(): void
    {
        self::$documents = [];
    }

    /** @param array<string, mixed> $document */
    public static function put(array $document): array
    {
        self::$documents[$document['type'].':'.$document['id']] = $document;

        return $document;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function document(string $documentType, string $documentId): FiscalDocument
    {
        return FiscalDocument::fromArray(self::$documents["{$documentType}:{$documentId}"] ?? throw new RuntimeException('No such test document.'));
    }

    public function documentsSince(string $companyId, CarbonImmutable $from): iterable
    {
        $documents = array_filter(self::$documents, fn (array $d) => $d['company_id'] === $companyId && CarbonImmutable::parse($d['issued_at'])->greaterThanOrEqualTo($from));
        uasort($documents, fn (array $a, array $b) => [$a['issued_at'], $a['type'] !== 'sale'] <=> [$b['issued_at'], $b['type'] !== 'sale']);

        foreach ($documents as $document) {
            yield [$document['type'], $document['id']];
        }
    }

    /**
     * A KES sale with one line per [name, tax code id, rate, total, tax],
     * tax-inclusive amounts in minor units.
     *
     * @param  list<array{0: string, 1: ?string, 2: ?string, 3: int, 4: int}>  $lines
     */
    public static function sale(string $id, string $companyId, array $lines, array $extra = []): array
    {
        return self::put([
            'type' => 'sale',
            'id' => $id,
            'number' => 'R-OUT1-'.substr($id, -6),
            'issued_at' => '2026-10-09T07:15:30Z',
            'company_id' => $companyId,
            'currency' => 'KES',
            'payment_type' => 'mobile_money',
            'customer' => ['tin' => 'P051234567X', 'name' => 'Juma Traders'],
            'cashier' => ['id' => 'cashier-1', 'name' => 'Amina Otieno'],
            'lines' => array_map(fn (array $line, int $index) => [
                'line_no' => $index + 1,
                'item_id' => 'item-'.($index + 1),
                'item_code' => 'ITEM-'.($index + 1),
                'item_name' => $line[0],
                'barcode' => '616100000000'.($index + 1),
                'classification_code' => '5020230100',
                'unit_code' => 'U',
                'packaging_code' => 'NT',
                'qty' => '2',
                'unit_price_minor' => intdiv($line[3], 2),
                'discount_minor' => 0,
                'tax_code_id' => $line[1],
                'tax_rate' => $line[2],
                'tax_minor' => $line[4],
                'total_minor' => $line[3],
            ], $lines, array_keys($lines)),
            'totals' => ['tax_minor' => array_sum(array_column($lines, 4)), 'total_minor' => array_sum(array_column($lines, 3))],
            ...$extra,
        ]);
    }

    /** A credit note for $saleId (a refund or a void) with the given lines. */
    public static function creditNote(string $type, string $id, string $saleId, string $companyId, array $lines): array
    {
        $document = self::sale($id, $companyId, $lines, ['type' => $type, 'original' => ['type' => 'sale', 'id' => $saleId], 'number' => 'RF-OUT1-'.substr($id, -6)]);
        unset(self::$documents["sale:{$id}"]);

        return self::put($document);
    }
}
