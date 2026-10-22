<?php

use App\Core\Marketplace\Connectors\FirstParty;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MKT-04 (docs/adr/024-marketplace.md): the first-party connectors,
// listed as free apps of the platform's own developer, in every
// environment (like the catalogue, ADR 013). Each is approved and listed;
// its OAuth client stays revoked, with no redirect URI, until the owner
// sets up the connector service (`php artisan marketplace:connector`):
// until then the listing says "not configured" and nobody can install it.
// Texts are stored once (names and texts are not per-language).
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('marketplace_developers')->insert([
            'id' => FirstParty::DEVELOPER,
            'developer_tenant_id' => null,
            'name' => (string) config('app.name'),
            'email' => (string) config('mail.from.address'),
            'first_party' => true,
            'status' => 'active',
            'terms_accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (FirstParty::CONNECTORS as $connector) {
            DB::table('oauth_clients')->insert([
                'id' => $connector['client'],
                'name' => $connector['name'],
                'secret' => null,
                'redirect_uris' => '[]',
                'grant_types' => json_encode(['authorization_code', 'refresh_token']),
                // Not configured until the owner sets the connector service up.
                'revoked' => true,
                'app_id' => $connector['app'],
                'developer_tenant_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('marketplace_apps')->insert([
                'id' => $connector['app'],
                'developer_id' => FirstParty::DEVELOPER,
                'developer_tenant_id' => null,
                'slug' => $connector['slug'],
                'status' => 'active',
                'client_id' => $connector['client'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Its webhook key, in the table no runtime role reads (security review, ADR 024).
            DB::table('marketplace_app_secrets')->insert(['app_id' => $connector['app'], 'client_secret_sha256' => null, 'webhook_key' => DB::raw("decode('".bin2hex(random_bytes(32))."', 'hex')"), 'created_at' => $now, 'updated_at' => $now]);

            DB::table('marketplace_app_versions')->insert([
                'id' => $connector['version'],
                'app_id' => $connector['app'],
                'developer_tenant_id' => null,
                'version' => 1,
                'status' => 'draft',
                'name' => $connector['name'],
                'tagline' => $connector['tagline'],
                'description' => $connector['description'],
                'category' => $connector['category'],
                'redirect_uris' => '[]',
                'scopes' => json_encode($connector['scopes']),
                'webhook_events' => json_encode($connector['events']),
                'pricing' => json_encode(['model' => 'free']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Through the review states the trigger allows: draft, submitted, approved; then listed.
            DB::table('marketplace_app_versions')->where('id', $connector['version'])->update(['status' => 'submitted', 'submitted_at' => $now]);
            DB::table('marketplace_app_versions')->where('id', $connector['version'])->update(['status' => 'approved', 'reviewed_at' => $now, 'review_note' => 'First-party connector']);
            DB::table('marketplace_apps')->where('id', $connector['app'])->update(['listed_version_id' => $connector['version']]);
        }
    }

    public function down(): void
    {
        $apps = array_column(FirstParty::CONNECTORS, 'app');
        DB::table('marketplace_apps')->whereIn('id', $apps)->update(['listed_version_id' => null]);
        DB::statement('alter table marketplace_app_versions disable trigger marketplace_versions_guard');
        DB::table('marketplace_app_versions')->whereIn('app_id', $apps)->delete();
        DB::statement('alter table marketplace_app_versions enable trigger marketplace_versions_guard');
        DB::table('marketplace_app_secrets')->whereIn('app_id', $apps)->delete();
        DB::table('marketplace_apps')->whereIn('id', $apps)->delete();
        DB::table('oauth_clients')->whereIn('id', array_column(FirstParty::CONNECTORS, 'client'))->delete();
        DB::table('marketplace_developers')->where('id', FirstParty::DEVELOPER)->delete();
    }
};
