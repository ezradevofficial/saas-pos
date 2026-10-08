<?php

namespace App\Core\MasterData;

use App\Core\Currency\CurrencyUsage;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\Dimension;
use App\Core\MasterData\Dimensions\Dimensions;
use App\Core\MasterData\Duplicates\DuplicateFinder;
use App\Core\MasterData\History\HistoryTypes;
use App\Core\MasterData\Items\Console\SeedDefaultUomsCommand;
use App\Core\MasterData\Items\Http\Resources\ItemCategoryResource;
use App\Core\MasterData\Items\Http\Resources\ItemResource;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemCategoryPolicy;
use App\Core\MasterData\Items\ItemCategorySharedRecords;
use App\Core\MasterData\Items\ItemCodesGuard;
use App\Core\MasterData\Items\ItemPolicy;
use App\Core\MasterData\Items\ItemSharedRecords;
use App\Core\MasterData\Items\Listeners\SeedDefaultUoms;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Items\UomPolicy;
use App\Core\MasterData\Parties\Http\Resources\PartyResource;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\MasterData\Parties\PartySharedRecords;
use App\Core\MasterData\PaymentMethods\Console\SeedDefaultPaymentMethodsCommand;
use App\Core\MasterData\PaymentMethods\DefaultPaymentMethods;
use App\Core\MasterData\PaymentMethods\Http\Resources\PaymentMethodResource;
use App\Core\MasterData\PaymentMethods\Listeners\SeedDefaultPaymentMethods;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\PaymentMethods\PaymentProviders;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategorySharedRecords;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Rbac\Models\Role;
use App\Core\Tenancy\Events\CompanyCreated;
use App\Core\Tenancy\Events\TenantProvisioned;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * TEN-08 sharing modes, MD-01 parties, MD-02 items, MD-04 payment
 * methods, MD-05 dimensions, MD-06 duplicate warnings and MD-07 record
 * history. Modules add their sharable records, switch guards and
 * history types in their own providers.
 */
class MasterDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MasterDataSharing::class);
        $this->app->singleton(HistoryTypes::class);
        $this->app->singleton(DuplicateFinder::class);
        $this->app->singleton(PaymentProviders::class);
        $this->app->singleton(DefaultPaymentMethods::class);
    }

    public function boot(): void
    {
        $sharing = $this->app->make(MasterDataSharing::class);

        foreach (['customers', 'suppliers', 'employees'] as $type) {
            $sharing->records($type, new PartySharedRecords($type, $sharing));
        }

        // Items point to tax categories and item categories: they share or
        // split together. Codes and barcodes must stay unique (review focus 4).
        $sharing->records('items', $this->app->make(TaxCategorySharedRecords::class));
        $sharing->records('items', new ItemCategorySharedRecords);
        $sharing->records('items', new ItemSharedRecords);
        $sharing->guard(new ItemCodesGuard);

        // MD-02: signed item image URLs, 120 a minute per IP.
        RateLimiter::for('media', fn (Request $request) => Limit::perMinute(120)->by('ip|'.$request->ip()));

        // MD-02: every tenant starts with the default units.
        Event::listen(TenantProvisioned::class, SeedDefaultUoms::class);

        // MD-04: every company starts with its country's payment methods
        // (after its currencies, in the creating transaction).
        Event::listen(CompanyCreated::class, SeedDefaultPaymentMethods::class);

        // CUR-01: a party's credit limit is a stored amount; its currency's
        // decimals lock once any party (archived ones too) has one in it.
        $this->app->make(CurrencyUsage::class)->register(
            fn (string $code) => Party::query()->where('credit_limit_currency', $code)->exists(),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([SeedDefaultUomsCommand::class, SeedDefaultPaymentMethodsCommand::class]);
        }

        $history = $this->app->make(HistoryTypes::class);
        $reach = fn () => $this->app->make(CompanyReach::class);

        // The credit limit change number of an applied request goes with the limit (RBAC-05).
        $history->register(
            'party', Party::class, PartyResource::FIELD_RULES, fn (User $user, Party $party) => $this->app->make(PartyPolicy::class)->view($user, $party),
            ['credit_limit_minor' => ['credit_limit_change'], 'credit_limit_currency' => ['credit_limit_change']],
        );
        $history->register('tax_code', TaxCode::class, null, fn (User $user, TaxCode $code) => $reach()->reachesRecord($user, $code->company_id, ['core.tax.view', 'core.tax.edit']));
        $history->register('tax_category', TaxCategory::class, null, fn (User $user, TaxCategory $category) => $reach()->reachesRecord($user, $category->company_id, ['core.tax.view', 'core.tax.edit']));
        $history->register('price_list', PriceList::class, null, fn (User $user, PriceList $list) => $reach()->reachesRecord($user, $list->company_id, ['core.price_list.view', 'core.price_list.edit']));
        $history->register('item', Item::class, ItemResource::FIELD_RULES, fn (User $user, Item $item) => $this->app->make(ItemPolicy::class)->view($user, $item));
        $history->register('item_category', ItemCategory::class, ItemCategoryResource::FIELD_RULES, fn (User $user, ItemCategory $category) => $this->app->make(ItemCategoryPolicy::class)->view($user, $category));
        $history->register('uom', Uom::class, null, fn (User $user, Uom $uom) => $this->app->make(UomPolicy::class)->view($user, $uom));
        // The secrets change marker names secret keys: hidden with `secrets`.
        $history->register(
            'payment_method', PaymentMethod::class, PaymentMethodResource::FIELD_RULES,
            fn (User $user, PaymentMethod $method) => $reach()->reachesRecord($user, $method->company_id, PaymentMethod::PERMISSIONS),
            ['secrets' => ['secrets_changed']],
        );

        foreach (Dimensions::TYPES as $type => $model) {
            $history->register($type, $model, null, fn (User $user, Dimension $dimension) => $reach()->reachesRecord($user, $dimension->company_id, Dimension::PERMISSIONS));
        }

        $history->register('company', Company::class, null);
        $history->register('branch', Branch::class, null);
        $history->register('location', Location::class, null);
        $history->register('user', User::class, null);
        $history->register('role', Role::class, null);
    }
}
