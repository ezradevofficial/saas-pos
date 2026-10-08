<?php

namespace App\Core\MasterData\Dimensions;

/** MD-05: a department of a company; its owner is the department head (APR-02). */
class Department extends Dimension
{
    protected $table = 'departments';

    public static function path(): string
    {
        return 'departments';
    }
}
