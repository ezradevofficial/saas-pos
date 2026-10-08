<?php

namespace App\Core\Notifications\Drivers;

/**
 * NOT-01: a provider adapter for push, SMS or WhatsApp. Throws on a
 * failure the delivery should retry (SendDelivery records the error).
 * Real providers per country come later (owner credentials, prepaid
 * message credits in billing).
 */
interface ChannelDriver
{
    public function send(OutgoingMessage $message): DriverResult;
}
