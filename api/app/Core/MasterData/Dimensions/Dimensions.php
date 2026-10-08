<?php

namespace App\Core\MasterData\Dimensions;

use InvalidArgumentException;

/**
 * MD-05: the kinds of dimension, by type name (the route default
 * `dimension_type`, the history type and the audit resource).
 */
final class Dimensions
{
    /** @var array<string, class-string<Dimension>> */
    public const TYPES = [
        'department' => Department::class,
        'cost_centre' => CostCentre::class,
        'project' => Project::class,
    ];

    /** @return class-string<Dimension> */
    public static function model(string $type): string
    {
        return self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown dimension type [{$type}].");
    }
}
