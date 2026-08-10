<?php

namespace Liberu\Ecommerce\Returns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\Returns\Data\LineReceipt;
use Liberu\Ecommerce\Returns\Data\ReturnData;

/**
 * **Goods physically came back.** This is the contract event of the module.
 *
 * A return holds order line ids and quantities, which are identifiers. Raising
 * the *returned* counter on an order line is the job of whoever owns that line,
 * and this package does not import it, require it, or know its class names — so
 * this event carries what came back, and the **host** subscribes and calls that
 * module's counter action with it. `README.md` and `docs/adoption.md` carry the
 * listener verbatim.
 *
 * `$receipts` are **deltas, not totals.** A return that comes back in two parcels
 * dispatches this twice, with two and then three, never with two and then five.
 * That matters because the counter on the far side is append-only as well, and a
 * total posted twice is double the goods.
 *
 * `$return` is the state *after* the receipt, so a listener reads the quantities
 * rather than applying a delta it would have to compute itself.
 */
final class ReturnGoodsReceived
{
    use Dispatchable;

    /**
     * @param  list<LineReceipt>  $receipts
     */
    public function __construct(
        public readonly ReturnData $return,
        public readonly array $receipts,
        public readonly ?int $actorId = null,
    ) {}

    /** Everything in this receipt, for a listener that wants one number. */
    public function quantity(): int
    {
        return array_sum(array_map(fn (LineReceipt $receipt): int => $receipt->quantity, $this->receipts));
    }
}
