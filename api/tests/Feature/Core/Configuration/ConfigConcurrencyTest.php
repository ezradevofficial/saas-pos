<?php

namespace Tests\Feature\Core\Configuration;

use App\Core\Audit\AuditEntry;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Configuration\TestLayoutKind;
use Tests\TestCase;

/**
 * LAY-06, AUD-01: two people on one draft. Saves and publishing name the
 * draft revision they edited or reviewed; a stale one is refused with 409
 * config_changed and the document as it is now. Draft saves are audited
 * by payload hash and size, publishing with the full payloads.
 */
class ConfigConcurrencyTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private const URL = '/api/v1/config/'.TestLayoutKind::KEY;

    protected function setUp(): void
    {
        parent::setUp();
        TestLayoutKind::register();
        $this->setUpOrganisation();
    }

    private function columns(string ...$ids): array
    {
        return ['columns' => array_map(fn ($id) => ['id' => $id], $ids)];
    }

    private function save(array $body): TestResponse
    {
        return $this->postJson(self::URL, ['scope_type' => 'tenant', 'payload' => $this->columns('name'), ...$body], $this->headersFor());
    }

    public function test_a_stale_revision_is_refused_with_the_current_document(): void
    {
        $created = $this->save([])->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame(1, $created->json('data.draft.revision'));

        // Ana saves over revision 1: revision 2.
        $this->putJson(self::URL."/{$id}/draft", ['revision' => 1, 'payload' => $this->columns('code')], $this->headersFor())
            ->assertOk()->assertJsonPath('data.draft.revision', 2);

        // Ben still edits revision 1, through either route, or names none: refused, with Ana's draft.
        foreach ([
            fn () => $this->putJson(self::URL."/{$id}/draft", ['revision' => 1, 'payload' => $this->columns('price')], $this->headersFor()),
            fn () => $this->save(['revision' => 1, 'payload' => $this->columns('price')]),
            fn () => $this->save(['payload' => $this->columns('price')]),
            fn () => $this->postJson(self::URL."/{$id}/publish", ['revision' => 1], $this->headersFor()),
            fn () => $this->postJson(self::URL."/{$id}/discard-draft", ['revision' => 1], $this->headersFor()),
        ] as $stale) {
            $stale()->assertConflict()
                ->assertJsonPath('code', 'config_changed')
                ->assertJsonPath('message', __('config.errors.config_changed'))
                ->assertJsonPath('data.id', $id)
                ->assertJsonPath('data.draft.revision', 2)
                ->assertJsonPath('data.draft.payload.columns.0.id', 'code')
                ->assertJsonPath('data.published', null);
        }

        // Publishing the revision reviewed works once; the draft is gone after it.
        $this->postJson(self::URL."/{$id}/publish", ['revision' => 2], $this->headersFor())->assertOk()->assertJsonPath('data.published.revision', 2);
        $this->postJson(self::URL."/{$id}/publish", ['revision' => 2], $this->headersFor())->assertConflict()->assertJsonPath('code', 'config_changed');
        $this->putJson(self::URL."/{$id}/draft", ['revision' => 2, 'payload' => $this->columns('price')], $this->headersFor())->assertConflict();

        // A new draft starts above every revision the document had, so an old revision never matches it.
        $this->putJson(self::URL."/{$id}/draft", ['payload' => $this->columns('price')], $this->headersFor())->assertOk()->assertJsonPath('data.draft.revision', 3);
        $this->postJson(self::URL."/{$id}/discard-draft", ['revision' => 3], $this->headersFor())->assertOk()->assertJsonPath('data.draft', null);
        $this->save(['revision' => null, 'payload' => $this->columns('name')])->assertOk()->assertJsonPath('data.draft.revision', 4);

        // French too.
        $this->postJson(self::URL."/{$id}/publish", ['revision' => 3], [...$this->headersFor(), 'Accept-Language' => 'fr'])
            ->assertConflict()->assertJsonPath('message', trans('config.errors.config_changed', [], 'fr'));
        $this->assertNotSame(trans('config.errors.config_changed', [], 'en'), trans('config.errors.config_changed', [], 'fr'));
    }

    public function test_draft_saves_are_audited_by_hash_and_publishing_with_the_payloads(): void
    {
        $id = $this->save([])->assertCreated()->json('data.id');
        $this->putJson(self::URL."/{$id}/draft", ['revision' => 1, 'payload' => $this->columns('code')], $this->headersFor())->assertOk();
        $this->postJson(self::URL."/{$id}/publish", ['revision' => 2], $this->headersFor())->assertOk();

        $entries = $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $id)->orderBy('seq')->get()->keyBy('action'));
        $json = json_encode($this->columns('code'));

        $update = $entries['core.config.draft_update'];
        $this->assertEquals(['version' => 1, 'revision' => 2, 'payload_sha256' => hash('sha256', $json), 'payload_bytes' => strlen($json)], $update->after);
        $this->assertSame(hash('sha256', json_encode($this->columns('name'))), $update->before['payload_sha256']);
        $this->assertArrayNotHasKey('payload', $update->before);
        $this->assertArrayNotHasKey('payload', $entries['core.config.draft_create']->after);

        $this->assertSame($this->columns('code'), $entries['core.config.publish']->after['payload']);
    }
}
