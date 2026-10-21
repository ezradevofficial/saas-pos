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
}
