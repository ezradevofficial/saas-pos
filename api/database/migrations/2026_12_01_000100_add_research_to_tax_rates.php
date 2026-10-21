<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CP-01, CP-02: a pack rate may come from public research (ResearchStatus):
// `research` holds its confidence, research date and sources, null for a
// confirmed rate (or one still needed). Tenants' rate rows copied from the
// pack carry it too, so a tax line knows it used a researched rate.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('country_pack_tax_codes', function (Blueprint $table) {
            $table->jsonb('research')->nullable();
        });

        Schema::table('tax_rates', function (Blueprint $table) {
            $table->jsonb('research')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tax_rates', fn (Blueprint $table) => $table->dropColumn('research'));
        Schema::table('country_pack_tax_codes', fn (Blueprint $table) => $table->dropColumn('research'));
    }
};
