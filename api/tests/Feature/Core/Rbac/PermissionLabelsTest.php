<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Rbac\PermissionRegistry;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

// RBAC-01, ADR 020 review: every core resource has a label in English and
// French, so the roles screen and the API key scope picker never show a raw key.
class PermissionLabelsTest extends TestCase
{
    public function test_every_core_resource_is_labelled_in_both_languages(): void
    {
        $missing = [];

        foreach (array_keys(PermissionRegistry::CORE) as $resource) {
            foreach (['en', 'fr'] as $locale) {
                if (! Lang::hasForLocale("rbac.catalogue.resources.core.{$resource}", $locale)) {
                    $missing[] = "{$locale}: {$resource}";
                }
            }
        }

        $this->assertSame([], $missing);
    }

    // Phase 8 walkthrough: Accounting, Inventory, Stores, Fixed assets and
    // Manufacturing (and pos.till, payroll.pay) showed raw keys in the API
    // key scope picker and the roles screen. Every module and resource of
    // the whole catalogue now has a label in both languages.
    public function test_every_module_and_resource_of_the_catalogue_is_labelled_in_both_languages(): void
    {
        $missing = [];

        foreach (app(PermissionRegistry::class)->all() as ['module' => $module, 'resource' => $resource]) {
            foreach (['en', 'fr'] as $locale) {
                foreach (["rbac.catalogue.modules.{$module}", "rbac.catalogue.resources.{$module}.{$resource}"] as $key) {
                    if (! Lang::hasForLocale($key, $locale)) {
                        $missing["{$locale}: {$key}"] = true;
                    }
                }
            }
        }

        $this->assertSame([], array_keys($missing));
    }
}
