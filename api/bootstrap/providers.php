<?php

use App\Core\Approvals\ApprovalsServiceProvider;
use App\Core\Automation\AutomationServiceProvider;
use App\Core\Branding\BrandingServiceProvider;
use App\Core\Configuration\ConfigurationServiceProvider;
use App\Core\Currency\CurrencyServiceProvider;
use App\Core\CustomFields\CustomFieldsServiceProvider;
use App\Core\Fiscal\FiscalServiceProvider;
use App\Core\Identity\IdentityServiceProvider;
use App\Core\Layouts\LayoutsServiceProvider;
use App\Core\MasterData\CreditLimits\CreditLimitsServiceProvider;
use App\Core\MasterData\MasterDataServiceProvider;
use App\Core\MasterData\Taxes\TaxServiceProvider;
use App\Core\Notifications\NotificationsServiceProvider;
use App\Core\Numbering\NumberingServiceProvider;
use App\Core\Payments\PaymentsServiceProvider;
use App\Core\Rbac\RbacServiceProvider;
use App\Core\Sync\SyncServiceProvider;
use App\Core\Workflow\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;
use App\Providers\HorizonServiceProvider;
use Modules\POS\PosServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    IdentityServiceProvider::class,
    CurrencyServiceProvider::class,
    NumberingServiceProvider::class,
    RbacServiceProvider::class,
    TaxServiceProvider::class,
    MasterDataServiceProvider::class,
    CustomFieldsServiceProvider::class,
    WorkflowServiceProvider::class,
    ConfigurationServiceProvider::class,
    BrandingServiceProvider::class,
    LayoutsServiceProvider::class,
    NotificationsServiceProvider::class,
    AutomationServiceProvider::class,
    ApprovalsServiceProvider::class,
    CreditLimitsServiceProvider::class,
    SyncServiceProvider::class,
    PaymentsServiceProvider::class,
    FiscalServiceProvider::class,
    PosServiceProvider::class,
    HorizonServiceProvider::class,
];
