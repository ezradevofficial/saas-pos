<?php

namespace App\Core\Rbac;

use InvalidArgumentException;

/**
 * Where a permission applies (RBAC-04): the whole tenant, a company, a
 * branch or a location. Wider scopes cover narrower ones beneath them.
 */
final class Scope
{
    public const TENANT = 'tenant';

    public const COMPANY = 'company';

    public const BRANCH = 'branch';

    public const LOCATION = 'location';

    public const TYPES = [self::TENANT, self::COMPANY, self::BRANCH, self::LOCATION];

    private function __construct(
        public readonly string $type,
        public readonly ?string $id,
    ) {}

    /** The whole tenant. The id is optional: there is only one tenant in context. */
    public static function tenant(?string $tenantId = null): self
    {
        return new self(self::TENANT, $tenantId);
    }

    public static function company(string $id): self
    {
        return new self(self::COMPANY, $id);
    }

    public static function branch(string $id): self
    {
        return new self(self::BRANCH, $id);
    }

    public static function location(string $id): self
    {
        return new self(self::LOCATION, $id);
    }

    public static function of(string $type, ?string $id): self
    {
        return match ($type) {
            self::TENANT => self::tenant($id),
            self::COMPANY, self::BRANCH, self::LOCATION => new self($type, $id ?? throw new InvalidArgumentException("A {$type} scope needs an id.")),
            default => throw new InvalidArgumentException("Unknown scope type [{$type}]."),
        };
    }

    public function isTenant(): bool
    {
        return $this->type === self::TENANT;
    }

    /** @return array{type: string, id: ?string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
