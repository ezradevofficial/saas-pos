<?php

namespace App\Core\Layouts;

use App\Core\Configuration\ConfigKinds;
use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\Layouts\Dashboards\Sources\ApprovalsMine;
use App\Core\Layouts\Dashboards\Sources\ApprovalsWaiting;
use App\Core\Layouts\Dashboards\Sources\Shortcuts;
use App\Core\Layouts\Dashboards\Sources\WorkflowsOverdue;
use App\Core\Layouts\Forms\FormCatalogue;
use App\Core\Layouts\Forms\FormDefinition;
use App\Core\Layouts\Kinds\DashboardLayout;
use App\Core\Layouts\Kinds\FormLayout;
use App\Core\Layouts\Kinds\ListViewLayout;
use App\Core\Layouts\Kinds\NavigationLayout;
use App\Core\MasterData\CreditLimits\Http\Lists\CreditLimitChangeList;
use App\Core\MasterData\Items\Http\Lists\ItemList;
use App\Core\MasterData\Parties\Http\Lists\PartyList;
use App\Core\MasterData\Prices\Http\Lists\ItemPriceList;
use Illuminate\Support\ServiceProvider;

/**
 * Web layout designers (LAY-01, LAY-02, LAY-04): the dashboard, navigation
 * and list view configuration kinds, under `core.layout.*` (RBAC-01; Owner
 * and Admin hold them through their templates), the list views' field
 * rule catalogue (RBAC-05) and the core dashboard data sources. Modules
 * register their own sources (the POS module: its sales).
 */
class LayoutsServiceProvider extends ServiceProvider
{
    public const PERMISSIONS = [
        'view' => 'core.layout.view',
        'edit' => 'core.layout.edit',
        'publish' => 'core.layout.publish',
    ];

    public function register(): void
    {
        $this->app->singleton(ListViewCatalogue::class);
        $this->app->singleton(DashboardSources::class);
        $this->app->singleton(FormCatalogue::class);
    }

    public function boot(): void
    {
        $kinds = $this->app->make(ConfigKinds::class);
        $kinds->register(ListViewLayout::kind());
        $kinds->register(NavigationLayout::kind());
        $kinds->register(DashboardLayout::kind());
        $kinds->register(FormLayout::kind());

        // LAY-03: the item and party forms; custom forms (CF-04) register theirs at run time.
        $forms = $this->app->make(FormCatalogue::class);
        $forms->register(self::itemForm());
        $forms->register(self::partyForm());

        // RBAC-05: the lists whose resources apply field rules, by the web list's id.
        $lists = $this->app->make(ListViewCatalogue::class);
        $lists->register('items', ItemList::class);
        $lists->register('customers', PartyList::class);
        $lists->register('suppliers', PartyList::class);
        $lists->register('item-prices', ItemPriceList::class);
        $lists->register('credit-limit-changes', CreditLimitChangeList::class);

        $sources = $this->app->make(DashboardSources::class);
        foreach ([ApprovalsWaiting::class, ApprovalsMine::class, WorkflowsOverdue::class, Shortcuts::class] as $source) {
            $sources->register(new $source);
        }
    }

    /** LAY-03: the item form (MD-02) as the web renders it. */
    public static function itemForm(): FormDefinition
    {
        $f = fn (string $id, string $group, bool $required = false, bool $default = false, bool $wide = false) => [
            'id' => $id, 'label' => "layouts.forms.item.fields.{$id}", 'required' => $required, 'has_default' => $default, 'wide' => $wide, 'group' => $group,
        ];

        return new FormDefinition('item', 'item', [
            ['id' => 'details', 'title' => 'layouts.forms.item.sections.details', 'columns' => 2],
            ['id' => 'units', 'title' => 'layouts.forms.item.sections.units', 'columns' => 1],
            ['id' => 'barcodes', 'title' => 'layouts.forms.item.sections.barcodes', 'columns' => 1],
            ['id' => 'custom', 'title' => 'layouts.forms.sections.custom', 'columns' => 2],
        ], [
            // The company is asked only when items are kept per company; the switcher's is the default.
            $f('company_id', 'details', true, true, true),
            $f('code', 'details', true),
            $f('type', 'details', true, true),
            $f('name', 'details', true, wide: true),
            $f('category_id', 'details'),
            $f('tax_category_id', 'details'),
            // The base unit (EA by default) with the other units.
            $f('units', 'units', true, true, true),
            $f('barcodes', 'barcodes', wide: true),
        ], 'layouts.forms.item.label');
    }

    /** LAY-03: the party form (MD-01) as the web renders it. */
    public static function partyForm(): FormDefinition
    {
        $f = fn (string $id, string $group, bool $required = false, bool $default = false, bool $wide = false) => [
            'id' => $id, 'label' => "layouts.forms.party.fields.{$id}", 'required' => $required, 'has_default' => $default, 'wide' => $wide, 'group' => $group,
        ];

        return new FormDefinition('party', 'party', [
            ['id' => 'details', 'title' => 'layouts.forms.party.sections.details', 'columns' => 2],
            ['id' => 'contact', 'title' => 'layouts.forms.party.sections.contact', 'columns' => 1],
            ['id' => 'addresses', 'title' => 'layouts.forms.party.sections.addresses', 'columns' => 1],
            ['id' => 'terms', 'title' => 'layouts.forms.party.sections.terms', 'columns' => 2],
            ['id' => 'custom', 'title' => 'layouts.forms.sections.custom', 'columns' => 2],
        ], [
            $f('kind', 'details', true, true),
            $f('name', 'details', true),
            $f('legal_name', 'details'),
            $f('tax_id', 'details'),
            // The roles default to the list the party is created from.
            $f('roles', 'details', true, true, true),
            $f('company_id', 'details', true, true, true),
            $f('phones', 'contact', wide: true),
            $f('emails', 'contact', wide: true),
            $f('addresses', 'addresses', wide: true),
            $f('currency', 'terms'),
            $f('payment_terms_days', 'terms'),
            $f('credit_limit', 'terms', wide: true),
            $f('price_list_id', 'terms'),
            $f('tags', 'terms', wide: true),
        ], 'layouts.forms.party.label');
    }
}
