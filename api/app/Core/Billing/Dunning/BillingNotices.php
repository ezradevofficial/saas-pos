<?php

namespace App\Core\Billing\Dunning;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Exports\ExportValues;
use App\Core\Localisation\TenantLocale;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\OwnerGuard;
use Carbon\CarbonInterface;

/**
 * SUB-05, SUB-06: billing messages to the tenant's Owners, in the app and
 * by email (NOT-02). Amounts carry their currency code first; dates are
 * the day the subscription's clock uses (UTC).
 */
class BillingNotices
{
    public const TRIAL_ENDING = 'core.subscription.trial_ending';

    public const RENEWAL_DUE = 'core.subscription.renewal_due';

    public const PAST_DUE = 'core.subscription.past_due';

    public const READ_ONLY = 'core.subscription.read_only';

    public const PAYMENT_RECEIVED = 'core.subscription.payment_received';

    public const LINK = '/settings/subscription';

    public function __construct(
        private readonly Notifier $notifier,
        private readonly OwnerGuard $owners,
        private readonly CurrencyDecimals $decimals,
        private readonly TenantLocale $locale,
    ) {}

    public static function register(EventTypes $types): void
    {
        $sample = ['amount' => 'KES 3,900.00', 'date' => '2026-11-01'];
        $common = ['defaultChannels' => [Channels::IN_APP, Channels::EMAIL], 'mandatoryAllowed' => true];

        $types->register(new EventType(key: self::TRIAL_ENDING, placeholders: [...$sample, 'days' => '7'], langKey: 'billing.notifications.trial_ending', ...$common));
        $types->register(new EventType(key: self::RENEWAL_DUE, placeholders: [...$sample, 'days' => '3'], langKey: 'billing.notifications.renewal_due', ...$common));
        $types->register(new EventType(key: self::PAST_DUE, placeholders: [...$sample, 'number' => 'INV-2026-000012', 'read_only_date' => '2026-11-08'], langKey: 'billing.notifications.past_due', ...$common));
        $types->register(new EventType(key: self::READ_ONLY, placeholders: [...$sample, 'number' => 'INV-2026-000012', 'pos_until' => '2026-11-11'], langKey: 'billing.notifications.read_only', ...$common));
        $types->register(new EventType(key: self::PAYMENT_RECEIVED, placeholders: [...$sample, 'number' => 'INV-2026-000012'], langKey: 'billing.notifications.payment_received', ...$common));
    }

    /** @param array<string, string|int|null> $data */
    public function send(string $event, array $data): void
    {
        $owners = $this->owners->ownerIds();

        if ($owners === []) {
            return;
        }

        $this->notifier->send(new NotificationEvent($event, $owners, $data, self::LINK));
    }

    public function money(int $minor, string $currency): string
    {
        return (new ExportValues($this->locale->current(), 'UTC', [], $this->decimals))->money(['amount_minor' => $minor, 'currency' => $currency]);
    }

    public static function date(?CarbonInterface $at): string
    {
        return $at === null ? '' : $at->toDateString();
    }
}
