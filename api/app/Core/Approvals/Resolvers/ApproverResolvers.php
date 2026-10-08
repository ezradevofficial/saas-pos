<?php

namespace App\Core\Approvals\Resolvers;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * APR-02: the kinds of approver approval nodes may name. The core
 * registers branch_manager, department_head, cost_centre_owner,
 * manager_levels_up, role and user (and the unlisted `stage_roles`, the
 * default of a node naming no approver); modules register more.
 */
class ApproverResolvers
{
    /** @var array<string, ApproverResolver> */
    private array $resolvers = [];

    /** @var array<string, true> keys not offered in the builder */
    private array $unlisted = [];

    public function __construct(private readonly Container $container) {}

    /** @param ApproverResolver|class-string<ApproverResolver> $resolver */
    public function register(ApproverResolver|string $resolver, bool $listed = true): void
    {
        $instance = is_string($resolver) ? $this->container->make($resolver) : $resolver;

        if (preg_match('/^[a-z][a-z0-9_]{0,49}$/', $instance->key()) !== 1) {
            throw new InvalidArgumentException("Invalid approver type [{$instance->key()}].");
        }

        $this->resolvers[$instance->key()] = $instance;

        if (! $listed) {
            $this->unlisted[$instance->key()] = true;
        }
    }

    public function find(string $key): ?ApproverResolver
    {
        return $this->resolvers[$key] ?? null;
    }

    /**
     * What the builder lists (GET workflow/document-types `meta.approver_types`).
     *
     * @return list<array{key: string, label: string, params: list<array<string, mixed>>}>
     */
    public function describeAll(): array
    {
        $types = [];

        foreach ($this->resolvers as $key => $resolver) {
            if (! isset($this->unlisted[$key])) {
                $types[] = ['key' => $key, 'label' => __($resolver->label()), 'params' => $resolver->params()];
            }
        }

        return $types;
    }
}
