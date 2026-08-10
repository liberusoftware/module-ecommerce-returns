<?php

namespace Liberu\Ecommerce\Returns\Data;

use JsonSerializable;

/**
 * *This many of this order line physically arrived.*
 *
 * The unit a receiving desk works in, and — carried on
 * `Events\ReturnGoodsReceived` — **the value the host hands to whoever owns the
 * order's counters.** It is an order line id and a quantity, which is exactly
 * what crosses a module boundary and exactly what does not require an import at
 * either end. See `README.md` for the listener.
 *
 * A quantity here is a **delta**, not a running total: a second parcel is a
 * second receipt of two, never a correction to four. That matters because the
 * counter it feeds is append-only on the far side too, and a total posted twice
 * would be double the goods.
 */
final readonly class LineReceipt implements JsonSerializable
{
    public function __construct(
        public int $orderLineId,
        public int $quantity,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['order_line_id' => $this->orderLineId, 'quantity' => $this->quantity];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
