<?php

namespace Modules\Loyalty\GiftCards;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Ledgers\Ledgers;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Support\LoyaltyError;

/**
 * LOY-20..LOY-23: gift cards and vouchers.
 *
 * - Codes are 16 characters from an alphabet without look-alikes (no 0/O,
 *   1/I/L), printed in groups of four. The server keeps the SHA-256 of the
 *   normalised code (upper case, spaces and dashes removed) and the last
 *   four characters; the code is answered once, at issue.
 * - Issued `active` (a voucher given away, or a card sold at once) or
 *   `inactive` (stock to sell later, at the till or here).
 */
class GiftCards
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 16;

    public function __construct(
        private readonly Ledgers $ledgers,
        private readonly Auditor $auditor,
    ) {}

    public static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $code));
    }

    /**
     * M8: the code's proof, H(code): what a till uploads with a tender or a
     * sale, and sends to the live lookup. Never stored.
     */
    public static function proof(string $code): string
    {
        return hash('sha256', self::normalise($code));
    }

    /** The stored and synced hash, H(H(code)), from a proof (hex, any case). */
    public static function hashProof(string $proof): string
    {
        return hash('sha256', strtolower($proof));
    }

    /** The stored and synced hash of a code, H(H(code)). */
    public static function hash(string $code): string
    {
        return self::hashProof(self::proof($code));
    }

    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($code, 4));
    }

    /**
     * Issue $count cards; answers each card with its code (shown once).
     *
     * @param  array{company_id: string, kind: string, currency: string, amount_minor: string, expires_on?: ?string, active: bool, party_id?: ?string, reference?: ?string}  $values
     * @return list<array{card: GiftCard, code: string}>
     */
    public function issue(array $values, int $count, User $by): array
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($values, $count, $by) {
            $issued = [];

            for ($i = 0; $i < $count; $i++) {
                $code = self::generate();
                $card = GiftCard::create([
                    'company_id' => $values['company_id'],
                    'kind' => $values['kind'],
                    'code_hash' => self::hash($code),
                    'last4' => substr(self::normalise($code), -4),
                    'currency' => $values['currency'],
                    'initial_minor' => $values['amount_minor'],
                    'balance_minor' => 0,
                    'status' => GiftCard::INACTIVE,
                    'expires_on' => $values['expires_on'] ?? null,
                    'party_id' => $values['party_id'] ?? null,
                    'issued_by' => $by->id,
                    'issued_at' => CarbonImmutable::now(),
                ]);
                $this->auditor->record('loyalty.gift_card.issue', $card, null, [
                    'kind' => $card->kind, 'last4' => $card->last4, 'value' => ['amount_minor' => (string) $card->initial_minor, 'currency' => $card->currency],
                    'expires_on' => $values['expires_on'] ?? null, 'active' => $values['active'],
                ], ['user_id' => $by->id]);

                if ($values['active']) {
                    $card = $this->load($card, (string) $card->initial_minor, 'issue', $card->id, $values['reference'] ?? null, $by);
                }

                $issued[] = ['card' => $card, 'code' => $code];
            }

            return $issued;
        });
    }

    /** LOY-21: sell an inactive card in the back office (its value is loaded; the payment reference is kept). */
    public function sell(GiftCard $card, ?string $amountMinor, ?string $reference, User $by): GiftCard
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($card, $amountMinor, $reference, $by) {
            $card = $this->ledgers->lockCard($card->id);

            if ($card->status !== GiftCard::INACTIVE) {
                throw LoyaltyError::make('gift_card_not_inactive');
            }

            $card = $this->load($card, $amountMinor ?? (string) $card->initial_minor, 'manual_sale', $card->id, $reference, $by);
            $this->auditor->record('loyalty.gift_card.sell', $card, ['status' => GiftCard::INACTIVE], [
                'status' => $card->status, 'value' => ['amount_minor' => (string) $card->balance_minor, 'currency' => $card->currency], 'reference' => $reference,
            ], ['user_id' => $by->id]);

            return $card;
        });
    }

    /**
     * Load a locked card with $amount and activate it (sold, given, or sold
     * at the till). Returns the card.
     */
    public function load(GiftCard $card, string $amount, string $sourceType, ?string $sourceId, ?string $reference, ?User $by, ?string $saleId = null, array $flags = []): GiftCard
    {
        $this->ledgers->giftCard($card, 'load', $amount, [
            'source_type' => $sourceType, 'source_id' => $sourceId, 'sale_id' => $saleId, 'reference' => $reference, 'created_by' => $by?->id, 'flags' => $flags,
        ]);

        if ($card->status === GiftCard::INACTIVE) {
            $card->forceFill(['status' => GiftCard::ACTIVE, 'activated_at' => CarbonImmutable::now(), 'sold_reference' => $reference])->save();
        }

        return $card;
    }

    public function block(GiftCard $card, bool $blocked, User $by): GiftCard
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($card, $blocked, $by) {
            $card = $this->ledgers->lockCard($card->id);
            $before = $card->status;

            if ($blocked === ($before === GiftCard::BLOCKED)) {
                throw LoyaltyError::make($blocked ? 'gift_card_blocked' : 'gift_card_not_blocked');
            }

            // Unblocked, a card is active again once it was ever loaded, else inactive.
            $after = $blocked ? GiftCard::BLOCKED : ($card->activated_at !== null ? GiftCard::ACTIVE : GiftCard::INACTIVE);
            $card->forceFill(['status' => $after])->save();
            $this->auditor->record($blocked ? 'loyalty.gift_card.block' : 'loyalty.gift_card.unblock', $card, ['status' => $before], ['status' => $after], ['user_id' => $by->id]);

            return $card;
        });
    }

    /** LOY-23: the card a code names in the tenant (by hash), or null. */
    public function find(string $code): ?GiftCard
    {
        return GiftCard::query()->where('code_hash', self::hash($code))->first();
    }
}
