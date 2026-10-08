<?php

namespace App\Core\Automation\Templates;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Workflow\DocumentTypes\DocumentType;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * AUTO-07: the library of ready-made rules. Modules register theirs in
 * their provider's boot(); a template of a module the tenant has not
 * activated is hidden (RBAC-08).
 */
class RuleTemplates
{
    private const KEY = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /** @var array<string, RuleTemplate> */
    private array $templates = [];

    public function __construct(
        private readonly Container $container,
        private readonly ModuleRegistry $modules,
    ) {}

    /** @param RuleTemplate|class-string<RuleTemplate> $template */
    public function register(RuleTemplate|string $template): void
    {
        $instance = is_string($template) ? $this->container->make($template) : $template;

        if (preg_match(self::KEY, $instance->key()) !== 1) {
            throw new InvalidArgumentException("Invalid rule template key [{$instance->key()}].");
        }

        $this->templates[$instance->key()] = $instance;
    }

    public function find(string $key): ?RuleTemplate
    {
        $template = $this->templates[$key] ?? null;

        return $template !== null && $this->modules->isActive(PermissionRegistry::moduleOf($key)) ? $template : null;
    }

    /** @return array<string, RuleTemplate> active templates by key, sorted */
    public function all(): array
    {
        $all = [];

        foreach (array_keys($this->templates) as $key) {
            if (($template = $this->find($key)) !== null) {
                $all[$key] = $template;
            }
        }

        ksort($all);

        return $all;
    }

    /** @return array<string, RuleTemplate> */
    public function for(DocumentType $type): array
    {
        return array_filter($this->all(), fn (RuleTemplate $template) => $template->appliesTo($type));
    }
}
