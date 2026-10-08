<?php

namespace App\Core\Fiscal;

use App\Core\Fiscal\Console\ProcessFiscalQueueCommand;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use Illuminate\Support\ServiceProvider;

/**
 * Fiscal transmission (concept note 7.2): authority drivers
 * (FiscalDrivers), document sources registered by modules (FiscalSources:
 * the POS registers `pos`), the queue and its scheduler command, and the
 * alerts to fiscal administrators. Routes are in routes/api.php.
 */
class FiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FiscalDrivers::class);
        $this->app->singleton(FiscalSources::class);
    }

    public function boot(): void
    {
        $events = $this->app->make(EventTypes::class);
        $events->register(new EventType(
            key: FiscalAlert::REJECTED,
            placeholders: ['document_type' => 'Sale', 'document_number' => 'R-OUT1-000123', 'company_name' => 'Duka Bora Ltd', 'error' => 'Item Sugar 1kg: its tax code VAT_STD has no fiscal code.'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
            langKey: 'fiscal.notifications.rejected',
        ));
        $events->register(new EventType(
            key: FiscalAlert::DELAYED,
            placeholders: ['document_type' => 'Sale', 'document_number' => 'R-OUT1-000123', 'company_name' => 'Duka Bora Ltd', 'hours' => '6', 'error' => 'The tax authority could not be reached.'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
            langKey: 'fiscal.notifications.delayed',
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessFiscalQueueCommand::class]);
        }
    }
}
