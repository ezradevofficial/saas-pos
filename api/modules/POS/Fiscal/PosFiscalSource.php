<?php

namespace Modules\POS\Fiscal;

use App\Core\Fiscal\Contracts\FiscalDocumentSource;
use App\Core\Fiscal\Contracts\ListsFiscalDocuments;
use App\Core\Fiscal\FiscalDocument;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundLine;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SaleVoid;

/**
 * POS-10: the POS module's documents for the core fiscal queue
 * (FiscalDocumentSource, key `pos`), built from the module's own tables in
 * the document's tenant, so the core never reads `pos_*`. A sale is an
 * invoice; a refund (its lines and quantities) and a void (the whole sale)
 * are credit notes naming the sale. Lines carry the tax code and the rate
 * the till applied, as sold; nothing is recomputed here.
 */
class PosFiscalSource implements FiscalDocumentSource, ListsFiscalDocuments
{
    public const KEY = 'pos';

    public function key(): string
    {
        return self::KEY;
    }

    public function document(string $documentType, string $documentId): FiscalDocument
    {
        return FiscalDocument::fromArray(match ($documentType) {
            'sale' => $this->sale(Sale::query()->findOrFail($documentId)),
            'refund' => $this->refund(Refund::query()->findOrFail($documentId)),
            'void' => $this->void(SaleVoid::query()->findOrFail($documentId)),
        });
    }

    /**
     * "Send earlier sales": the company's sales sold since $from, oldest
     * first, each followed by its applied refunds and void.
     */
    public function documentsSince(string $companyId, CarbonImmutable $from): iterable
    {
        $sales = Sale::query()->where('company_id', $companyId)->where('sold_at', '>=', $from)->orderBy('sold_at')->orderBy('id')->lazy(200);

        foreach ($sales as $sale) {
            yield ['sale', $sale->id];

            foreach (Refund::query()->where('sale_id', $sale->id)->where('status', 'applied')->orderBy('refunded_at')->pluck('id') as $refund) {
                yield ['refund', $refund];
            }

            $void = SaleVoid::query()->where('sale_id', $sale->id)->where('status', 'applied')->value('id');

            if ($void !== null) {
                yield ['void', $void];
            }
        }
    }

    private function sale(Sale $sale): array
    {
        $lines = $sale->lines()->get();

        return [
            ...$this->header($sale, 'sale', $sale->id, $sale->receipt_number, $sale->sold_at->toIso8601String(), $sale->cashier_id),
            'lines' => $this->lines($lines, fn (SaleLine $line) => [
                'qty' => (string) $line->qty,
                'discount_minor' => (int) $line->discount_minor,
                'tax_minor' => (int) $line->tax_minor,
                'total_minor' => (int) $line->total_minor,
            ]),
            'totals' => ['tax_minor' => (int) $sale->tax_minor, 'total_minor' => (int) $sale->total_minor],
        ];
    }

    private function refund(Refund $refund): array
    {
        $sale = Sale::query()->findOrFail($refund->sale_id);
        $given = RefundLine::query()->where('refund_id', $refund->id)->get()->keyBy('sale_line_id');
        $lines = SaleLine::query()->whereIn('id', $given->keys())->orderBy('line_no')->get();

        return [
            ...$this->header($sale, 'refund', $refund->id, $refund->receipt_number, $refund->refunded_at->toIso8601String(), $refund->cashier_id),
            'original' => ['type' => 'sale', 'id' => $sale->id],
            'lines' => $this->lines($lines, fn (SaleLine $line) => [
                'qty' => (string) $given[$line->id]->qty,
                'discount_minor' => 0,
                'tax_minor' => (int) $given[$line->id]->tax_minor,
                'total_minor' => (int) $given[$line->id]->total_minor,
            ]),
            'totals' => ['tax_minor' => (int) $refund->tax_minor, 'total_minor' => (int) $refund->total_minor],
        ];
    }

    private function void(SaleVoid $void): array
    {
        $document = $this->sale(Sale::query()->findOrFail($void->sale_id));

        return [
            ...$document,
            'type' => 'void',
            'id' => $void->id,
            'issued_at' => $void->voided_at->toIso8601String(),
            'cashier' => $this->cashier($void->voided_by),
            'original' => ['type' => 'sale', 'id' => $void->sale_id],
        ];
    }

    /** @return array<string, mixed> */
    private function header(Sale $sale, string $type, string $id, ?string $number, string $issuedAt, ?string $cashierId): array
    {
        $customer = $sale->customer_id === null ? null : Party::query()->find($sale->customer_id);
        $main = $sale->payments()->get()->sortByDesc(fn ($payment) => (int) $payment->amount_in_sale_minor)->first();

        return [
            'type' => $type,
            'id' => $id,
            'number' => $number,
            'issued_at' => $issuedAt,
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'location_id' => $sale->location_id,
            'currency' => $sale->currency,
            'customer' => $customer === null ? null : ['tin' => $customer->tax_id, 'name' => $customer->name],
            'payment_type' => in_array($main?->method_type, FiscalDocument::PAYMENT_TYPES, true) ? $main->method_type : 'other',
            'cashier' => $this->cashier($cashierId),
        ];
    }

    /** @return array{id: string, name: string}|null */
    private function cashier(?string $userId): ?array
    {
        $user = $userId === null ? null : User::query()->find($userId);

        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    /**
     * @param  Collection<int, SaleLine>  $lines
     * @param  callable(SaleLine): array<string, mixed>  $amounts
     */
    private function lines(Collection $lines, callable $amounts): array
    {
        $codes = Item::query()->whereKey($lines->pluck('item_id')->unique()->all())->pluck('code', 'id');

        return $lines->values()->map(fn (SaleLine $line) => [
            'line_no' => $line->line_no,
            'item_id' => $line->item_id,
            'item_code' => $codes[$line->item_id] ?? null,
            'item_name' => $line->item_name,
            'unit_price_minor' => (int) $line->unit_price_minor,
            'tax_code_id' => $line->tax_code_id,
            'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
            ...$amounts($line),
        ])->all();
    }
}
