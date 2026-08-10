<?php

namespace Liberu\Ecommerce\Returns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\Returns\Data\ReturnData;

/**
 * A shopper asked to send something back.
 *
 * Past tense, and about an intention rather than about goods: nothing has moved
 * and nothing is authorised. A listener that allocates, reserves or credits
 * anything on this event is acting on a request the merchant has not agreed to.
 */
final class ReturnRequested
{
    use Dispatchable;

    public function __construct(
        public readonly ReturnData $return,
    ) {}
}
