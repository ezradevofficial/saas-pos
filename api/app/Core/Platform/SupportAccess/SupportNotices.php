<?php

namespace App\Core\Platform\SupportAccess;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\LocalDate;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Tenant;

/**
 * RBAC-13b: support access told to the tenant's users who decide on it
 * (`core.support_access.manage`: the Owner and Admin templates), in the
 * app and by email. Platform account messages (`system`): always on.
 */
class SupportNotices
{
    public const REQUESTED = 'core.support_access.requested';

    public const STARTED = 'core.support_access.started';

    public const ENDED = 'core.support_access.ended';

    public const LINK = '/settings/security/support-access';

    public function __construct(
        private readonly Notifier $notifier,
        private readonly ScopeResolver $resolver,
    ) {}

    public static function register(EventTypes $types): void
    {
        $sample = ['staff_name' => 'Amina (support)', 'reason' => 'Help with the stock count import', 'hours' => '24', 'mode' => 'read-only'];
        $events = [
            self::REQUESTED => $sample,
            self::STARTED => [...$sample, 'until' => '19 Oct 2026 06:00 UTC'],
            self::ENDED => [...$sample, 'how' => 'revoked'],
        ];

        foreach ($events as $key => $placeholders) {
            $types->register(new EventType(
                key: $key,
                placeholders: $placeholders,
                defaultChannels: [Channels::IN_APP, Channels::EMAIL],
                channels: [Channels::IN_APP, Channels::EMAIL],
                langKey: 'platform.support.notifications.'.substr($key, strlen('core.support_access.')),
                system: true,
            ));
        }
    }

    public function requested(SupportAccessGrant $grant): void
    {
        $this->send(self::REQUESTED, $grant);
    }

    public function started(SupportAccessGrant $grant): void
    {
        $this->send(self::STARTED, $grant, ['until' => $grant->expires_at === null ? '' : LocalDate::format($grant->expires_at, $this->locale())]);
    }

    public function ended(SupportAccessGrant $grant): void
    {
        $this->send(self::ENDED, $grant, ['how' => __('platform.support.end_reasons.'.($grant->end_reason ?? 'ended'), [], $this->locale())]);
    }

    /** @param  array<string, string>  $extra */
    private function send(string $event, SupportAccessGrant $grant, array $extra = []): void
    {
        $recipients = $this->resolver->holders('core.support_access.manage', Scope::tenant());

        if ($recipients === []) {
            return;
        }

        $locale = $this->locale();
        $this->notifier->send(new NotificationEvent($event, $recipients, [
            'staff_name' => $grant->staff_name,
            'reason' => $grant->reason,
            'hours' => (string) $grant->hours,
            'mode' => __('platform.support.modes.'.$grant->mode, [], $locale),
            ...$extra,
        ], self::LINK));
    }

    private function locale(): string
    {
        return (string) (Tenant::query()->value('default_locale') ?: 'en');
    }
}
