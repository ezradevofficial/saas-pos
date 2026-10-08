<?php

namespace App\Core\MasterData\Dimensions;

/** MD-05: a cost centre of a company; its owner is the cost-centre owner (APR-02). */
class CostCentre extends Dimension
{
    protected $table = 'cost_centres';

    public static function path(): string
    {
        return 'cost-centres';
    }
}
