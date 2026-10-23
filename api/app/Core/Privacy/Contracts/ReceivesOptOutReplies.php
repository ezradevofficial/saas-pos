<?php

namespace App\Core\Privacy\Contracts;

/**
 * NFR-08 (KE DPA): where an inbound SMS or WhatsApp reply goes to opt out
 * of marketing. Whoever receives messages (E-commerce's store WhatsApp
 * inbox today; an SMS provider's webhook once one is chosen, an owner
 * input in docs/privacy.md) calls it in the tenant the message reached:
 *
 *     if ($optOuts->isOptOut($text)) $optOuts->optOut('sms', $from, 'sms_provider');
 *
 * It never sees or stores the message itself.
 */
interface ReceivesOptOutReplies
{
    /** Whether a reply's text is an opt-out keyword (STOP, ARRÊT...), alone on the line. */
    public function isOptOut(string $text): bool;

    /**
     * Withdraw marketing consent on every channel for every customer of
     * the current tenant with this phone number (E.164) who still consents
     * on any channel (owner decision 2026-10-22). $channel is the one the
     * reply came by (sms or whatsapp), named in the evidence.
     *
     * @param  string  $via  where the reply arrived, for the evidence (e.g. `store_whatsapp`)
     * @return int the customers withdrawn
     */
    public function optOut(string $channel, string $phone, string $via): int;
}
