<?php

namespace Liberu\Ecommerce\Returns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;

/**
 * The return moved along its state machine.
 *
 * Carries **both ends of the edge**, so no listener has to keep a second copy of
 * the transition table to work out what just happened. `reason` is a short slug,
 * capped at 64 characters by the column, because this value is copied into a log
 * line.
 *
 * A refused move dispatches nothing at all. An attempt the state machine turned
 * down is not a transition that happened.
 */
final class ReturnTransitioned
{
    use Dispatchable;

    public function __construct(
        public readonly ReturnData $return,
        public readonly ReturnStatus $from,
        public readonly ReturnStatus $to,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
