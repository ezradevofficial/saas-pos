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
use App\Core\Billing\Overage\SubscriptionAllowances;
use App\Core\Billing\Subscriptions\BillingTrial;
use App\Core\Fiscal\FiscalSources;
use App\Core\Limits\PlanAllowances;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Onboarding\Trial\TrialExpiry;
use App\Core\Onboarding\Trial\TrialStarter;
use App\Core\Onboarding\Trial\TrialStatusProvider;
use App\Core\Payments\Events\PaymentIntentSettled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The platform's own billing (ADR 016): subscriptions, operator invoices
 * (fiscal source `billing`, numbering type `core.billing_invoice`, never
 * shown to tenants: its module is not a tenant module), payments settled
 * from the operator's payment intents, dunning notifications and the
 * scheduler commands. Overage comes from metering (App\Core\Limits\
 * UsageOverage), priced here (OveragePricing, LIM-05); plan allowances
 * follow the subscription (SubscriptionAllowances, LIM-01).
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Operator::class);
        $this->app->scoped(PlatformSettings::class);
        // LIM-01: limits follow the paid plan (Limits' default reads the onboarding selection).
        $this->app->singleton(PlanAllowances::class, SubscriptionAllowances::class);
        // ONB-04: the trial is the subscription's (onboarding binds its defaults with bindIf).
        $this->app->singleton(BillingTrial::class);
        $this->app->singleton(TrialStarter::class, BillingTrial::class);
        $this->app->singleton(TrialStatusProvider::class, BillingTrial::class);
        $this->app->singleton(TrialExpiry::class, BillingTrial::class);
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
