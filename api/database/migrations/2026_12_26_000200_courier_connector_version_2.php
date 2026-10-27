<?php

use App\Core\Marketplace\Connectors\FirstParty;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MKT-04, security review M4 (ADR 024): the courier connector no longer asks
// for core.party.read, which would read every customer's contacts; it gets
// the customer of an order handed to it from the courier routes. A reviewed
// version never changes (trigger), so where version 1 was seeded with the
// scope, version 2 is added with FirstParty's scopes, approved and listed.
// No tenant has installed the connector yet (it was not available), so
// nobody has to consent again; an install of version 1 would keep its
// consented scopes until it consents (ADR 024, scope changes).
return new class extends Migration
{
    private const VERSION_1 = '019a0000-0000-7000-8000-00000000b001';

    public function up(): void
    {
        $courier = FirstParty::CONNECTORS['courier'];
        $listed = DB::table('marketplace_app_versions')->where('id', DB::table('marketplace_apps')->where('id', $courier['app'])->value('listed_version_id'))->first();

        if ($listed === null || $listed->id === $courier['version'] || ! in_array('core.party.read', json_decode((string) $listed->scopes, true) ?: [], true)) {
            return;
        }

        // Migrated before and rolled back: the approved version 2 is still there, list it again.
        if (DB::table('marketplace_app_versions')->where('id', $courier['version'])->where('status', 'approved')->exists()) {
            DB::table('marketplace_apps')->where('id', $courier['app'])->update(['listed_version_id' => $courier['version']]);

            return;
        }

        $now = now();
        DB::table('marketplace_app_versions')->insert([
            'id' => $courier['version'],
            'app_id' => $courier['app'],
            'developer_tenant_id' => null,
            'version' => (int) $listed->version + 1,
            'status' => 'draft',
            'name' => $courier['name'],
            'tagline' => $courier['tagline'],
            'description' => $courier['description'],
            'category' => $courier['category'],
            'redirect_uris' => $listed->redirect_uris,
            'scopes' => json_encode($courier['scopes']),
            'webhook_url' => $listed->webhook_url,
            'webhook_events' => json_encode($courier['events']),
            'pricing' => json_encode(['model' => 'free']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('marketplace_app_versions')->where('id', $courier['version'])->update(['status' => 'submitted', 'submitted_at' => $now]);
        DB::table('marketplace_app_versions')->where('id', $courier['version'])->update(['status' => 'approved', 'reviewed_at' => $now, 'review_note' => 'First-party connector: core.party.read removed (security review M4)']);
        DB::table('marketplace_apps')->where('id', $courier['app'])->update(['listed_version_id' => $courier['version']]);
    }

    public function down(): void
    {
        $courier = FirstParty::CONNECTORS['courier'];

        if (DB::table('marketplace_app_versions')->where('id', self::VERSION_1)->exists()) {
            DB::table('marketplace_apps')->where('id', $courier['app'])->update(['listed_version_id' => self::VERSION_1]);
        }
    }
};
