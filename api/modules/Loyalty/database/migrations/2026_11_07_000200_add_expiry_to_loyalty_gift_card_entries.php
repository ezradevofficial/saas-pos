<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stage 7B: what is left on a gift card past its expiry date is written
// off by an `expire` entry (breakage, raised as GiftCardExpired for
// Accounting). The kind check gains `expire`; nothing else changes.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");
        DB::statement('alter table loyalty_gift_card_entries drop constraint loyalty_gift_card_entries_kind_check');
        DB::statement("alter table loyalty_gift_card_entries add constraint loyalty_gift_card_entries_kind_check check (kind in ('load', 'redeem', 'reverse_redeem', 'refund_credit', 'adjust', 'expire') and amount_minor <> 0)");
    }

    public function down(): void
    {
        DB::statement('alter table loyalty_gift_card_entries drop constraint loyalty_gift_card_entries_kind_check');
        DB::statement("alter table loyalty_gift_card_entries add constraint loyalty_gift_card_entries_kind_check check (kind in ('load', 'redeem', 'reverse_redeem', 'refund_credit', 'adjust') and amount_minor <> 0)");
    }
};
