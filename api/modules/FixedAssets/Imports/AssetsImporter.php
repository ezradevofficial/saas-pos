<?php

namespace Modules\FixedAssets\Imports;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Imports\DomainRequests;
use App\Core\Imports\ImportColumn;
use App\Core\Imports\ImportContext;
use App\Core\Imports\Importer;
use App\Core\Imports\ImportRow;
use App\Core\Imports\PreparedRow;
use App\Core\Imports\RowRejected;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FixedAssets\Assets\Assets;
use Modules\FixedAssets\FixedAssetsServiceProvider;
use Modules\FixedAssets\Http\Requests\StoreAssetRequest;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\Support\Access;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\Support\History;

/**
 * FA-24, IMP-01 to IMP-03: the opening asset register, one asset per row:
 * location (code or name), tag (numbered when empty), name, category code
 * (of the location's company), acquisition and in-service dates, cost and
 * accumulated depreciation to a date and residual value (decimals in the
 * company's base currency), life in months, serial number and custodian
 * (a user's email). Each row passes StoreAssetRequest
 * (`fixed_assets.asset.manage` at the location) and becomes an active
 * asset flagged `opening`, which posts no capitalisation (decision 3).
 * Undo archives an opening asset nothing has used since.
 */
class AssetsImporter extends Importer
{
    public const KEY = 'fixed_assets';

    public function key(): string
    {
        return self::KEY;
    }

    public function model(): string
    {
        return Asset::class;
    }

    public function permissions(string $mode): array
    {
        return ['fixed_assets.asset.manage'];
    }

    public function canRun(User $user, string $mode = 'create'): bool
    {
        return $mode === 'create' && app(ModuleRegistry::class)->isActive(FixedAssetsServiceProvider::MODULE) && parent::canRun($user, $mode);
    }

    public function matchColumns(): array
    {
        return [];
    }

    protected function baseColumns(): array
    {
        return [
            ImportColumn::text('location', true),
            ImportColumn::text('tag'),
            ImportColumn::text('name', true),
            ImportColumn::text('category', true),
            ImportColumn::of('acquired_on', 'date', true),
            ImportColumn::of('in_service_on', 'date'),
            ImportColumn::of('cost', 'decimal', true),
            ImportColumn::of('accumulated_depreciation', 'decimal'),
            ImportColumn::of('depreciated_to', 'date'),
            ImportColumn::of('residual_value', 'decimal'),
            ImportColumn::of('useful_life_months', 'integer'),
            ImportColumn::text('serial_number'),
            ImportColumn::text('custodian_email'),
        ];
    }

    public function example(): array
    {
        return [
            'location' => 'Head office', 'tag' => '', 'name' => 'Delivery van', 'category' => 'VEH', 'acquired_on' => '2024-03-15', 'in_service_on' => '2024-03-20',
            'cost' => '3200000.00', 'accumulated_depreciation' => '1066666.67', 'depreciated_to' => '2025-12-31', 'residual_value' => '320000.00',
            'useful_life_months' => '60', 'serial_number' => 'KDA 123X', 'custodian_email' => '',
        ];
    }

    protected function fieldColumns(): array
    {
        return [
            'location_id' => 'location', 'number' => 'tag', 'name' => 'name', 'category_id' => 'category', 'acquired_on' => 'acquired_on',
            'in_service_on' => 'in_service_on', 'cost_minor' => 'cost', 'residual_minor' => 'residual_value', 'useful_life_months' => 'useful_life_months',
            'serial_number' => 'serial_number', 'custodian_user_id' => 'custodian_email',
        ];
    }

    public function identity(array $values): ?string
    {
        return ($values['tag'] ?? '') === '' ? null : Str::lower((string) $values['tag']);
    }

