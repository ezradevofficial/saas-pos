<?php

namespace App\Core\Rbac;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The permission catalogue declared in code (RBAC-01). Each module
 * registers its resources and actions; `permissions:sync` writes them to
 * the global `permissions` table as `module.resource.action`.
 */
class PermissionRegistry
{
    /** RBAC-01: the core module's catalogue. */
    public const CORE = [
        'company' => ['view', 'create', 'edit', 'archive'],
        'branch' => ['view', 'create', 'edit', 'archive'],
        'location' => ['view', 'create', 'edit', 'archive'],
        'device' => ['view', 'create', 'edit', 'archive', 'pair'],
        'user' => ['view', 'invite', 'edit', 'deactivate'],
        'role' => ['view', 'create', 'edit', 'archive', 'assign'],
        'audit' => ['view', 'export'],
        'settings' => ['edit'],
        'access_review' => ['view', 'export'],
        'currency' => ['view', 'edit'],
        'exchange_rate' => ['view', 'override'],
        'tax' => ['view', 'edit'],
        'price_list' => ['view', 'edit'],
        // MD-03 follow-up: item prices in a company's price lists (read: the POS sells with them).
        'price' => ['view', 'edit'],
        'party' => ['view', 'create', 'edit', 'archive'],
        // MD-01, WF-01: ask for a party's credit limit to change (through its
        // flow), act on that flow (approve: stages naming no roles, cancel,
        // return), or set a limit directly, raises included (Owner, Admin).
        'credit_limit' => ['request', 'approve', 'set_directly'],
        'master_data_settings' => ['edit'],
        'item' => ['view', 'create', 'edit', 'archive'],
        'item_category' => ['view', 'create', 'edit', 'archive'],
        'uom' => ['view', 'edit'],
        'payment_method' => ['view', 'create', 'edit', 'archive', 'configure'],
        'dimension' => ['view', 'create', 'edit', 'archive'],
        // WF-01..WF-11, APR-09: flow definitions; documents move through stage roles (WF-08).
        'workflow' => ['view', 'edit', 'publish'],
        // APR-04, APR-06: oversight of every approval at a place, and reassigning
        // pending ones (also what makes a role a manager for approver resolution).
        // Acting on an approval needs no permission: being its approver.
        'approval' => ['view_all', 'reassign'],
        'notification_template' => ['view', 'edit'],
        'notification_settings' => ['edit'],
        'notification_delivery' => ['view'],
        // AUTO-01..AUTO-07: automation rules and their run log.
        'automation' => ['view', 'edit'],
        // Concept note 7.1: payment intents and money received (view), matching
        // received money to payments by hand (match).
        'payment' => ['view', 'match'],
        // Concept note 7.2: fiscal settings and the queue to the tax authority
        // (view); non-secret settings, retries and sending earlier sales
        // (edit); the authority's credentials, initialisation, the driver
        // and switching transmission on or off (configure: Owner, Admin).
        'fiscal' => ['view', 'edit', 'configure'],
        // NUM-01: how documents are numbered, per type and tenant, company or branch.
        'numbering' => ['view', 'edit'],
        // LAY-06: versioned configuration (themes, layouts, templates), the
        // default for kinds that bring no permissions of their own.
        'config' => ['view', 'edit', 'publish'],
        // BR-02, BR-03, BR-08: the tenant theme (preset, colours, logos,
        // sign-in page), per tenant, company or branch.
        'theme' => ['view', 'edit', 'publish'],
        // BR-04..BR-06: the tenant's subdomain, custom domains and the
        // email sender and SMS sender ID (tenant scope; Owner, Admin).
        'domain' => ['manage'],
    ];

    private const SEGMENT = '/^[a-z][a-z0-9_]*$/';

    /** @var array<string, array{name: string, module: string, resource: string, action: string}> */
    private array $permissions = [];

    /**
     * @param  array<string, list<string>>  $resources  resource => actions
     */
    public function register(string $module, array $resources): void
    {
        foreach ($resources as $resource => $actions) {
            foreach ($actions as $action) {
                foreach ([$module, $resource, $action] as $segment) {
                    if (! is_string($segment) || preg_match(self::SEGMENT, $segment) !== 1) {
                        throw new InvalidArgumentException("Invalid permission segment [{$segment}] in module [{$module}].");
                    }
                }

                $name = "{$module}.{$resource}.{$action}";
                $this->permissions[$name] = compact('name', 'module', 'resource', 'action');
            }
        }
    }

    /** @return Collection<string, array{name: string, module: string, resource: string, action: string}> */
    public function all(): Collection
    {
        return collect($this->permissions)->sortKeys();
    }

    public function has(string $name): bool
    {
        return isset($this->permissions[$name]);
    }

    /** True for strings shaped like a permission name (`module.resource.action`). */
    public static function isPermissionName(string $ability): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $ability) === 1;
    }

    public static function moduleOf(string $permission): string
    {
        return strstr($permission, '.', true) ?: $permission;
    }
}
