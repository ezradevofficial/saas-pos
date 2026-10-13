<?php

namespace App\Core\Imports;

/** IMP-02: a cell that can't be read as its column's type (`imports.cells.<code>`). */
final class ImportCellError
{
    public function __construct(public readonly string $code) {}

    public function message(): string
    {
        return __("imports.cells.{$this->code}");
    }
}
