<?php

namespace Modules\Salon\Http\Requests;

/** A step on a bound record with no body (submit, approve, export, leave, cancel a time off): the route's permission at the record. */
class ActionRequest extends BoundRequest {}
