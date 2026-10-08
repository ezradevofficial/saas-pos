<?php

namespace App\Core\Identity;

use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\LocalDate;
use App\Core\Notifications\NotificationAddress;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonInterface;

/**
 * NOT-02, ADR 009: invitations (AUTH-05) and new-device sign-in alerts
 * (AUTH-10) go through the Notifier as system event types, so they have
 * templates and a delivery log. Both are mandatory: nobody can switch
 * them off or hold them for a digest.
 *
 * One-time codes (sign-up, two-step and password-reset codes) do not:
 * they stay with Identity\Notifications\VerificationCode (ADR 009).
 */
final class IdentityNotices
{
    public const INVITED = 'core.identity.invited';

    public const NEW_DEVICE = 'core.identity.new_device';

    public function __construct(private readonly Notifier $notifier) {}

    public static function register(EventTypes $types): void
    {
        $types->register(new EventType(
            key: self::INVITED,
            placeholders: [
                'tenant_name' => 'Amani Stores',
                'inviter_name' => 'Baraka Njoroge',
                'expires_at' => '12 Oct 2026 14:30 UTC',
                'invitation_url' => 'https://app.example.com/invitations/…',
                'invitation_token' => '…',
            ],
            defaultChannels: [Channels::EMAIL, Channels::SMS],
            channels: [Channels::EMAIL, Channels::SMS],
            langKey: 'auth.notifications.events.invited',
            system: true,
            secrets: ['invitation_url', 'invitation_token'],
            contactsOnly: true,
        ));

        $types->register(new EventType(
            key: self::NEW_DEVICE,
            placeholders: ['time' => '12 Oct 2026 14:30 UTC', 'device' => 'Firefox on Windows', 'ip' => '192.0.2.10'],
            defaultChannels: [Channels::EMAIL],
            channels: [Channels::EMAIL, Channels::SMS],
            langKey: 'auth.notifications.events.new_device',
            system: true,
        ));
    }

    /**
     * The invitation link, by email or SMS to the invited contact, in the
     * tenant's language. The token is a secret: it reaches the message at
     * hand-over only and is never stored (the invitation keeps its hash).
     */
    public function invited(Invitation $invitation, string $token, Tenant $tenant, User $inviter): void
    {
        $locale = $tenant->default_locale;
        [$channel, $to] = $invitation->email !== null ? [Channels::EMAIL, $invitation->email] : [Channels::SMS, $invitation->phone];

        $this->notifier->send(new NotificationEvent(
            self::INVITED,
            [],
            [
                'tenant_name' => $tenant->name,
                'inviter_name' => $inviter->name,
                'expires_at' => LocalDate::format($invitation->expires_at, $locale),
            ],
            '/invitations/{invitation_token}',
            [new NotificationAddress($channel, (string) $to, $invitation->name, $locale)],
            [
                'invitation_token' => $token,
                'invitation_url' => rtrim((string) config('app.frontend_url'), '/').'/invitations/'.$token,
            ],
        ));
    }

    /** Someone signed in to $user's account from a device not seen before. */
    public function newDevice(User $user, string $ip, ?string $userAgent, CarbonInterface $at): void
    {
        $this->notifier->send(new NotificationEvent(self::NEW_DEVICE, [$user], [
            'time' => LocalDate::format($at, $user->locale),
            'device' => $userAgent ?: '—',
            'ip' => $ip,
        ]));
    }
}
