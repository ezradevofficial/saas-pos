<?php

namespace Modules\Accounting\Fiscal;

use App\Core\Fiscal\Contracts\FiscalDocumentSource;
use App\Core\Fiscal\FiscalDocument;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;

/**
 * ACC-19: posted customer invoices (`sale`) and credit notes (`refund`,
 * naming the invoice they credit) for the core fiscal queue (KRA eTIMS,
 * DRC DGI), built from this module's tables in the document's tenant. Lines
 * carry the tax code and rate as applied; nothing is recomputed.
 */
class AccountingFiscalSource implements FiscalDocumentSource
{
    public const KEY = 'accounting';

    public function key(): string
    {
        return self::KEY;
    }

    public static function typeOf(string $kind): string
    {
        return $kind === 'customer_credit' ? 'refund' : 'sale';
    }

    public function document(string $documentType, string $documentId): FiscalDocument
    {
        $document = Document::query()->where('status', Document::POSTED)->whereIn('kind', ['customer_invoice', 'customer_credit'])->findOrFail($documentId);
        $lines = DocumentLine::query()->where('document_id', $document->id)->orderBy('line_no')->get();
        $codes = Item::query()->whereIn('id', $lines->pluck('item_id')->filter()->unique())->pluck('code', 'id');
        $party = Party::query()->find($document->party_id);
        $poster = $document->posted_by === null ? null : User::query()->find($document->posted_by);

        return FiscalDocument::fromArray([
            'type' => self::typeOf($document->kind),
            'id' => $document->id,
            'number' => $document->number,
            'issued_at' => $document->posted_at->toIso8601String(),
            'company_id' => $document->company_id,
            'branch_id' => $document->branch_id,
            'currency' => $document->currency,
            ...($document->kind === 'customer_credit' ? ['original' => ['type' => 'sale', 'id' => $document->credits_document_id]] : []),
            'customer' => $party === null ? null : ['tin' => $party->tax_id, 'name' => $party->name],
            'payment_type' => 'credit',
            'cashier' => $poster === null ? null : ['id' => $poster->id, 'name' => $poster->name],
            'lines' => $lines->map(fn (DocumentLine $line) => [
                'line_no' => $line->line_no,
                'item_id' => $line->item_id,
                'item_code' => $line->item_id === null ? null : ($codes[$line->item_id] ?? null),
                'item_name' => $line->description,
                'qty' => (string) $line->qty,
                'unit_price_minor' => $line->unit_price_minor,
                'discount_minor' => 0,
                'tax_code_id' => $line->tax_code_id,
                'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
                'tax_minor' => $line->tax_minor,
                'total_minor' => $line->total_minor,
            ])->values()->all(),
            'totals' => ['tax_minor' => $document->tax_minor, 'total_minor' => $document->total_minor],
        ]);
    }
}
