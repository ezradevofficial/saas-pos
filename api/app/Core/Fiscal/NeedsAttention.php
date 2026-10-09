<?php

namespace App\Core\Fiscal;

/**
 * A document the platform must not guess how to send (a Kenyan sale in
 * USD while eTIMS currency handling is unconfirmed): queued but held as
 * `needs_attention` with this reason, alerted once, never sent until
 * someone decides and retries it.
 */
class NeedsAttention extends LocalRejection {}
