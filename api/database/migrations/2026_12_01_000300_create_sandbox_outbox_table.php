<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// API-05 (review, docs/adr/020-public-api.md): a developer sandbox sends no
// email, SMS or WhatsApp; each message it would have sent is kept here for
// the developer to read (Settings, API keys, outbox). Tenant table under RLS.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandbox_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('channel', 20);
            $table->string('recipient', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('body');
            // notification (the Notifier's deliveries) or mail (any other message).
            $table->string('source', 20);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("alter table sandbox_outbox add constraint sandbox_outbox_source_check check (source in ('notification', 'mail'))");
        Rls::enable('sandbox_outbox');
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_outbox');
    }
};
