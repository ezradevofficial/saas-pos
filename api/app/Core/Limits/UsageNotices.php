<?php

namespace App\Core\Limits;

use App\Core\Currency\Money;
use App\Core\Limits\Models\Overage;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\LocalDate;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\OwnerGuard;
use App\Core\Tenancy\Models\Tenant;

/**
 * LIM-02, LIM-03: the Owners hear about a unit above the plan in the app
 * and by email: when it starts (with the extra cost from the catalogue
 * quote), every day of the 7-day window, and when adding more is paused.
 * Each links to the subscription page (one-click upgrade). System event
 * types (ADR 009): account messages, never switched off or digested.
 */
final class UsageNotices
{
    public const EXCEEDED = 'core.usage_limit.exceeded';

    public const REMINDER = 'core.usage_limit.reminder';

    public const BLOCKED = 'core.usage_limit.blocked';

    private const SAMPLES = [
        'item' => 'POS devices',
        'count' => '4',
        'allowed' => '2',
        'over' => '2',
        'extra_cost' => 'KES 1,000.00 a month',
        'days_left' => '7',
        'blocks_on' => '19 Oct 2026 06:00 UTC',
    ];

    public function __construct(
        private readonly Notifier $notifier,
        private readonly OwnerGuard $owners,
    ) {}

    public static function register(EventTypes $types): void
    {
        foreach ([self::EXCEEDED, self::REMINDER, self::BLOCKED] as $key) {
            $types->register(new EventType(
                key: $key,
                placeholders: self::SAMPLES,
                defaultChannels: [Channels::IN_APP, Channels::EMAIL],
                channels: [Channels::IN_APP, Channels::EMAIL],
                langKey: 'limits.notifications.'.substr($key, strrpos($key, '.') + 1),
                system: true,
            ));
        }
    }

    /** @param  array{currency: string, units: array<string, int>}|null  $cost */
    public function send(string $type, Overage $overage, ?array $cost): void
    {
        $owners = $this->owners->ownerIds();

        if ($owners === []) {
            return;
        }

        $locale = Tenant::query()->value('default_locale') ?: 'en';
        $amount = $cost['units'][$overage->unit] ?? null;

        $this->notifier->send(new NotificationEvent($type, $owners, [
            'item' => __("limits.items.{$overage->unit}", [], $locale),
            'count' => (string) $overage->count,
            'allowed' => (string) $overage->allowed,
            'over' => (string) ($overage->count - $overage->allowed),
            'extra_cost' => $amount === null
                ? __('limits.cost.unpriced', [], $locale)
                : __('limits.cost.monthly', ['amount' => self::money($amount, $cost['currency'], $locale)], $locale),
            'days_left' => (string) $overage->daysLeft(now()),
            'blocks_on' => LocalDate::format($overage->blocksAt(), $locale),
        ], (string) config('limits.upgrade_path', '/settings/subscription')));
    }

    /** "KES 1,000.00" (en) or "KES 1 000,00" (fr): the currency code first (CLAUDE.md). */
    public static function money(int $minor, string $currency, string $locale = 'en'): string
    {
        [$whole, $fraction] = array_pad(explode('.', Money::ofMinor($minor, $currency)->toDecimalString()), 2, '');
        [$group, $point] = $locale === 'fr' ? ["\u{202F}", ','] : [',', '.'];
        $whole = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', $group, $whole);

        return $currency.' '.$whole.($fraction === '' ? '' : $point.$fraction);
    }
}
