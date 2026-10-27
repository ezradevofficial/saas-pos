<?php

namespace Tests\Feature\Core\Onboarding;

use App\Core\Billing\Catalogue\Models\CatalogueModule;
use App\Core\Onboarding\BusinessTypes;
use App\Core\Rbac\RoleTemplates;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * ONB-02, ONB-03: presets.php is data that must stay consistent with the
 * code and catalogue it names: the 11 business types, allowed POS modes,
 * existing role templates, registered document types with a default flow,
 * catalogue module keys and known units, and module groups.
 */
class PresetsTest extends TestCase
{
    use RefreshTenantDatabase;

    private const POS_MODES = ['retail', 'supermarket', 'restaurant', 'bar', 'hotel', 'pharmacy', 'wholesale', 'van_sales', 'salon', 'fuel_station'];

    private const UNITS = ['outlet', 'device', 'warehouse', 'online_store', 'company', 'employee'];

    public function test_business_types_reference_only_what_exists(): void
    {
        $types = app(BusinessTypes::class)->all();
        $templates = array_column(app(RoleTemplates::class)->all(), 'key');
        $documentTypes = app(DocumentTypeRegistry::class);
        $modules = CatalogueModule::query()->where('kind', 'module')->whereNull('archived_at')->pluck('key')->all();

        $this->assertSame(
            ['retail', 'supermarket', 'restaurant', 'bar', 'hotel', 'pharmacy', 'wholesale', 'van_sales', 'salon', 'fuel_station', 'non_pos'],
            array_column($types, 'key'),
        );

        foreach ($types as $type) {
            $key = $type['key'];
            $this->assertSame(['key', 'pos_mode', 'role_templates', 'flows', 'modules', 'quantities'], array_keys($type), $key);
            $this->assertTrue($type['pos_mode'] === null || in_array($type['pos_mode'], self::POS_MODES, true), "{$key}: pos_mode");
            $this->assertSame($type['pos_mode'] === null, ! in_array('pos', $type['modules'], true), "{$key}: a till mode exactly when POS is suggested");
            $this->assertSame([], array_diff($type['role_templates'], $templates), "{$key}: role templates");
            $this->assertNotContains('owner', $type['role_templates'], "{$key}: the Owner is never offered");
            $this->assertSame([], array_diff($type['modules'], $modules), "{$key}: catalogue module keys");
            $this->assertNotSame([], $type['modules'], $key);

            foreach ($type['flows'] as $flow) {
                $documentType = $documentTypes->find($flow);
                $this->assertNotNull($documentType, "{$key}: flow {$flow} is a registered document type");
                $this->assertNotNull($documentType->defaultFlow(null), "{$key}: {$flow} ships a default flow");
            }

            foreach ($type['quantities'] as $unit => $quantity) {
                $this->assertContains($unit, self::UNITS, "{$key}: unit {$unit}");
                $this->assertIsInt($quantity);
                $this->assertGreaterThanOrEqual($unit === 'device' ? 0 : 1, $quantity, "{$key}: {$unit}");
            }

            $this->assertNotSame("onboarding.business_types.{$key}.name", __("onboarding.business_types.{$key}.name"), "{$key}: named in en");
            $this->assertNotSame("onboarding.business_types.{$key}.name", __("onboarding.business_types.{$key}.name", [], 'fr'), "{$key}: named in fr");
        }
    }

    public function test_module_groups_name_catalogue_modules_once(): void
    {
        $groups = app(BusinessTypes::class)->moduleGroups();
        $listed = array_merge(...array_values($groups));
        $modules = CatalogueModule::query()->where('kind', 'module')->whereNull('archived_at')->pluck('key')->all();

        $this->assertSame(['sell', 'operate', 'finance_people'], array_keys($groups));
        $this->assertSame($listed, array_values(array_unique($listed)), 'each module in one group');
        $this->assertSame([], array_diff($listed, $modules));
        $this->assertSame([], array_diff($modules, $listed), 'every sellable module is shown');
    }
}
