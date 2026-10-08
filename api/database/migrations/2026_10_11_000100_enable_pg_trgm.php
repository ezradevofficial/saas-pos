<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MD-06: trigram similarity for party names (duplicate warnings, search).
// pg_trgm is a trusted extension: the database owner (the migration role)
// creates it; the runtime role only calls its functions and operators.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists pg_trgm with schema public');
    }

    public function down(): void
    {
        // Kept: dropping it would break any index that uses it.
    }
};
