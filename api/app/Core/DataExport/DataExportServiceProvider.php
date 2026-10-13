<?php

namespace App\Core\DataExport;

use App\Core\DataExport\Console\ExpireExportsCommand;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * EXP-02: full tenant data exports (docs/adr/015-tenant-export.md): the
 * "export ready" notification, the download limiter and the clean-up command.
 */
class DataExportServiceProvider extends ServiceProvider
{
    /** Downloads of one export per hour, whoever holds a link. */
    public const DOWNLOAD_LIMITER = 'tenant-export-download';

    public function boot(): void
    {
        RateLimiter::for(self::DOWNLOAD_LIMITER, fn (Request $request) => Limit::perHour(max(1, (int) config('data_export.downloads_per_hour', 5)))
            ->by('tenant-export|'.(string) $request->route('export')));

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
