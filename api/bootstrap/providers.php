<?php

use App\Core\Approvals\ApprovalsServiceProvider;
use App\Core\Automation\AutomationServiceProvider;
use App\Core\Billing\BillingServiceProvider;
use App\Core\Branding\BrandingServiceProvider;
use App\Core\Configuration\ConfigurationServiceProvider;
use App\Core\Currency\CurrencyServiceProvider;
use App\Core\CustomFields\CustomFieldsServiceProvider;
use App\Core\CustomForms\CustomFormsServiceProvider;
use App\Core\DataExport\DataExportServiceProvider;
use App\Core\DocumentTemplates\DocumentTemplatesServiceProvider;
use App\Core\Fiscal\FiscalServiceProvider;
use App\Core\Identity\IdentityServiceProvider;
use App\Core\Imports\ImportsServiceProvider;
use App\Core\Layouts\LayoutsServiceProvider;
use App\Core\Limits\LimitsServiceProvider;
use App\Core\MasterData\CreditLimits\CreditLimitsServiceProvider;
use App\Core\MasterData\MasterDataServiceProvider;
use App\Core\MasterData\Taxes\TaxServiceProvider;
use App\Core\Notifications\NotificationsServiceProvider;
use App\Core\Numbering\NumberingServiceProvider;
use App\Core\Onboarding\OnboardingServiceProvider;
use App\Core\Payments\PaymentsServiceProvider;
use App\Core\Platform\PlatformServiceProvider;
use App\Core\Rbac\RbacServiceProvider;
use App\Core\Sync\SyncServiceProvider;
use App\Core\Workflow\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;
use App\Providers\HorizonServiceProvider;
use Modules\Accounting\AccountingServiceProvider;
use Modules\HR\HrServiceProvider;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Manufacturing\ManufacturingServiceProvider;
use Modules\POS\PosServiceProvider;
use Modules\Procurement\ProcurementServiceProvider;
use Modules\Stores\StoresServiceProvider;

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
    DocumentTemplatesServiceProvider::class,
    BrandingServiceProvider::class,
    LayoutsServiceProvider::class,
    NotificationsServiceProvider::class,
    AutomationServiceProvider::class,
    ApprovalsServiceProvider::class,
    CreditLimitsServiceProvider::class,
    CustomFormsServiceProvider::class,
    ImportsServiceProvider::class,
    DataExportServiceProvider::class,
    SyncServiceProvider::class,
    PaymentsServiceProvider::class,
    FiscalServiceProvider::class,
    PlatformServiceProvider::class,
    BillingServiceProvider::class,
    LimitsServiceProvider::class,
    OnboardingServiceProvider::class,
    PosServiceProvider::class,
    LoyaltyServiceProvider::class,
    InventoryServiceProvider::class,
    AccountingServiceProvider::class,
    ProcurementServiceProvider::class,
    StoresServiceProvider::class,
    HrServiceProvider::class,
    ManufacturingServiceProvider::class,
    HorizonServiceProvider::class,
];
