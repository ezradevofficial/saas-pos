<?php

namespace App\Core\Onboarding;

/**
 * ONB-02: the business type presets and the picker's module groups, read
 * from presets.php (data, not code).
 */
class BusinessTypes
{
    /** @var array{business_types: list<array<string, mixed>>, module_groups: array<string, list<string>>}|null */
    private ?array $presets = null;

    /** @return list<array{key: string, pos_mode: ?string, role_templates: list<string>, flows: list<string>, modules: list<string>, quantities: array<string, int>, features: list<string>}> */
    public function all(): array
    {
        return $this->presets()['business_types'];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->all(), 'key');
    }

    /** @return array{key: string, pos_mode: ?string, role_templates: list<string>, flows: list<string>, modules: list<string>, quantities: array<string, int>}|null */
    public function find(?string $key): ?array
    {
        foreach ($this->all() as $type) {
            if ($type['key'] === $key) {
                return $type;
            }
        }

        return null;
    }

    /** @return array<string, list<string>> */
    public function moduleGroups(): array
    {
        return $this->presets()['module_groups'];
    }

    /** With names and descriptions in the request's language. */
    public function present(): array
    {
        return array_map(fn (array $type) => [
            ...$type,
            'name' => __("onboarding.business_types.{$type['key']}.name"),
            'description' => __("onboarding.business_types.{$type['key']}.description"),
        ], $this->all());
    }

    /** @return list<string> every module some business type switches on with it (`features`) */
    public function featureModules(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (array $type) => $type['features'], $this->all()))));
    }

    private function presets(): array
    {
        if ($this->presets === null) {
            $presets = require __DIR__.'/presets.php';
            $presets['business_types'] = array_map(fn (array $type) => $type + ['features' => []], $presets['business_types']);
            $this->presets = $presets;
        }

        return $this->presets;
    }
}
