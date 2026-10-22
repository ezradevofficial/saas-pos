<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR 009, NOT-01, ECOM-44: a system message to a contact that is not a user
// may also go to a phone number on WhatsApp (through the channel's driver),
// besides email and SMS. Only the channel list of the address check widens.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table notification_deliveries drop constraint notification_deliveries_address_check');
        DB::statement(<<<'SQL'
            alter table notification_deliveries add constraint notification_deliveries_address_check
            check (user_id is not null or (channel in ('email', 'sms', 'whatsapp') and recipient is not null and digest is null and notification_id is null))
            SQL);
    }

    public function down(): void
    {
        DB::statement('alter table notification_deliveries drop constraint notification_deliveries_address_check');
        DB::statement(<<<'SQL'
            alter table notification_deliveries add constraint notification_deliveries_address_check
            check (user_id is not null or (channel in ('email', 'sms') and recipient is not null and digest is null and notification_id is null))
            SQL);
    }
};
