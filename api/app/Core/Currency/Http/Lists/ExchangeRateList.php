<?php

namespace App\Core\Currency\Http\Lists;

use App\Core\Currency\Http\Resources\ExchangeRateResource;
use App\Core\Currency\Models\ExchangeRate;
use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company's rate history (CUR-03): sort keys and exportable columns
 * (EXP-01). Times in the company's time zone. No field rules apply.
 */
class ExchangeRateList extends ListDefinition
{
    /** With `?pair=`: the pair's base, to say each row's direction (as the JSON list does). */
    private ?string $pairBase = null;

    public function __construct(private readonly Company $company) {}

    public function directionFrom(?string $pairBase): void
    {
        $this->pairBase = $pairBase;
    }

    public function name(): string
    {
        return 'exchange-rates';
    }

    public function auditAction(): string
    {
        return 'core.exchange_rate.export';
    }

    public function title(array $filters): string
    {
        return __('core.exchange_rate.list_title', ['company' => $this->company->name]);
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function resource(Model $model): JsonResource
    {
        return ExchangeRateResource::make($model);
    }

    public function sorts(): array
    {
        return [
            'effective_at' => ListSort::column('effective_at'),
            'pair' => ListSort::by(['pair'], fn (Builder $query, string $direction) => $query
                ->orderBy($query->qualifyColumn('base'), $direction)->orderBy($query->qualifyColumn('quote'), $direction)),
            'kind' => ListSort::column('kind'),
            'mid' => ListSort::column('mid'),
            'buy' => ListSort::by(['buy'], fn (Builder $query, string $direction) => $query->orderByRaw("{$query->qualifyColumn('buy')} {$direction} nulls last")),
            'sell' => ListSort::by(['sell'], fn (Builder $query, string $direction) => $query->orderByRaw("{$query->qualifyColumn('sell')} {$direction} nulls last")),
            'source' => ListSort::column('source'),
            'created_at' => ListSort::column('created_at'),
        ];
    }

    public function defaultSort(): string
    {
        // Newest first, as before sorting existed.
        return '-effective_at';
    }

    public function columns(): array
    {
        $decimal = fn (string $field) => fn (array $row, ExchangeRate $rate, ExportValues $values) => $values->decimal($row[$field] === null ? null : (string) $row[$field]);

        return [
            ListColumn::text('pair', 'core.exchange_rate.columns.pair'),
            ListColumn::make('effective_at', 'core.exchange_rate.columns.effective_at', ['effective_at'],
                fn (array $row, ExchangeRate $rate, ExportValues $values) => $values->dateTime($row['effective_at'], $rate->company_id)),
            ListColumn::make('kind', 'core.exchange_rate.columns.kind', ['kind'],
                fn (array $row, ExchangeRate $rate, ExportValues $values) => $values->enum('core.exchange_rate.kinds', $row['kind'])),
            ListColumn::make('mid', 'core.exchange_rate.columns.mid', ['mid'], $decimal('mid')),
            ListColumn::make('buy', 'core.exchange_rate.columns.buy', ['buy'], $decimal('buy')),
            ListColumn::make('sell', 'core.exchange_rate.columns.sell', ['sell'], $decimal('sell')),
            ListColumn::make('direction', 'core.exchange_rate.columns.direction', ['direction'],
                fn (array $row, ExchangeRate $rate, ExportValues $values) => $values->enum('core.exchange_rate.directions', $row['direction'] ?? 'direct')),
            ListColumn::make('source', 'core.exchange_rate.columns.source', ['source'], fn (array $row) => self::source($row['source'])),
            ListColumn::make('created_at', 'core.exchange_rate.columns.created_at', ['created_at'],
                fn (array $row, ExchangeRate $rate, ExportValues $values) => $values->dateTime($row['created_at'], $rate->company_id)),
        ];
    }

    /** A feed's name; a feed without one is "Rate feed" (as the web app shows it). */
    private static function source(?string $source): ?string
    {
        if ($source === null || $source === '') {
            return null;
        }

        $key = "core.exchange_rate.sources.{$source}";

        return __($key) === $key ? __('core.exchange_rate.sources.feed') : __($key);
    }

    public function resolve(Model $model, Request $request): array
    {
        if ($this->pairBase !== null) {
            $model->setDirection($model->base === $this->pairBase ? 'direct' : 'inverse');
        }

        return parent::resolve($model, $request);
    }

    public function filterSummary(array $filters, ExportValues $values): array
    {
        $summary = [];

        foreach (['pair' => 'pair', 'from' => 'from', 'to' => 'to'] as $filter => $label) {
            if (($filters[$filter] ?? '') !== '') {
                $summary[__("core.exchange_rate.columns.{$label}")] = $filters[$filter];
            }
        }

        if (isset($filters['kind'])) {
            $summary[__('core.exchange_rate.columns.kind')] = (string) $values->enum('core.exchange_rate.kinds', $filters['kind']);
        }

        return $summary;
    }
}