    public function prepare(int $number, array $values, ImportContext $context): PreparedRow
    {
        $locations = $context->remember('locations', fn () => Location::query()->whereNull('archived_at')->with('branch')->get(['id', 'code', 'name', 'branch_id']));
        $wanted = Str::lower((string) ($values['location'] ?? ''));
        $byCode = $locations->filter(fn (Location $l) => $l->code !== null && Str::lower((string) $l->code) === $wanted);
        $matches = $byCode->isNotEmpty() ? $byCode : $locations->filter(fn (Location $l) => Str::lower($l->name) === $wanted);

        if ($matches->count() !== 1) {
            throw new RowRejected(['location' => [__($matches->isEmpty() ? 'imports.errors.unknown' : 'imports.errors.ambiguous', ['value' => $values['location'] ?? ''])]]);
        }

        $location = $matches->first();
        $company = Company::query()->findOrFail($location->branch->company_id);
        $category = Category::query()->where('company_id', $company->id)->whereNull('archived_at')->where('code', (string) ($values['category'] ?? ''))->first()
            ?? throw new RowRejected(['category' => [__('imports.errors.unknown', ['value' => $values['category'] ?? ''])]]);
        $decimals = app(CurrencyDecimals::class)->for((string) $company->base_currency);
        $minor = function (string $column) use ($values, $decimals): ?int {
            if (($values[$column] ?? '') === '') {
                return null;
            }

            try {
                return BigDecimal::of((string) $values[$column])->toScale($decimals, RoundingMode::Unnecessary)->withPointMovedRight($decimals)->toInt();
            } catch (MathException) {
                throw new RowRejected([$column => [__('fixed_assets.imports.amount', ['decimals' => $decimals])]]);
            }
        };
        $custodian = null;

        if (($values['custodian_email'] ?? '') !== '') {
            $custodian = User::query()->whereRaw('lower(email) = ?', [Str::lower((string) $values['custodian_email'])])->value('id')
                ?? throw new RowRejected(['custodian_email' => [__('imports.errors.unknown', ['value' => $values['custodian_email']])]]);
        }

        $accumulated = $minor('accumulated_depreciation') ?? 0;

        if ($accumulated > 0 && ($values['depreciated_to'] ?? '') === '') {
            throw new RowRejected(['depreciated_to' => [__('fixed_assets.imports.depreciated_to')]]);
        }

        return new PreparedRow($number, array_filter([
            'location_id' => $location->id,
            'number' => ($values['tag'] ?? '') === '' ? null : (string) $values['tag'],
            'name' => $values['name'] ?? null,
            'category_id' => $category->id,
            'acquired_on' => $values['acquired_on'] ?? null,
            'in_service_on' => $values['in_service_on'] ?? null,
            'currency' => (string) $company->base_currency,
            'cost_minor' => $minor('cost'),
            'residual_minor' => $minor('residual_value'),
            'useful_life_months' => isset($values['useful_life_months']) && $values['useful_life_months'] !== '' ? (int) $values['useful_life_months'] : null,
            'serial_number' => $values['serial_number'] ?? null,
            'custodian_user_id' => $custodian,
        ], fn ($v) => $v !== null && $v !== ''), null, [
            'accumulated' => $accumulated,
            'depreciated_to' => ($values['depreciated_to'] ?? '') === '' ? null : (string) $values['depreciated_to'],
        ]);
    }

    public function validate(PreparedRow $row, ImportContext $context): void
    {
        $row->validated = app(DomainRequests::class)->validate(StoreAssetRequest::class, $row->input, $context->user);
    }

    public function check(PreparedRow $row, ImportContext $context): void
    {
        if ((int) $row->context['accumulated'] > (int) ($row->validated['cost_minor'] ?? 0)) {
            throw new RowRejected(['accumulated_depreciation' => [__('fixed_assets.imports.accumulated_above_cost')]]);
        }
    }

    public function write(PreparedRow $row, ImportContext $context): array
    {
        $this->check($row, $context);

        try {
            return FaError::transaction(function () use ($row, $context) {
                $assets = app(Assets::class);
                $asset = $assets->create($row->validated, $context->user, [
                    'source' => Asset::IMPORT,
                    'opening' => true,
                    'accumulated_minor' => (int) $row->context['accumulated'],
                    'depreciated_to' => $row->context['depreciated_to'],
                ]);

                return ['record' => $assets->activate($asset, [], $context->user), 'before' => null];
            });
        } catch (ApiException $e) {
            throw RowRejected::from($e) ?? $e;
        }
    }

    public function undo(ImportRow $row, ImportContext $context): void
    {
        $asset = Asset::query()->find($row->record_id);

        if ($asset === null || $asset->archived_at !== null) {
            return;
        }

        if (! app(Access::class)->at($context->user, 'fixed_assets.asset.manage', $asset)) {
            throw RowRejected::row(__('imports.errors.forbidden'));
        }

        $used = DB::table('fa_depreciation_lines')->where('asset_id', $asset->id)->exists()
            || DB::table('fa_transfers')->where('asset_id', $asset->id)->exists()
            || DB::table('fa_disposals')->where('asset_id', $asset->id)->exists()
            || DB::table('fa_revaluations')->where('asset_id', $asset->id)->exists()
            || DB::table('fa_work_orders')->where('asset_id', $asset->id)->exists();

        if ($used) {
            throw RowRejected::row(__('fixed_assets.imports.used'));
        }

        $asset->fill(['archived_at' => CarbonImmutable::now(), 'archived_by' => $context->user->id, 'archive_reason' => __('fixed_assets.imports.undone')])->save();
        app(History::class)->record($asset, 'cancelled', CarbonImmutable::now(), note: __('fixed_assets.imports.undone'), by: $context->user);
    }

    public function companiesOf(array $ids): array
    {
        return Asset::query()->whereKey($ids)->distinct()->pluck('company_id')->all();
    }

    public function readPermissions(): array
    {
        return ['fixed_assets.asset.view'];
    }

    public function describe(Model $record): string
    {
        return trim($record->getAttribute('number').' '.$record->getAttribute('name'));
    }

    public function label(): string
    {
        return __('imports.importers.fixed_assets');
    }
}
