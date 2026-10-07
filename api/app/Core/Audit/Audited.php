<?php

namespace App\Core\Audit;

use Illuminate\Support\Str;

/**
 * Audits a model's create, update, archive and restore (AUD-01, AUD-02).
 *
 * Actions are `{module}.{resource}.{verb}`. A model may declare:
 *   protected string $auditModule = 'core';        // default 'core'
 *   protected string $auditResource = 'company';   // default snake_case class name
 *   protected array $auditHidden = ['password'];   // never written to the log
 * Attributes in $hidden and the created/updated timestamps are never logged.
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(function (self $model) {
            $keys = $model->auditableKeys(array_keys($model->getAttributes()));

            $model->recordAudit('create', null, $model->auditValues($keys, original: false));
        });

        static::updated(function (self $model) {
            // syncChanges() has run; the original is not yet synced.
            $keys = $model->auditableKeys(array_keys($model->getChanges()));

            if ($keys === []) {
                return;
            }

            $model->recordAudit(
                $model->auditVerb(),
                $model->auditValues($keys, original: true),
                $model->auditValues($keys, original: false),
            );
        });
    }

    public function auditModule(): string
    {
        return property_exists($this, 'auditModule') ? $this->auditModule : 'core';
    }

    public function auditResource(): string
    {
        return property_exists($this, 'auditResource') ? $this->auditResource : Str::snake(class_basename($this));
    }

    /** @return list<string> */
    public function auditHidden(): array
    {
        return array_values(array_unique(array_merge(
            property_exists($this, 'auditHidden') ? $this->auditHidden : [],
            $this->getHidden(),
            array_filter([$this->getCreatedAtColumn(), $this->getUpdatedAtColumn()]),
        )));
    }

    private function recordAudit(string $verb, ?array $before, ?array $after): void
    {
        app(Auditor::class)->record(
            sprintf('%s.%s.%s', $this->auditModule(), $this->auditResource(), $verb),
            $this,
            $before,
            $after,
        );
    }

    /** archive/restore when archived_at flipped (TEN-06), otherwise update. */
    private function auditVerb(): string
    {
        if (! array_key_exists('archived_at', $this->getChanges())) {
            return 'update';
        }

        return $this->getRawOriginal('archived_at') === null ? 'archive' : 'restore';
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function auditableKeys(array $keys): array
    {
        return array_values(array_diff($keys, $this->auditHidden()));
    }

    /** @param list<string> $keys */
    private function auditValues(array $keys, bool $original): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $original ? $this->getOriginal($key) : $this->getAttribute($key);
        }

        return $values;
    }
}
