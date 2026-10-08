<?php

namespace Tests\Support\Workflow;

use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use Illuminate\Support\Str;

/**
 * An in-memory "module table" for the test document types: documents
 * belong to a tenant and are invisible from another (as row-level
 * security would make them), so the engine's accessors behave like a real
 * module's.
 */
final class TestDocuments
{
    /** @var array<string, array{type: string, tenant: string, values: array<string, mixed>, scope: DocumentScope, status: string}> */
    private static array $documents = [];

    public static function reset(): void
    {
        self::$documents = [];
    }

    /** @param array<string, mixed> $values */
    public static function create(string $type, array $values, DocumentScope $scope, string $status = 'draft'): string
    {
        $id = (string) Str::uuid7();
        self::$documents[$id] = [
            'type' => $type,
            'tenant' => app(TenantContext::class)->require(),
            'values' => $values,
            'scope' => $scope,
            'status' => $status,
        ];

        return $id;
    }

    /** @return array{type: string, tenant: string, values: array<string, mixed>, scope: DocumentScope, status: string}|null */
    public static function find(string $type, string $id): ?array
    {
        $document = self::$documents[$id] ?? null;

        if ($document === null || $document['type'] !== $type || $document['tenant'] !== app(TenantContext::class)->id()) {
            return null;
        }

        return $document;
    }

    /** @param array<string, mixed> $values */
    public static function update(string $id, array $values): void
    {
        self::$documents[$id]['values'] = [...self::$documents[$id]['values'], ...$values];
    }

    public static function move(string $id, DocumentScope $scope): void
    {
        self::$documents[$id]['scope'] = $scope;
    }

    public static function setStatus(string $id, string $status): void
    {
        self::$documents[$id]['status'] = $status;
    }

    /** @return list<array{id: string, type: string, values: array<string, mixed>, status: string}> */
    public static function ofType(string $type): array
    {
        $found = [];

        foreach (self::$documents as $id => $document) {
            if ($document['type'] === $type && $document['tenant'] === app(TenantContext::class)->id()) {
                $found[] = ['id' => $id, 'type' => $type, 'values' => $document['values'], 'status' => $document['status']];
            }
        }

        return $found;
    }
}
