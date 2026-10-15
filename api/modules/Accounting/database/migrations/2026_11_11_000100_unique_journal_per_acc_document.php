<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ACC-32: a journal belongs to one document at most, so the supplier
// invoice written from a Procurement invoice's journal is written once,
// however often the event comes or however many workers take it.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create unique index acc_documents_journal_unique on acc_documents (journal_id) where journal_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists acc_documents_journal_unique');
    }
};
