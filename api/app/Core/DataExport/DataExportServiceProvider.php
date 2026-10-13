<?php

namespace App\Core\DataExport;

use App\Core\DataExport\Console\ExpireExportsCommand;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use Illuminate\Support\ServiceProvider;

/**
 * EXP-02: full tenant data exports (docs/adr/015-tenant-export.md): the
 * "export ready" notification and the clean-up command.
 */
class DataExportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(EventTypes::class)->register(new EventType(
            key: TenantExports::READY_EVENT,
            placeholders: ['size' => '12.4 MB', 'expires_at' => '19 Oct 2026 14:30 UTC'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            channels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
            langKey: 'data_export.notifications.ready',
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireExportsCommand::class]);
        }
    }
}
