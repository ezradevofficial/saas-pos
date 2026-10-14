<?php

namespace App\Core\Payments\Tenders;

/**
 * MD-04, concept note 7.1: what a module adds to a payment method type
 * whose value it keeps (points, vouchers and gift cards, store credit).
 * The POS module asks the provider of a payment's method type about the
 * payment's `tender` (the module's reference: a card, an account, points)
 * when a till uploads a sale or a refund. Device wins (ADR 004): a
 * provider never refuses a completed sale; it returns review flags.
 */
interface TenderProvider
{
    /** The payment method type it serves (PaymentMethod::TYPES). */
    public function type(): string;

    /** The module that provides it (RBAC-08: unused while that module is off). */
    public function module(): string;

    /**
     * Review flags for one payment of a stored sale or refund, as short
     * codes (`gift_card_unknown`); [] when the tender is in order.
     *
     * @return list<string>
     */
    public function check(TenderUse $use): array;
}
