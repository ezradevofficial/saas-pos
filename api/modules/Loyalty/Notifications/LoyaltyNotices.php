<?php

namespace Modules\Loyalty\Notifications;

use App\Core\Identity\Models\User;
use App\Core\Limits\UsageNotices;
use App\Core\MasterData\Parties\Party;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\Programme;

/**
 * NOT-02: the module's notifications, through the core service, each sent
 * once per subject and key (`loyalty_notices`):
 *
 * - `loyalty.points.expiring` (LOY-06): points of a company come within
 *   the expiry notice window; to holders of `loyalty.programme.edit`.
 * - `loyalty.credit.limit_near` / `limit_exceeded` (LOY-19): a charge takes
 *   a balance to the warning percentage of the limit or above it, once a
 *   day per account; to holders of `loyalty.credit.manage`.
 * - `loyalty.gift_card.low` (LOY-24): a redemption takes a card below the
 *   programme's threshold, once per card; to holders of `loyalty.gift_card.issue`.
 *
 * Customers are not users: these go to staff (NOT-01).
 */
class LoyaltyNotices
{
    public const POINTS_EXPIRING = 'loyalty.points.expiring';

    public const CREDIT_NEAR = 'loyalty.credit.limit_near';

    public const CREDIT_EXCEEDED = 'loyalty.credit.limit_exceeded';

    public const GIFT_CARD_LOW = 'loyalty.gift_card.low';

    public function __construct(
        private readonly Notifier $notifier,
        private readonly TenantContext $tenants,
    ) {}

    public static function register(EventTypes $types): void
    {
        $samples = [
            self::POINTS_EXPIRING => ['company_name' => 'Duka Moja Ltd', 'points' => '1,250', 'customers' => '14', 'date' => '2026-11-01'],
            self::CREDIT_NEAR => ['party_name' => 'Duka Moja Ltd', 'balance' => 'KES 80,000.00', 'limit' => 'KES 100,000.00', 'percent' => '80'],
            self::CREDIT_EXCEEDED => ['party_name' => 'Duka Moja Ltd', 'balance' => 'KES 112,000.00', 'limit' => 'KES 100,000.00', 'percent' => '112'],
            self::GIFT_CARD_LOW => ['card' => '•••• 7Q2M', 'balance' => 'KES 150.00'],
        ];

        foreach ($samples as $key => $placeholders) {
            $types->register(new EventType(
                key: $key,
                placeholders: $placeholders,
                defaultChannels: [Channels::IN_APP],
                langKey: 'loyalty.notifications.'.str_replace('.', '_', Str::after($key, 'loyalty.')),
            ));
        }
    }

    /** LOY-19: after a charge, tell the account's managers when the balance nears or passes the limit. */
    public function creditLevel(Account $account, ?Programme $programme): void
    {
        $limit = Party::query()->whereKey($account->party_id)->first()?->creditLimit();

        if ($limit === null || $limit->currency() !== $account->currency || BigInteger::of($limit->minor())->isZero()) {
            return;
        }

        $balance = BigInteger::of((string) $account->balance);
        $limitMinor = BigInteger::of($limit->minor());
        $percent = $balance->multipliedBy(100)->quotient($limitMinor);
        $warn = $programme?->credit_warning_percent ?? 80;
        $type = match (true) {
            $balance->isGreaterThan($limitMinor) => self::CREDIT_EXCEEDED,
            $percent->isGreaterThanOrEqualTo($warn) => self::CREDIT_NEAR,
            default => null,
        };

        if ($type === null) {
            return;
        }

        $this->once($type, $account->id, now()->format('Y-m-d'), fn () => $this->send($type, $this->holders('loyalty.credit.manage', $account->company_id), [
            'party_name' => (string) Party::query()->whereKey($account->party_id)->value('name'),
            'balance' => UsageNotices::money((int) $account->balance, $account->currency),
            'limit' => UsageNotices::money((int) $limit->minor(), $limit->currency()),
            'percent' => (string) $percent,
        ], '/loyalty/credit/'.$account->id));
    }

    /** LOY-24: a card below the programme's threshold after a redemption. */
    public function giftCardLevel(GiftCard $card, ?Programme $programme): void
    {
        $threshold = $programme?->gift_card_low_minor;

        if ($threshold === null || BigInteger::of((string) $card->balance_minor)->isGreaterThanOrEqualTo((string) $threshold)) {
            return;
        }

        $this->once(self::GIFT_CARD_LOW, $card->id, 'low', fn () => $this->send(self::GIFT_CARD_LOW, $this->holders('loyalty.gift_card.issue', $card->company_id), [
            'card' => '•••• '.$card->last4,
            'balance' => UsageNotices::money((int) $card->balance_minor, $card->currency),
        ], '/loyalty/gift-cards/'.$card->id));
    }

    /** LOY-06: points of a company entering the expiry window (once a day per company). */
    public function pointsExpiring(Programme $programme, string $points, int $customers, string $date): void
    {
        $this->once(self::POINTS_EXPIRING, $programme->company_id, $date, fn () => $this->send(self::POINTS_EXPIRING, $this->holders('loyalty.programme.edit', $programme->company_id), [
            'company_name' => (string) $programme->company()->value('name'),
            'points' => number_format((int) $points),
            'customers' => (string) $customers,
            'date' => $date,
        ], '/loyalty/programme'));
    }

    /** Active users holding $permission at the company (or above it). @return list<string> */
    public function holders(string $permission, string $companyId): array
    {
        return User::query()->where('status', User::STATUS_ACTIVE)
            ->whereIn('id', RoleAssignment::query()->select('user_id'))
            ->orderBy('id')->get()
            ->filter(fn (User $user) => $user->can($permission, Scope::company($companyId)))
            ->modelKeys();
    }

    /** Run $send only the first time this kind, subject and key are seen. */
    private function once(string $kind, string $subjectId, string $key, \Closure $send): void
    {
        $inserted = DB::connection(TenantContext::CONNECTION)->table('loyalty_notices')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenants->require(),
            'kind' => $kind,
            'subject_id' => $subjectId,
            'key' => $key,
            'sent_at' => now(),
        ]);

        if ($inserted === 1) {
            $send();
        }
    }

    /** @param list<string> $recipients */
    private function send(string $type, array $recipients, array $data, string $link): void
    {
        if ($recipients !== []) {
            $this->notifier->send(new NotificationEvent($type, $recipients, $data, $link));
        }
    }
}
