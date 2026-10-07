<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * Business records are archived, never hard-deleted (TEN-06).
 * Requires an `archived_at timestampTz null` column.
 */
trait Archivable
{
    public function initializeArchivable(): void
    {
        $this->mergeCasts(['archived_at' => 'datetime']);
    }

    public function archive(): bool
    {
        $this->archived_at = $this->freshTimestamp();

        return $this->save();
    }

    public function restore(): bool
    {
        $this->archived_at = null;

        return $this->save();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('archived_at'));
    }
}
