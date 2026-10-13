<?php

namespace Modules\Loyalty\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Http\Resources\GiftCardResource;
use Modules\Loyalty\Models\GiftCard;

/** LOY-20..LOY-23, EXP-01: gift cards and vouchers, newest first (never their codes). */
class GiftCardList extends ListDefinition
{
    public function name(): string
    {
        return 'loyalty-gift-cards';
    }

    public function auditAction(): string
    {
        return 'loyalty.gift_card.export';
    }

    public function title(array $filters): string
    {
        return __('loyalty.gift_card.list_title');
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return GiftCardResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'issued_at' => ListSort::column('issued_at'),
            'balance' => ListSort::column('balance_minor'),
            'status' => ListSort::column('status'),
            'expires_on' => ListSort::column('expires_on'),
        ];
    }

    public function defaultSort(): string
    {
        return '-issued_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('last4', 'loyalty.gift_card.columns.card', ['last4'], fn (array $row) => '•••• '.$row['last4']),
            ListColumn::make('kind', 'loyalty.gift_card.columns.kind', ['kind'], fn (array $row) => __('loyalty.gift_card.kinds.'.$row['kind'])),
            ListColumn::make('status', 'loyalty.gift_card.columns.status', ['status'], fn (array $row) => __('loyalty.gift_card.statuses.'.$row['status'])),
            ListColumn::make('initial', 'loyalty.gift_card.columns.initial', ['initial'], fn (array $row, Model $m, ExportValues $values) => $values->money($row['initial'])),
            ListColumn::make('balance', 'loyalty.gift_card.columns.balance', ['balance'], fn (array $row, Model $m, ExportValues $values) => $values->money($row['balance'])),
            ListColumn::make('expires_on', 'loyalty.gift_card.columns.expires_on', ['expires_on'], fn (array $row) => $row['expires_on']),
            ListColumn::make('issued_at', 'loyalty.gift_card.columns.issued_at', ['issued_at'],
                fn (array $row, GiftCard $card, ExportValues $values) => $values->dateTime($row['issued_at'], $card->company_id)),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = $this->searchAndStatus($filters, archivable: false);

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $summary[__('core.list.status')] = __('loyalty.gift_card.statuses.'.$filters['status']);
        }

        return $summary;
    }
}
