<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Ecommerce\Delivery\RiderUpdates;

// Review M1, M5 (ECOM-40, KE DPA): rider updates stored before the follow-ups
// may hold positions, and their fingerprint was a plain sha256 from which a
// position or a 6-digit delivery code could be searched back. Positions are
// cleared, location rows (now never kept) removed, and every fingerprint
// re-keyed as the HMAC of the old one (RiderUpdates::keyed), which is how
// new fingerprints are made, so resends still match. Runs as the schema
// owner across tenants (ADR 002); no business record is touched.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ecom_delivery_updates')->where('kind', 'location')->delete();
        DB::table('ecom_delivery_updates')->whereNotNull('latitude')->orWhereNotNull('longitude')->update(['latitude' => null, 'longitude' => null]);

        DB::table('ecom_delivery_updates')->select(['id', 'payload_hash'])->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('ecom_delivery_updates')->where('id', $row->id)->update(['payload_hash' => RiderUpdates::keyed((string) $row->payload_hash)]);
            }
        });
    }

    public function down(): void
    {
        // The old fingerprints and positions are not brought back.
    }
};
