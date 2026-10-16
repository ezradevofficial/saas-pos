<?php

namespace App\Core\Platform\Tenants;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\OwnerGuard;

/**
 * ADM-01c: what platform staff did to the account, told to the tenant's
 * Owners in the app and by email. Platform account messages (`system`):
 * always on, whatever the preferences (ADR 009).
 */
class PlatformNotices
{
    public const SUSPENDED = 'core.platform.tenant_suspended';

    public const REACTIVATED = 'core.platform.tenant_reactivated';

    public const TRIAL_EXTENDED = 'core.platform.trial_extended';

    public const LINK = '/settings/subscription';

    public function __construct(
        private readonly Notifier $notifier,
        private readonly OwnerGuard $owners,
    ) {}

    public static function register(EventTypes $types): void
    {
        $events = [
            self::SUSPENDED => ['reason' => 'Unpaid invoices under review'],
            self::REACTIVATED => ['reason' => 'Review completed'],
            self::TRIAL_EXTENDED => ['reason' => 'More time to evaluate payroll', 'date' => '2026-11-30'],
        ];

        foreach ($events as $key => $placeholders) {
            $types->register(new EventType(
                key: $key,
                placeholders: $placeholders,
                defaultChannels: [Channels::IN_APP, Channels::EMAIL],
                channels: [Channels::IN_APP, Channels::EMAIL],
                langKey: 'platform.notifications.'.substr($key, strlen('core.platform.')),
                system: true,
            ));
        }
    }

    /** @param  array<string, string>  $data */
    public function toOwners(string $event, array $data): void
    {
        $owners = $this->owners->ownerIds();

        if ($owners !== []) {
            $this->notifier->send(new NotificationEvent($event, $owners, $data, self::LINK));
        }
    }
}
