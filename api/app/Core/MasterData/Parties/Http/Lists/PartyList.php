<?php

namespace App\Core\MasterData\Parties\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Parties\Http\Resources\PartyResource;
use App\Core\MasterData\Parties\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The contacts list (customers, suppliers, contacts, employee links;
 * MD-01): sort keys and exportable columns (EXP-01). Columns read
 * PartyResource, so field rules on `party` apply (RBAC-05).
 */
class PartyList extends ListDefinition
{
    public function name(): string
    {
        return 'parties';
    }

    public function auditAction(): string
    {
        return 'core.party.export';
    }

    public function title(array $filters): string
    {
        return __('core.party.list_titles.'.($filters['role'] ?? 'all'));
    }

    public function fieldRules(): string
    {
        return PartyResource::FIELD_RULES;
    }

    public function fieldSources(): array
    {
        return PartyResource::SOURCES;
    }

    public function resource(Model $model): JsonResource
    {
        return PartyResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'name' => 'name',
            'legal_name' => 'legal_name',
            'kind' => 'kind',
            'tax_id' => 'tax_id',
            'payment_terms' => 'payment_terms_days',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function columns(): array
    {
        return [
            ListColumn::text('name', 'core.party.columns.name'),
            ListColumn::text('legal_name', 'core.party.columns.legal_name'),
            ListColumn::make('kind', 'core.party.columns.kind', ['kind'],
                fn (array $row, Party $party, ExportValues $values) => $values->enum('core.party.kinds', $row['kind'])),
            ListColumn::make('roles', 'core.party.columns.roles', ['roles'],
                fn (array $row, Party $party, ExportValues $values) => $values->join(array_map(fn (string $role) => $values->enum('core.party.roles', $role), $row['roles'] ?? []))),
            ListColumn::make('phones', 'core.party.columns.phones', ['phones'],
                fn (array $row, Party $party, ExportValues $values) => $values->join(array_column($row['phones'], 'number'))),
            ListColumn::make('emails', 'core.party.columns.emails', ['emails'],
                fn (array $row, Party $party, ExportValues $values) => $values->join(array_column($row['emails'], 'address'))),
            ListColumn::text('tax_id', 'core.party.columns.tax_id'),
            ListColumn::make('tags', 'core.party.columns.tags', ['tags'],
                fn (array $row, Party $party, ExportValues $values) => $values->join($row['tags'] ?? [])),
            ListColumn::text('currency', 'core.party.columns.currency'),
            ListColumn::make('credit_limit', 'core.party.columns.credit_limit', ['credit_limit'],
                fn (array $row, Party $party, ExportValues $values) => $values->money($row['credit_limit'])),
            ListColumn::make('payment_terms', 'core.party.columns.payment_terms', ['payment_terms_days'],
                fn (array $row) => $row['payment_terms_days'] === null ? null
                    : trans_choice('core.party.payment_terms_days', $row['payment_terms_days'], ['days' => $row['payment_terms_days']])),
            ListColumn::make('status', 'core.party.columns.status', ['archived_at'],
                fn (array $row) => __('core.list.statuses.'.($row['archived_at'] === null ? 'active' : 'archived'))),
            ListColumn::make('created_at', 'core.party.columns.created_at', ['created_at'],
                fn (array $row, Party $party, ExportValues $values) => $values->dateTime($row['created_at'], $party->company_id)),
            ListColumn::make('updated_at', 'core.party.columns.updated_at', ['updated_at'],
                fn (array $row, Party $party, ExportValues $values) => $values->dateTime($row['updated_at'], $party->company_id)),
        ];
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = [];

        if (($filters['search'] ?? '') !== '') {
            $summary[__('core.list.search')] = $filters['search'];
        }

        if (isset($filters['role'])) {
            $summary[__('core.party.columns.role')] = $values->enum('core.party.roles', $filters['role']);
        }

        if (isset($filters['tag'])) {
            $summary[__('core.party.columns.tag')] = $filters['tag'];
        }

        $summary[__('core.list.status')] = __('core.list.statuses.'.($filters['status'] ?? 'active'));

        return $summary;
    }
}
