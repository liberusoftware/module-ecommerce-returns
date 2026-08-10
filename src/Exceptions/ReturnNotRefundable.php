<?php

namespace Liberu\Ecommerce\Returns\Exceptions;

use RuntimeException;

/**
 * Money recorded as going back for goods that never came back.
 *
 * **The symmetric invariant.** Orders enforces `returned ≤ fulfilled` — nothing
 * can come back that never went out. This is the same rule from the far side:
 * nothing is settled that never came back. The two together close the loop, and
 * neither module can enforce the other's half, because neither holds the other's
 * counters.
 *
 * A request can be made, approved, and then the goods simply never arrive. That
 * return `expires`; it does not resolve, and it does not get a refund recorded
 * against it. Without this, the most expensive mistake in the domain — refunding
 * a parcel nobody has — is one impatient support ticket away, and it is the exact
 * move a queue under pressure would like to make.
 *
 * *When* a merchant refunds within the loop is still theirs: on receipt or after
 * inspection are both honest, and both are allowed.
 */
final class ReturnNotRefundable extends RuntimeException
{
    public static function nothingReceived(string $number, string $status): self
    {
        return new self("Return {$number} is `{$status}` and has taken delivery of nothing, so no refund may be recorded against it. Money going back follows goods coming back — an approved return whose parcel never arrives expires, it does not resolve.");
    }

    public static function notPositive(int $amountMinor): self
    {
        return new self("A refund amount must be positive, got {$amountMinor}. This module records money that went back; a negative one is a charge, and charging is not a thing a returns module does.");
    }

    public static function currencyMismatch(string $expected, string $got): self
    {
        return new self("Return currency is `{$expected}` and the refund is in `{$got}`. This module holds no rates and converts nothing — a refund is recorded in the currency the order was agreed in, or it is a different transaction somebody else is responsible for.");
    }
}
