<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CF-05, CF-04, TEN-06, AUD-01: a draft's lines are kept by id (created,
// updated in place, archived when removed) and attachments taken off a
// draft are archived, never deleted. A line's position is unique among
// the record's active lines only, so archived lines keep theirs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_form_lines', function (Blueprint $table) {
            $table->timestampTz('archived_at')->nullable();
            $table->dropUnique(['record_id', 'position']);
        });

        DB::statement('create unique index custom_form_lines_position_unique on custom_form_lines (record_id, position) where archived_at is null');

        Schema::table('custom_form_attachments', function (Blueprint $table) {
            $table->timestampTz('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        DB::statement('drop index if exists custom_form_lines_position_unique');

        Schema::table('custom_form_attachments', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });

        Schema::table('custom_form_lines', function (Blueprint $table) {
            $table->dropColumn('archived_at');
            $table->unique(['record_id', 'position']);
        });
    }
};
