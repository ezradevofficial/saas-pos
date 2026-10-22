<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NFR-08 (KE DPA): consent evidence and the marketing send gate.
// - consent_records.wording_version: the version of the text the person
//   agreed to (a storefront sign-up names the wording it showed). Adding a
//   nullable column changes no row, so the append-only trigger is untouched.
// - notification_deliveries.consent_subject_id: the customer (a party) a
//   marketing delivery is for, so the send re-checks consent at hand-over
//   (a withdrawal after queueing stops it). Not a foreign key: a delivery
//   outlives nothing it points at, like `recipient`.
// - A marketing message may go to a contact's WhatsApp too, so the address
//   rule now allows whatsapp beside email and SMS.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_records', function (Blueprint $table) {
            $table->string('wording_version', 40)->nullable();
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->uuid('consent_subject_id')->nullable();
            $table->index('consent_subject_id');
        });

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

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex(['consent_subject_id']);
            $table->dropColumn('consent_subject_id');
        });

        Schema::table('consent_records', function (Blueprint $table) {
            $table->dropColumn('wording_version');
        });
    }
};
