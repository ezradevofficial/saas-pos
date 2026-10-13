<?php

namespace App\Core\Billing;

use App\Core\Billing\Console\CreateOperator;
use App\Core\Billing\Console\ModulePurgeReport;
use App\Core\Billing\Console\RunBillingCycleCommand;
use App\Core\Billing\Dunning\BillingNotices;
use App\Core\Billing\Invoicing\BillingFiscalSource;
use App\Core\Billing\Invoicing\Invoicing;
use App\Core\Billing\Listeners\SettleBillingPayment;
use App\Core\Billing\Operator\Operator;
use App\Core\Billing\Overage\NoUsageOverage;
use App\Core\Billing\Overage\UsageOverage;
use App\Core\Fiscal\FiscalSources;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Payments\Events\PaymentIntentSettled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The platform's own billing (ADR 016): subscriptions, operator invoices
 * (fiscal source `billing`, numbering type `core.billing_invoice`, never
 * shown to tenants: its module is not a tenant module), payments settled
 * from the operator's payment intents, dunning notifications and the
 * scheduler commands. UsageOverage defaults to none until metering binds
 * its own (LIM-05, Task 3).
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Operator::class);
        $this->app->scoped(PlatformSettings::class);
        $this->app->bindIf(UsageOverage::class, NoUsageOverage::class);
    }

    public function boot(): void
    {
        $this->app->make(FiscalSources::class)->register(BillingFiscalSource::KEY, BillingFiscalSource::class);
        $this->app->make(DocumentNumberTypes::class)->register(new DocumentNumberType(Invoicing::NUMBER_TYPE, 'platform', 'INV-{YYYY}-{000001}', NumberFormat::RESET_YEARLY, [], langKey: 'billing.invoices.number_type'));
        BillingNotices::register($this->app->make(EventTypes::class));
        Event::listen(PaymentIntentSettled::class, [SettleBillingPayment::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([CreateOperator::class, RunBillingCycleCommand::class, ModulePurgeReport::class]);
        }
    }
}
