<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// POS-04, NFR-04: what the server noticed about an uploaded shift without
// refusing it (an opener or closer without the permission, a placeholder
// made for sales whose shift never arrived), for back-office review.
// Null: nothing noticed. The table keeps its row-level security.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->jsonb('flags')->nullable();
        });

        DB::statement("alter table pos_shifts add constraint pos_shifts_flags_check check (flags is null or jsonb_typeof(flags) = 'array')");
    }

    public function down(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dropColumn('flags');
        });
    }
};
