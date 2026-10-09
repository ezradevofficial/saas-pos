<?php

namespace Tests\Feature\Core\Sync;

use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * BR-02, BR-08, NFR-04: the till's settings carry the theme that applies
 * at its branch (else its company's, else the tenant's), stored and
 * compiled per mode, for the POS to apply with NativeWind vars().
 */
class ThemeSyncTest extends TestCase
{
    use BuildsTill, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sync.snapshot_ttl_seconds' => 0]);
        $this->setUpOrganisation();
    }

    private function publish(array $scope, array $payload): void
    {
        $id = $this->postJson('/api/v1/config/theme', [...$scope, 'payload' => $payload], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/config/theme/{$id}/publish", ['revision' => $this->getJson("/api/v1/config/theme/{$id}", $this->headersFor())->json('data.draft.revision')], $this->headersFor())->assertOk();
    }

    private function theme(array $till): array
    {
        return $this->pull($till, ['settings'])->assertOk()->json('entities.settings.upserts.0.theme');
    }

    public function test_the_settings_entity_carries_the_theme_of_the_tills_branch(): void
    {
        $tillA = $this->pairTill($this->locationA, 'Till A');
        $tillB = $this->pairTill($this->locationB, 'Till B');

        $this->assertSame(['payload' => ['preset' => 'light'], 'tokens' => ['light' => [], 'dark' => []], 'scope' => null, 'version' => null], $this->theme($tillA));

        $this->publish(['scope_type' => 'tenant'], ['preset' => 'executive', 'colors' => ['primary' => '#0b5d6e']]);
        $this->publish(['scope_type' => 'branch', 'scope_id' => $this->branchA->id], ['preset' => 'warm', 'colors' => ['primary' => '#7c2d12'], 'corners' => 'sharp']);

        $a = $this->theme($tillA);
        $this->assertSame(['type' => 'branch', 'id' => $this->branchA->id], $a['scope']);
        $this->assertSame('#7c2d12', $a['tokens']['light']['primary']);
        $this->assertSame('2px', $a['tokens']['dark']['radius-md']);
        $this->assertNotSame('#7c2d12', $a['tokens']['dark']['primary'], 'dark mode lifts a dark brand colour');

        $b = $this->theme($tillB);
        $this->assertSame(['type' => 'tenant', 'id' => null], $b['scope']);
        $this->assertSame('executive', $b['payload']['preset']);
        $this->assertSame('#0b5d6e', $b['tokens']['light']['primary']);
    }
}
