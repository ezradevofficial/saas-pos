<?php

namespace App\Core\MasterData;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Duplicates\DuplicateFinder;
use App\Core\MasterData\History\HistoryTypes;
use App\Core\MasterData\Parties\Http\Resources\PartyResource;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\MasterData\Parties\PartySharedRecords;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategorySharedRecords;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Rbac\Models\Role;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Support\ServiceProvider;

/**
 * TEN-08 sharing modes, MD-01 parties, MD-06 duplicate warnings and MD-07
 * record history. Modules add their sharable records, switch guards and
 * history types in their own providers.
 */
class MasterDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MasterDataSharing::class);
        $this->app->singleton(HistoryTypes::class);
        $this->app->singleton(DuplicateFinder::class);
    }

    public function boot(): void
    {
        $sharing = $this->app->make(MasterDataSharing::class);

        foreach (['customers', 'suppliers', 'employees'] as $type) {
            $sharing->records($type, new PartySharedRecords($type, $sharing));
        }

        // Items point to tax categories: they share or split together.
        $sharing->records('items', $this->app->make(TaxCategorySharedRecords::class));

        $history = $this->app->make(HistoryTypes::class);
        $reach = fn () => $this->app->make(CompanyReach::class);

        $history->register('party', Party::class, PartyResource::FIELD_RULES, fn (User $user, Party $party) => $this->app->make(PartyPolicy::class)->view($user, $party));
        $history->register('tax_code', TaxCode::class, null, fn (User $user, TaxCode $code) => $reach()->reachesRecord($user, $code->company_id, ['core.tax.view', 'core.tax.edit']));
        $history->register('tax_category', TaxCategory::class, null, fn (User $user, TaxCategory $category) => $reach()->reachesRecord($user, $category->company_id, ['core.tax.view', 'core.tax.edit']));
        $history->register('price_list', PriceList::class, null, fn (User $user, PriceList $list) => $reach()->reachesRecord($user, $list->company_id, ['core.price_list.view', 'core.price_list.edit']));
        $history->register('company', Company::class, null);
        $history->register('branch', Branch::class, null);
        $history->register('location', Location::class, null);
        $history->register('user', User::class, null);
        $history->register('role', Role::class, null);
    }
}
