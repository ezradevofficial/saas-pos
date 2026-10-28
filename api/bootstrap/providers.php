<?php

use App\Core\Api\ApiServiceProvider;
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
use App\Core\Manual\ManualServiceProvider;
use App\Core\Marketplace\MarketplaceServiceProvider;
use App\Core\MasterData\CreditLimits\CreditLimitsServiceProvider;
use App\Core\MasterData\MasterDataServiceProvider;
use App\Core\MasterData\Taxes\TaxServiceProvider;
use App\Core\Notifications\NotificationsServiceProvider;
use App\Core\Numbering\NumberingServiceProvider;
use App\Core\Observability\ObservabilityServiceProvider;
use App\Core\Onboarding\OnboardingServiceProvider;
use App\Core\Payments\PaymentsServiceProvider;
use App\Core\Platform\PlatformServiceProvider;
use App\Core\Privacy\PrivacyServiceProvider;
use App\Core\Rbac\RbacServiceProvider;
use App\Core\Sso\SsoServiceProvider;
use App\Core\Sync\SyncServiceProvider;
use App\Core\Webhooks\WebhooksServiceProvider;
use App\Core\Workflow\WorkflowServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;
use App\Providers\HorizonServiceProvider;
use Modules\Accounting\AccountingServiceProvider;
use Modules\CRM\CrmServiceProvider;
use Modules\Ecommerce\Courier\CourierApiServiceProvider;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\FixedAssets\FixedAssetsServiceProvider;
use Modules\FuelStation\FuelStationServiceProvider;
use Modules\Hotel\HotelServiceProvider;
use Modules\HR\HrServiceProvider;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Manufacturing\ManufacturingServiceProvider;
use Modules\Payroll\PayrollServiceProvider;
use Modules\Pharmacy\PharmacyServiceProvider;
use Modules\POS\PosServiceProvider;
use Modules\Procurement\ProcurementServiceProvider;
use Modules\Restaurant\RestaurantServiceProvider;
use Modules\Salon\SalonServiceProvider;
use Modules\Stores\StoresServiceProvider;
use Modules\VanSales\VanSalesServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    ObservabilityServiceProvider::class,
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
    SsoServiceProvider::class,
    LayoutsServiceProvider::class,
    NotificationsServiceProvider::class,
    AutomationServiceProvider::class,
    ApprovalsServiceProvider::class,
    CreditLimitsServiceProvider::class,
    CustomFormsServiceProvider::class,
    ImportsServiceProvider::class,
    DataExportServiceProvider::class,
    PrivacyServiceProvider::class,
    WebhooksServiceProvider::class,
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
    FixedAssetsServiceProvider::class,
    CrmServiceProvider::class,
    EcommerceServiceProvider::class,
    // MKT-04 (ADR 024): the courier's public API routes.
    CourierApiServiceProvider::class,
    PayrollServiceProvider::class,
    RestaurantServiceProvider::class,
    PharmacyServiceProvider::class,
    HotelServiceProvider::class,
    FuelStationServiceProvider::class,
    VanSalesServiceProvider::class,
    SalonServiceProvider::class,
    // MKT-01..MKT-04 (ADR 024): the marketplace, OAuth for apps.
    MarketplaceServiceProvider::class,
    // API-01..API-05: last, so the sandbox's set-up runs after every other sign-up listener.
    ApiServiceProvider::class,
    // The printed user manual (docs/manual): `manual:build`.
    ManualServiceProvider::class,
    HorizonServiceProvider::class,
];
