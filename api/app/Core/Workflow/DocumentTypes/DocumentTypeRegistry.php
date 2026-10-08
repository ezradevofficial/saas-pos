<?php

namespace App\Core\Workflow\DocumentTypes;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The document types modules register (WF-01). A type of a module the
 * tenant has not activated is hidden (RBAC-08): it is neither listed nor
 * found.
 */
class DocumentTypeRegistry
{
    public const KEY = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /** @var array<string, DocumentType|class-string<DocumentType>> */
    private array $types = [];

    public function __construct(
        private readonly Container $container,
        private readonly ModuleRegistry $modules,
    ) {}

    /** @param DocumentType|class-string<DocumentType> $type */
    public function register(DocumentType|string $type): void
    {
        $instance = is_string($type) ? $this->container->make($type) : $type;
        $key = $instance->key();

        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException("Invalid document type key [{$key}]: use `module.document`.");
        }

        $this->types[$key] = $instance;
    }

    /** The type when registered and its module is active for the tenant, else null. */
    public function find(string $key): ?DocumentType
    {
        $type = $this->types[$key] ?? null;

        if ($type === null || ! $this->modules->isActive(PermissionRegistry::moduleOf($key))) {
            return null;
        }

        return $type;
    }

    public function get(string $key): DocumentType
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown document type [{$key}].");
    }

    /** @return array<string, DocumentType> active types by key, sorted */
    public function all(): array
    {
        $types = [];

        foreach (array_keys($this->types) as $key) {
            if (($type = $this->find($key)) !== null) {
                $types[$key] = $type;
            }
        }

        ksort($types);

        return $types;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
