<?php

namespace App\Core\MasterData\Parties\Http\Requests;

/** TEN-06: archive or restore a party (`core.party.archive` where it is reached). No body. */
class PartyActionRequest extends PartyRequest
{
    protected string $ability = 'archive';
}
