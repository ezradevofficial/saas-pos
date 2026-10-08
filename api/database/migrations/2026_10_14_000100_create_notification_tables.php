<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NOT-01..NOT-06: the core notification service.
// - notifications: the in-app inbox (bell), one row per recipient.
// - notification_deliveries: one row per recipient per channel (in-app,
//   email, push, SMS, WhatsApp) with status, attempts and errors (NOT-06);
//   email held for a digest waits here as `pending_digest` (NOT-05).
// - notification_templates: a tenant's overrides of the default texts per
//   event type, channel (or all) and language (NOT-03).
// - notification_preferences: a user's channels and digest per event type (NOT-04).
// - notification_settings: the channels a tenant makes mandatory per event type (NOT-04).
return new class extends Migration
{
    public const CHANNELS = ['in_app', 'email', 'push', 'sms', 'whatsapp'];

    public const STATUSES = ['queued', 'sending', 'sent', 'delivered', 'failed', 'skipped', 'pending_digest', 'digested'];

    public function up(): void
    {
        $channels = "'".implode("', '", self::CHANNELS)."'";

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('user_id');
            $table->string('event_type', 100);
            $table->text('subject');
            $table->text('body');
            $table->string('link', 2048)->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['user_id', 'archived_at', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
        Rls::enable('notifications');

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('user_id');
            $table->foreignUuid('notification_id')->nullable()->constrained('notifications')->restrictOnDelete();
            $table->uuid('digest_id')->nullable();
            $table->string('event_type', 100);
            $table->string('channel', 20);
            $table->string('status', 20);
            $table->string('reason', 40)->nullable();
            $table->string('recipient', 255)->nullable();
            $table->char('locale', 2);
            $table->text('subject')->nullable();
            $table->text('body');
            $table->string('link', 2048)->nullable();
            $table->string('digest', 10)->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'digest']);
            $table->index('user_id');
            $table->index('digest_id');
        });
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->foreign('digest_id')->references('id')->on('notification_deliveries')->restrictOnDelete();
        });
        DB::statement("alter table notification_deliveries add constraint notification_deliveries_channel_check check (channel in ({$channels}))");
        DB::statement("alter table notification_deliveries add constraint notification_deliveries_status_check check (status in ('".implode("', '", self::STATUSES)."'))");
        DB::statement("alter table notification_deliveries add constraint notification_deliveries_digest_check check (digest is null or digest in ('daily', 'weekly'))");
        DB::statement("alter table notification_deliveries add constraint notification_deliveries_locale_check check (locale in ('en', 'fr'))");
        DB::statement('alter table notification_deliveries add constraint notification_deliveries_attempts_check check (attempts >= 0)');
        Rls::enable('notification_deliveries');

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('event_type', 100);
            $table->string('channel', 20);
            $table->char('locale', 2);
            $table->text('subject')->nullable();
            $table->text('body');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'event_type', 'channel', 'locale']);
        });
        DB::statement("alter table notification_templates add constraint notification_templates_channel_check check (channel in ('all', {$channels}))");
        DB::statement("alter table notification_templates add constraint notification_templates_locale_check check (locale in ('en', 'fr'))");
        Rls::enable('notification_templates');

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('user_id');
            $table->string('event_type', 100);
            // {"email": false, "sms": true}: only the channels the user changed.
            $table->jsonb('channels')->default('{}');
            $table->string('digest', 10)->default('immediate');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->unique(['user_id', 'event_type']);
        });
        DB::statement("alter table notification_preferences add constraint notification_preferences_digest_check check (digest in ('immediate', 'daily', 'weekly'))");
        Rls::enable('notification_preferences');

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('event_type', 100);
            // ["in_app", "email"]: channels users of the tenant cannot switch off.
            $table->jsonb('mandatory_channels')->default('[]');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'event_type']);
        });
        Rls::enable('notification_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
    }
};
