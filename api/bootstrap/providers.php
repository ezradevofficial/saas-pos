<?php

use App\Core\Automation\AutomationServiceProvider;
use App\Core\Currency\CurrencyServiceProvider;
use App\Core\Identity\IdentityServiceProvider;
use App\Core\MasterData\MasterDataServiceProvider;
use App\Core\MasterData\Taxes\TaxServiceProvider;
use App\Core\Notifications\NotificationsServiceProvider;
use App\Core\Rbac\RbacServiceProvider;
use App\Core\Workflow\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    IdentityServiceProvider::class,
    CurrencyServiceProvider::class,
    RbacServiceProvider::class,
    TaxServiceProvider::class,
    MasterDataServiceProvider::class,
    WorkflowServiceProvider::class,
    NotificationsServiceProvider::class,
    AutomationServiceProvider::class,
];
