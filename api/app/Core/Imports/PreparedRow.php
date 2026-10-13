<?php

namespace App\Core\Imports;

use Illuminate\Database\Eloquent\Model;

/**
 * IMP-02: one row ready for the domain: the API body built from its
 * cells, the record it changes (update mode), and, once the Form Request
 * passed, the validated input the writer takes.
 */
final class PreparedRow
{
    /** @var array<string, mixed> validated input */
    public array $validated = [];

    /**
     * @param  array<string, mixed>  $input  the body the API would receive
     * @param  array<string, mixed>  $context  what the importer resolved (a price list, the item)
     */
    public function __construct(
        public readonly int $number,
        public readonly array $input,
        public readonly ?Model $record = null,
        public readonly array $context = [],
    ) {}

    public function creates(): bool
    {
        return $this->record === null;
    }
}
