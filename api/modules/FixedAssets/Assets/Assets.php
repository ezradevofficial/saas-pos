<?php

namespace Modules\FixedAssets\Assets;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\CustomFields\AssetEntity;
use Modules\FixedAssets\Depreciation\AssetBook;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\Models\Reading;
use Modules\FixedAssets\Postings\Postings;
use Modules\FixedAssets\Support\ClosedBooks;
use Modules\FixedAssets\Support\Decimals;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\Support\History;
use Modules\FixedAssets\Support\Numbers;
use Modules\FixedAssets\Support\Rates;
use Modules\FixedAssets\Support\RunLock;

/**
 * FA-04 to FA-05, FA-11, FA-18: the register.
 *
 * - create(): a draft at a location (its company and branch), tagged
 *   (numbered, or a typed tag unique in the company), its terms copied
 *   from the category unless given.
 * - update(): a draft changes freely; an active asset only its
 *   descriptive fields (its place and custodian move by transfer, its
 *   terms by a change of estimate, its value by revaluation).
 * - activate(): capitalisation at the acquisition date's rate, posted
 *   unless the asset is from an opening register.
 * - cancel(): a draft is archived, never deleted (TEN-06).
 * - estimates(): FA-11, prospective.
 * - read(): FA-18, a meter reading that never goes down.
 */
class Assets
{
    /** What a person may change on an active asset (FA-04). */
    public const DESCRIPTIVE = ['name', 'description', 'serial_number', 'supplier_reference', 'warranty_until', 'warranty_note', 'department_id', 'custom'];

