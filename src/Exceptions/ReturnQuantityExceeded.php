<?php

namespace Liberu\Ecommerce\Returns\Exceptions;

use RuntimeException;

/**
 * A quantity larger than the one it has to fit inside. **Amendable — the same
 * call succeeds once somebody raises the number it broke against.**
 *
 * The other half of the pair described on `UnexpectedReturnLine`. The line is
 * the right line; there is just more of it than was authorised, or more disposed
 * of than arrived. An operator can fix that, and a surface answers `409` rather
 * than `422` so the client knows retrying after an amendment is worth doing.
 *
 * **Refused, never clamped**, which is the rule Orders settled and this module
 * keeps on its own side. Four chains of arithmetic, and every one of them is a
 * number that eventually becomes money:
 *
 *     requested   ≤ returnable            (what Orders said was still returnable)
 *     approved    ≤ requested             (a merchant authorises no more than was asked)
 *     received    ≤ approved              (nothing arrives that was not authorised)
 *     restockable + rejected ≤ received   (nothing is inspected that did not arrive)
 *
 * A short receipt is **not** an error and is not here: three arriving against
 * five approved is an ordinary event, and the two units that never came are
 * simply never resolvable. The asymmetry is deliberate — arriving with fewer than
 * expected is what a courier does, and arriving with more is what a mistake does.
 */
final class ReturnQuantityExceeded extends RuntimeException
{
    public static function overReturnable(int $orderLineId, int $wanted, int $returnable): self
    {
        return new self("Order line {$orderLineId} has {$returnable} still returnable, so {$wanted} cannot be requested. Eligibility is an input to this module and never a lookup: this is the number the caller handed in, from whoever owns the order.");
    }

    public static function overRequested(int $orderLineId, int $wanted, int $requested): self
    {
        return new self("Order line {$orderLineId} was requested for {$requested}, so {$wanted} cannot be approved. A merchant may authorise less than was asked for, never more — authorising more invents a request the shopper never made.");
    }

    public static function overApproved(int $orderLineId, int $wanted, int $outstanding): self
    {
        return new self("Order line {$orderLineId} has {$outstanding} authorised and not yet received, so {$wanted} cannot be received. Raise the approval if the extra units are genuinely wanted back, then receive again — clamping here would silently accept goods nobody agreed to take.");
    }

    public static function overReceived(int $orderLineId, int $wanted, int $received): self
    {
        return new self("Order line {$orderLineId} has {$received} received, so {$wanted} cannot be given a disposition. Restockable plus rejected can never exceed what arrived.");
    }

    public static function notPositive(int $quantity): self
    {
        return new self("A quantity must be positive, got {$quantity}. Every count here is append-only: there is no negative move that un-receives a parcel.");
    }
}
