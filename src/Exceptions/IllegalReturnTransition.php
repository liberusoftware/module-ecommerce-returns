<?php

namespace Liberu\Ecommerce\Returns\Exceptions;

use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use RuntimeException;

/**
 * A move the state machine does not allow, refused rather than written.
 *
 * Nothing is written when this throws — not the status, not a timestamp, not a
 * history row, not an event. An attempt that was refused is not a transition
 * that happened, and a history containing refusals answers a different question
 * from the one it is kept for.
 *
 * **Self-transitions are refused too**, and that is not pedantry. A no-op files a
 * history row against a move nobody made, and lets a retried click stamp
 * `approved_at` a second time — so the record of when a merchant authorised
 * something becomes the record of when somebody last pressed a button.
 *
 * The move that matters most is `approved → resolved`: finishing a return whose
 * goods never arrived. See `Enums\ReturnStatus` and `docs/domain.md`.
 */
final class IllegalReturnTransition extends RuntimeException
{
    public static function from(ReturnStatus $from, ReturnStatus $to): self
    {
        if ($from === $to) {
            return new self("A return that is already `{$from->value}` cannot transition to `{$to->value}`. A no-op is not a transition, and recording one would put a history row against a move nobody made and stamp a timestamp twice.");
        }

        if ($from->isTerminal()) {
            return new self("A return that is `{$from->value}` is finished and cannot become `{$to->value}`. Reopening a closed return is a new request, with its own authorisation.");
        }

        if ($from === ReturnStatus::Approved && $to === ReturnStatus::Resolved) {
            return new self('A return cannot be resolved straight from `approved`, because nothing has come back yet. Receive the goods first, or expire the return if they are never going to arrive.');
        }

        $allowed = implode('`, `', array_map(fn (ReturnStatus $status): string => $status->value, $from->allowedTransitions()));

        return new self("A return that is `{$from->value}` cannot become `{$to->value}`. It may only become `{$allowed}`.");
    }
}
