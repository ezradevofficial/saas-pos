<?php

use App\Core\Approvals\ApprovalsServiceProvider;
use App\Core\Automation\AutomationServiceProvider;
use App\Core\Currency\CurrencyServiceProvider;
use App\Core\Fiscal\FiscalServiceProvider;
use App\Core\Identity\IdentityServiceProvider;
use App\Core\MasterData\CreditLimits\CreditLimitsServiceProvider;
use App\Core\MasterData\MasterDataServiceProvider;
use App\Core\MasterData\Taxes\TaxServiceProvider;
use App\Core\Notifications\NotificationsServiceProvider;
use App\Core\Payments\PaymentsServiceProvider;
use App\Core\Rbac\RbacServiceProvider;
use App\Core\Sync\SyncServiceProvider;
use App\Core\Workflow\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;
use App\Providers\HorizonServiceProvider;

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
    ApprovalsServiceProvider::class,
    CreditLimitsServiceProvider::class,
    SyncServiceProvider::class,
    PaymentsServiceProvider::class,
    FiscalServiceProvider::class,
    HorizonServiceProvider::class,
];