    public function __construct(
        private readonly Places $places,
        private readonly Custodians $custodians,
        private readonly Numbers $numbers,
        private readonly Rates $rates,
        private readonly History $history,
        private readonly Postings $postings,
        private readonly ClosedBooks $books,
        private readonly BaseCurrencyLock $baseLock,
        private readonly CustomFieldWriter $customFields,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $by, array $attributes = []): Asset
    {
        $place = $this->places->location((string) $data['location_id']);
        $company = Company::query()->findOrFail($place['company_id']);
        $category = isset($data['category_id']) ? $this->category((string) $data['category_id'], $company->id) : null;

        return FaError::transaction(function () use ($data, $by, $place, $company, $category, $attributes) {
            $asset = new Asset([
                'company_id' => $company->id,
                'branch_id' => $place['branch_id'],
                'location_id' => $place['location_id'],
                'status' => Asset::DRAFT,
                'source' => Asset::MANUAL,
                'currency' => $company->base_currency,
                'created_by' => $by?->id,
                ...$attributes,
            ]);
            $this->fill($asset, $company, $data, $category, creating: true);
            $asset->number = $this->tag($data['number'] ?? null, $company->id, $place['branch_id'], $place['location_id']);
            $this->customFields->fill(AssetEntity::KEY, $asset, $data['custom'] ?? null, creating: true);
            $asset->save();
            $this->customFields->saved(AssetEntity::KEY, $asset);
            $this->history->record($asset, 'created', null, null, ['number' => $asset->number, 'source' => $asset->source], by: $by);

            return $asset->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Asset $asset, array $data, User $by): Asset
    {
        return FaError::transaction(function () use ($asset, $data) {
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($asset->status === Asset::DISPOSED || $asset->archived_at !== null) {
                throw FaError::make('asset_not_open', status: 409);
            }

            $company = Company::query()->findOrFail($asset->company_id);

            if ($asset->status === Asset::ACTIVE) {
                $data = array_intersect_key($data, array_flip(self::DESCRIPTIVE));
            }

            if (array_key_exists('location_id', $data)) {
                $place = $this->places->location((string) $data['location_id'], $company->id);
                $asset->fill(['branch_id' => $place['branch_id'], 'location_id' => $place['location_id']]);
            }

            $category = isset($data['category_id']) ? $this->category((string) $data['category_id'], $company->id) : null;
            $this->fill($asset, $company, $data, $category, creating: false);

            if (isset($data['number']) && $data['number'] !== $asset->number) {
                $asset->number = $this->tag($data['number'], $company->id, $asset->branch_id, $asset->location_id, $asset->id);
            }

            $this->customFields->fill(AssetEntity::KEY, $asset, $data['custom'] ?? null, creating: false);
            $asset->save();
            $this->customFields->saved(AssetEntity::KEY, $asset);

            return $asset->refresh();
        });
    }

    /** FA-05: capitalise a draft. @param array<string, mixed> $data the last changes (in-service date, residual) */
    public function activate(Asset $asset, array $data, User $by): Asset
    {
        return FaError::transaction(function () use ($asset, $data, $by) {
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($asset->status !== Asset::DRAFT || $asset->archived_at !== null) {
                throw FaError::make('asset_not_draft', status: 409);
            }

            $company = Company::query()->findOrFail($asset->company_id);

            if ($data !== []) {
                $this->fill($asset, $company, $data, null, creating: false);
            }

            $category = $asset->category_id === null ? null : Category::query()->find($asset->category_id);

            if ($category === null) {
                throw FaError::make('category_required', field: 'category_id');
            }

            if ($category->archived_at !== null) {
                throw FaError::make('category_archived', field: 'category_id');
            }

            if ($asset->acquired_on === null) {
                throw FaError::make('acquired_on_required', field: 'acquired_on');
            }

            if ((int) $asset->cost_minor <= 0) {
                throw FaError::make('cost_required', field: 'cost_minor');
            }

            $this->assertTerms($asset);
            $base = $this->baseLock->lock($company);
            $fx = $this->rates->fx($company, (string) $asset->currency, $asset->acquired_on, 'currency');
            $baseCost = Rates::toBase($fx, (int) $asset->cost_minor, (string) $asset->currency);
            $residual = (int) $asset->residual_minor;

            if ($residual === 0 && BigDecimal::of((string) $category->residual_percent)->isPositive()) {
                $residual = BigDecimal::of($baseCost)->multipliedBy((string) $category->residual_percent)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
            }

            if ($residual > $baseCost) {
                throw FaError::make('residual_above_cost', field: 'residual_minor');
            }

            if (! $asset->opening) {
                $this->books->assertOpen($company->id, $asset->acquired_on, 'acquired_on');
            }

            $asset->fill([
                'status' => Asset::ACTIVE,
                'in_service_on' => ($asset->in_service_on ?? $asset->acquired_on)->toDateString(),
                'fx' => $fx,
                'base_currency' => $base,
                'base_cost_minor' => $baseCost,
                'book_cost_minor' => $baseCost,
                'residual_minor' => $residual,
                'activated_by' => $by->id,
                'activated_at' => CarbonImmutable::now(),
            ])->save();

            $this->history->record($asset, 'activated', $asset->in_service_on, null, History::book($asset), by: $by);

            if (! $asset->opening) {
                $credit = in_array($asset->source, [Asset::FROM_RECEIPT, Asset::FROM_INVOICE], true) ? 'procurement' : 'manual';
                $this->postings->raise(Postings::CAPITALISATION, 'asset', $asset->id, $company->id, $asset->branch_id, $asset->cost_centre_id,
                    $asset->supplier_id, $asset->acquired_on, $base, ["cost.{$asset->category_id}" => $baseCost, $credit => $baseCost], $asset->number,
                    ['kind' => 'fixed_assets.asset', 'id' => $asset->id]);
            }

            return $asset->refresh();
        });
    }

    public function cancel(Asset $asset, User $by, string $reason): Asset
    {
        return FaError::transaction(function () use ($asset, $by, $reason) {
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($asset->status !== Asset::DRAFT || $asset->archived_at !== null) {
                throw FaError::make('asset_not_draft', status: 409);
            }

            $asset->fill(['archived_at' => CarbonImmutable::now(), 'archived_by' => $by->id, 'archive_reason' => $reason])->save();
            $this->history->record($asset, 'cancelled', null, note: $reason, by: $by);

            return $asset->refresh();
        });
    }

    /** FA-11: a change of estimate, from the next charge on. @param array<string, mixed> $data */
    public function estimates(Asset $asset, array $data, User $by): Asset
    {
        return FaError::transaction(function () use ($asset, $data, $by) {
            RunLock::take($asset->company_id);
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($asset->status !== Asset::ACTIVE) {
                throw FaError::make('asset_not_active', status: 409);
            }

            $before = History::terms($asset);
            $asset->fill(array_intersect_key($data, array_flip(['method', 'useful_life_months', 'residual_minor', 'declining_rate', 'total_units'])));
            $this->assertTerms($asset);

            if ((int) $asset->residual_minor > (int) $asset->book_cost_minor) {
                throw FaError::make('residual_above_cost', field: 'residual_minor');
            }

            $asset->save();
            $this->history->record($asset, 'estimate_changed', null, $before, History::terms($asset), note: $data['reason'], by: $by);

            return $asset->refresh();
        });
    }

    /** FA-18: a meter reading; never below an earlier one nor above a later one, and never on or before the last charge. */
    public function read(Asset $asset, string $on, string $value, ?string $note, User $by): Reading
    {
        return FaError::transaction(function () use ($asset, $on, $value, $note, $by) {
            $asset = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($asset->status === Asset::DISPOSED || $asset->archived_at !== null) {
                throw FaError::make('asset_not_open', status: 409);
            }

            // Review #9: a reading inside a span already charged would change the meter that charge used.
            if ($asset->depreciated_to !== null && $on <= $asset->depreciated_to->toDateString()) {
                throw FaError::make('reading_depreciated', ['date' => $asset->depreciated_to->toDateString()], 'read_on');
            }

            $reading = BigDecimal::of($value);
            $earlier = Reading::query()->where('asset_id', $asset->id)->where('read_on', '<=', $on)->max('reading');
            $later = Reading::query()->where('asset_id', $asset->id)->where('read_on', '>', $on)->min('reading');

            if ($reading->isLessThan((string) ($earlier ?? $asset->meter_start ?? '0')) || ($later !== null && $reading->isGreaterThan((string) $later))) {
                throw FaError::make('reading_decreases', field: 'reading');
            }

            $row = Reading::query()->create(['asset_id' => $asset->id, 'read_on' => $on, 'reading' => (string) $reading, 'note' => $note, 'recorded_by' => $by->id]);
            $this->history->record($asset, 'reading', $on, null, ['reading' => Decimals::show($reading)], 'reading', $row->id, $note, $by);

            return $row;
        });
    }

    /**
     * The fields of $data an asset takes, checked against its company.
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(Asset $asset, Company $company, array $data, ?Category $category, bool $creating): void
    {
        $fields = array_intersect_key($data, array_flip([
            'name', 'description', 'serial_number', 'acquired_on', 'in_service_on', 'cost_minor', 'supplier_reference', 'warranty_until', 'warranty_note',
            'method', 'useful_life_months', 'residual_minor', 'declining_rate', 'total_units', 'meter_start',
        ]));

        if ($category !== null) {
            $asset->category_id = $category->id;

            // The category's terms, unless the body gives its own.
            foreach (['method' => 'method', 'useful_life_months' => 'useful_life_months', 'declining_rate' => 'declining_rate'] as $field => $from) {
                if (! array_key_exists($field, $data)) {
                    $fields[$field] = $category->{$from};
                }
            }
        }

        if (array_key_exists('currency', $data)) {
            $fields['currency'] = $this->rates->currency((string) $data['currency']);
        }

        if (array_key_exists('cost_centre_id', $data)) {
            $fields['cost_centre_id'] = $this->places->costCentre($data['cost_centre_id'], $company->id);
        }

        if (array_key_exists('department_id', $data)) {
            $fields['department_id'] = $this->places->department($data['department_id'], $company->id);
        }

        if (array_key_exists('supplier_id', $data)) {
            $fields['supplier_id'] = $this->supplier($data['supplier_id'], $company->id);
        }

        if (array_key_exists('custodian_user_id', $data) || array_key_exists('custodian_employee_id', $data)) {
            $fields = [...$fields, ...$this->custodians->resolve($data, $company->id)];
        }

        $asset->fill($fields);
    }

    /** FA-07 to FA-10: the terms must fit the method (`method_terms`). */
    private function assertTerms(Asset $asset): void
    {
        $method = $asset->method ?? AssetBook::NONE;

        if (in_array($method, [AssetBook::STRAIGHT_LINE, AssetBook::DECLINING_BALANCE], true) && $asset->useful_life_months === null) {
            throw FaError::make('method_terms', field: 'useful_life_months');
        }

        if ($method === AssetBook::DECLINING_BALANCE && $asset->declining_rate === null) {
            throw FaError::make('method_terms', field: 'declining_rate');
        }

        if ($method === AssetBook::UNITS_OF_PRODUCTION && $asset->total_units === null) {
            throw FaError::make('method_terms', field: 'total_units');
        }
    }

    private function category(string $id, string $companyId): Category
    {
        $category = Category::query()->find($id);

        if ($category === null || $category->company_id !== $companyId) {
            throw FaError::make('category_other_company', field: 'category_id');
        }

        if ($category->archived_at !== null) {
            throw FaError::make('category_archived', field: 'category_id');
        }

        return $category;
    }

    private function supplier(?string $id, string $companyId): ?string
    {
        if ($id === null) {
            return null;
        }

        $party = Party::query()->find($id);

        if ($party === null || ($party->company_id !== null && $party->company_id !== $companyId)) {
            throw FaError::make('party_unknown', field: 'supplier_id');
        }

        return $id;
    }

    /** FA-27: a typed tag unique in the company, else the next number. */
    private function tag(?string $typed, string $companyId, string $branchId, string $locationId, ?string $except = null): string
    {
        if ($typed === null || trim($typed) === '') {
            return $this->numbers->next(Numbers::ASSET, $companyId, $branchId, $locationId);
        }

        $typed = trim($typed);

        if (Asset::query()->where('company_id', $companyId)->where('number', $typed)->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw FaError::make('tag_taken', field: 'number');
        }

        return $typed;
    }
}
