<?php

namespace Liberu\Ecommerce\Returns\Exceptions;

use RuntimeException;

/**
 * Goods arrived that nobody authorised. **Permanent — retrying cannot help.**
 *
 * One of two failures a receiving desk sees, and the whole reason there are two
 * classes rather than one. Checkout published a single class for a permanent
 * conflict and a transient one, and its API now has to rebuild a message string
 * from the domain's own factory to tell them apart. A surface over this module
 * uses `instanceof`, and gets the two answers right without parsing prose.
 *
 * **This one and `ReturnQuantityExceeded` are opposites, and the difference is
 * the remedy:**
 *
 * - This: the parcel names something this return never asked for, or a return
 *   that is not open to goods at all. Nothing an operator does to *this* return
 *   makes the parcel belong to it. Quarantine the goods and raise a new request.
 *   A surface answers `422`.
 * - `ReturnQuantityExceeded`: the parcel names the right line, and there is
 *   simply more of it than was authorised. An operator raises the approval and
 *   the same parcel goes through unchanged. A surface answers `409`.
 *
 * The difference matters because the wrong one is expensive in both directions:
 * treating this as amendable invites an operator to authorise goods after the
 * fact to make an error message go away, and treating the other as permanent
 * sends a perfectly good parcel to quarantine.
 */
final class UnexpectedReturnLine extends RuntimeException
{
    public static function notRequested(string $number, int $orderLineId): self
    {
        return new self("Return {$number} does not cover order line {$orderLineId}, so goods against it cannot be received here. This is not an amount to amend — the parcel names something nobody authorised. Quarantine it and raise a request that covers it.");
    }

    public static function notOpenToGoods(string $number, string $status): self
    {
        return new self("Return {$number} is `{$status}` and is not open to goods. Only an approved return, or one already part-received, may take delivery. Goods arriving against an expired return arrived after the merchant closed it, and adopting them would write a receipt nobody agreed to.");
    }

    public static function nothingToReturn(): self
    {
        return new self('A return has to name at least one order line. A request for nothing is not a return, and writing one would put an empty workflow in every count of returns in progress.');
    }

    public static function nothingToInspect(string $number, int $orderLineId): self
    {
        return new self("Return {$number} has taken no delivery of order line {$orderLineId}, so there is nothing on it to inspect. A disposition is a statement about goods somebody physically has.");
    }
}
