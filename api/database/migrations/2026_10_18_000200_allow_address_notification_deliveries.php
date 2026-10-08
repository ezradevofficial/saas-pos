<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR 009, NOT-02: a system message (an invitation) may go to a contact
// that is not a user yet. Its delivery has no user_id; `recipient` holds
// the address, and only email and SMS are possible. The row stays a tenant
// row under the same row-level security policy.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table notification_deliveries alter column user_id drop not null');
        DB::statement(<<<'SQL'
            alter table notification_deliveries add constraint notification_deliveries_address_check
            check (user_id is not null or (channel in ('email', 'sms') and recipient is not null and digest is null and notification_id is null))
            SQL);
    }

    public function down(): void
    {
        DB::statement('alter table notification_deliveries drop constraint if exists notification_deliveries_address_check');
        DB::statement('alter table notification_deliveries alter column user_id set not null');
    }
};
