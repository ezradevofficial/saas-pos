<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stage 7B: what is left on a gift card past its expiry date is written
// off by an `expire` entry (breakage, raised as GiftCardExpired for
// Accounting). The kind check gains `expire`; nothing else changes.
//
// Stage 7B closing review: the check is rebuilt from the kinds it allows
// now plus (or, on rollback, less) this migration's own kind, never from a
// list typed here, so kinds a later migration adds are kept.
return new class extends Migration
{
    private const KIND = 'expire';

    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        $this->check([...array_diff($this->kinds(), [self::KIND]), self::KIND]);
    }

    public function down(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        $this->check(array_values(array_diff($this->kinds(), [self::KIND])));
    }

    /** @return list<string> the kinds the check allows now */
    private function kinds(): array
    {
        $definition = (string) DB::scalar("select pg_get_constraintdef(oid) from pg_constraint where conrelid = 'loyalty_gift_card_entries'::regclass and conname = 'loyalty_gift_card_entries_kind_check'");
        // Only the kind list: the amount condition is not a kind.
        preg_match("/kind\)?::text = ANY \(\(?ARRAY\[(.*?)\]/", $definition, $list) || preg_match('/kind in \((.*?)\)/i', $definition, $list);
        preg_match_all("/'([a-z_]+)'/", $list[1] ?? '', $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @param list<string> $kinds */
    private function check(array $kinds): void
    {
        DB::statement('alter table loyalty_gift_card_entries drop constraint loyalty_gift_card_entries_kind_check');
        DB::statement("alter table loyalty_gift_card_entries add constraint loyalty_gift_card_entries_kind_check check (kind in ('".implode("', '", $kinds)."') and amount_minor <> 0)");
    }
};
