<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PRC-08 (review): an open tender's public notice token is random. Its
// sha256 is what the portal looks up (`public_token_hash`); the token is
// kept encrypted with the application key (`public_token_secret`) so staff
// can see the link again. Never exported (EXP-02); ADR 019.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_tenders', function (Blueprint $table) {
            $table->text('public_token_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('procurement_tenders', function (Blueprint $table) {
            $table->dropColumn('public_token_secret');
        });
    }
};
