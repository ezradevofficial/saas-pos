<?php

namespace Modules\Pharmacy\Alerts;

use App\Core\Approvals\Resolvers\ApproverDirectory;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\TenantContext;

/**
 * PHA-10, PHA-22, PHA-26, NOT-02: the module's notifications, through the
 * core notifier (in-app and email by default). Who receives each is whoever
 * holds a permission where it happened (the location, its branch, company
 * or the whole tenant).
 */
class PharmacyNotices
{
    public const NEAR_EXPIRY = 'pharmacy.stock.near_expiry';

    public const EXPIRED = 'pharmacy.stock.expired';

    public const RECALL = 'pharmacy.recall.issued';

    public const FLAGGED = 'pharmacy.dispense.flagged';

    public function __construct(
        private readonly ApproverDirectory $directory,
        private readonly ScopeResolver $scopes,
        private readonly TenantContext $tenants,
    ) {}

    public static function register(EventTypes $types): void
    {
        $channels = [Channels::IN_APP, Channels::EMAIL];

        $types->register(new EventType(self::NEAR_EXPIRY, ['location_name' => 'Main pharmacy', 'count' => '3', 'items' => 'Amoxicillin 500 mg, Paracetamol 500 mg', 'days' => '90'], $channels, mandatoryAllowed: true, langKey: 'pharmacy.notifications.near_expiry'));
        $types->register(new EventType(self::EXPIRED, ['location_name' => 'Main pharmacy', 'count' => '2', 'items' => 'Amoxicillin 500 mg'], $channels, mandatoryAllowed: true, langKey: 'pharmacy.notifications.expired'));
        $types->register(new EventType(self::RECALL, ['item_name' => 'Amoxicillin 500 mg', 'batch_number' => 'B-2041', 'reason' => 'Contamination found by the maker', 'lots' => '2'], $channels, mandatoryAllowed: true, langKey: 'pharmacy.notifications.recall'));
        $types->register(new EventType(self::FLAGGED, ['location_name' => 'Main pharmacy', 'receipt_number' => 'R-0001', 'flags' => 'prescription_missing'], $channels, mandatoryAllowed: false, langKey: 'pharmacy.notifications.flagged'));
    }

    /** Tell the holders of $permission where $locationId is (the location, its branch, company, or the whole tenant when null). */
    public function send(string $type, string $permission, ?string $locationId, array $data, string $link): void
    {
        $chain = $locationId === null ? ['tenant:'.$this->tenants->require()] : ($this->scopes->chainOf(Scope::location($locationId)) ?? []);
        $users = $this->directory->holdersAt($this->directory->rolesWith($permission), $chain);

        if ($users !== []) {
            app(Notifier::class)->send(new NotificationEvent($type, $users, $data, $link));
        }
    }
}
