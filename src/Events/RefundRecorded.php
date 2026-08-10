<?php

namespace Liberu\Ecommerce\Returns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\Returns\Data\RefundData;
use Liberu\Ecommerce\Returns\Data\ReturnData;

/**
 * Money went back, and this package wrote it down.
 *
 * **Past tense, and it means the movement already happened.** This is not a
 * request to refund and nothing downstream should treat it as one — a listener
 * that calls a payment provider on this event refunds every shopper twice.
 * Whoever owns the tender moves the money and then records it here with its
 * reference.
 *
 * Which way round that goes is the whole design: this module records money it did
 * not move, rather than moving money it does not own.
 */
final class RefundRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly ReturnData $return,
        public readonly RefundData $refund,
    ) {}
}
