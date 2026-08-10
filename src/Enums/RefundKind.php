<?php

namespace Liberu\Ecommerce\Returns\Enums;

/**
 * The shape of money going back — **what it was, never who moved it.**
 *
 * A refund here is an amount and a reference. This enum says which of four
 * genuinely different things the merchant did, because they have different
 * accounting consequences and a report that cannot tell them apart is useless:
 * a store credit is a liability the merchant still owes, a tender refund is cash
 * that has left, and an exchange is neither.
 *
 * There is deliberately no case naming a payment provider, and no case that
 * implies this module made a call. `Tender` means "back the way it came, and the
 * host holds the reference for that"; it does not mean this package knows what a
 * gateway is. `src/` names no provider at all and a boundary test greps for five
 * of them.
 */
enum RefundKind: string
{
    /** Back to whatever paid — Checkout owns the tender, the host owns the call. */
    case Tender = 'tender';

    /** Credit the shopper may spend. A liability, not a payment. */
    case StoreCredit = 'store_credit';

    /** Goods for goods. Recorded at zero, or at the difference. */
    case Exchange = 'exchange';

    /** A cheque, a bank transfer, a shop-floor decision. Somebody did it by hand. */
    case Manual = 'manual';
}
