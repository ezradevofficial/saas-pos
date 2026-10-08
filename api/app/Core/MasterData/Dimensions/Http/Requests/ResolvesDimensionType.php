<?php

namespace App\Core\MasterData\Dimensions\Http\Requests;

use App\Core\MasterData\Dimensions\Dimension;
use App\Core\MasterData\Dimensions\Dimensions;

/** The kind of dimension a route serves (its `dimension_type` default, MD-05). */
trait ResolvesDimensionType
{
    protected function dimensionType(): string
    {
        return (string) $this->route('dimension_type');
    }

    /** @return class-string<Dimension> */
    protected function dimensionClass(): string
    {
        return Dimensions::model($this->dimensionType());
    }
}
