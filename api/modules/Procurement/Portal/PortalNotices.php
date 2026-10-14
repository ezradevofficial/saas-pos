<?php

namespace Modules\Procurement\Portal;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\LocalDate;
use App\Core\Notifications\NotificationAddress;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\Tender;

/**
 * PRC-07, NOT-02, ADR 009: a bidder's portal link goes out through the
 * Notifier as a system message to a contact that is not a user, in the
 * tenant's language. The link token is a secret: it reaches the message
 * at hand-over only and is never stored (the bidder keeps its hash).
 */
final class PortalNotices
{
    public const INVITED = 'procurement.portal.invited';

    public function __construct(
        private readonly Notifier $notifier,
        private readonly TenantContext $tenants,
    ) {}

    public static function register(EventTypes $types): void
    {
        $types->register(new EventType(
            key: self::INVITED,
            placeholders: [
                'company_name' => 'Amani Stores Ltd',
                'tender_number' => 'TND-NBO-2026-00004',
                'tender_title' => 'Supply of office furniture',
                'deadline' => '30 Oct 2026 12:00 EAT',
                'portal_url' => 'https://app.example.com/supplier-portal/…',
                'portal_token' => '…',
            ],
            defaultChannels: [Channels::EMAIL],
            channels: [Channels::EMAIL],
            langKey: 'procurement.notifications.portal_invited',
            system: true,
            secrets: ['portal_url', 'portal_token'],
            contactsOnly: true,
        ));
    }

    public function linkSent(Bidder $bidder, Tender $tender, string $token): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenants->require());
        $locale = $tenant->default_locale ?: 'en';
        $company = Company::query()->find($tender->company_id);

        $this->notifier->send(new NotificationEvent(
            self::INVITED,
            [],
            [
                'company_name' => (string) ($company?->name ?? $tenant->name),
                'tender_number' => (string) $tender->number,
                'tender_title' => $tender->title,
                'deadline' => LocalDate::format($tender->deadline_at, $locale),
            ],
            '/supplier-portal/{portal_token}',
            [new NotificationAddress(Channels::EMAIL, $bidder->email, $bidder->name, $locale)],
            [
                'portal_token' => $token,
                'portal_url' => rtrim((string) config('app.frontend_url'), '/').'/supplier-portal/'.$token,
            ],
        ));
    }
}
