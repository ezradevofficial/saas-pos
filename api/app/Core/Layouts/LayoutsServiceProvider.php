<?php

namespace App\Core\Layouts;

use App\Core\Configuration\ConfigKinds;
use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\Layouts\Dashboards\Sources\ApprovalsMine;
use App\Core\Layouts\Dashboards\Sources\ApprovalsWaiting;
use App\Core\Layouts\Dashboards\Sources\Shortcuts;
use App\Core\Layouts\Dashboards\Sources\WorkflowsOverdue;
use App\Core\Layouts\Kinds\DashboardLayout;
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
    }

    public function boot(): void
    {
        $kinds = $this->app->make(ConfigKinds::class);
        $kinds->register(ListViewLayout::kind());
        $kinds->register(NavigationLayout::kind());
        $kinds->register(DashboardLayout::kind());

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
}
