<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NFR-08 (KE DPA): every marketing message carries an opt-out.
// - consent_records.source gains `opt_out_link` (the unsubscribe link of a
//   marketing email) and `opt_out_reply` (a STOP reply by SMS or WhatsApp).
//   Only the check constraint changes; no row is touched, so the
//   append-only trigger is not involved.
// - notification_deliveries.opted_out_at: when the delivery's unsubscribe
//   link was used, so a link withdraws consent once (a second use answers
//   "already done" and records nothing).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table consent_records drop constraint consent_records_source_check');
        DB::statement("alter table consent_records add constraint consent_records_source_check check (source in ('back_office', 'storefront', 'import', 'lead_form', 'opt_out_link', 'opt_out_reply'))");

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->timestampTz('opted_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropColumn('opted_out_at');
        });

        DB::statement('alter table consent_records drop constraint consent_records_source_check');
        DB::statement("alter table consent_records add constraint consent_records_source_check check (source in ('back_office', 'storefront', 'import', 'lead_form'))");
    }
};
