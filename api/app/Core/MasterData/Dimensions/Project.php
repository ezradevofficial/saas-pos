<?php

namespace App\Core\MasterData\Dimensions;

/** MD-05: a project of a company. */
class Project extends Dimension
{
    protected $table = 'projects';

    public static function path(): string
    {
        return 'projects';
    }
}
