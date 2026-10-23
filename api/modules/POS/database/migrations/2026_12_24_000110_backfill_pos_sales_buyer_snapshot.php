<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\POS\Fiscal\BuyerSnapshotBackfill;

// POS-08, POS-10, NFR-08 (owner decision 2026-10-22): sales stored before
// the buyer snapshot get their customer's tax identity, in chunks
// (BuyerSnapshotBackfill), then the both-or-neither check added NOT VALID
// by 2026_12_24_000100 is validated. Outside a transaction, so each chunk
// commits on its own and the earlier migration's lock is released.
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        BuyerSnapshotBackfill::run(DB::connection());
        DB::statement('alter table pos_sales validate constraint pos_sales_buyer_check');
    }

    public function down(): void
    {
        // The snapshot columns go with 2026_12_24_000100.
    }
};
