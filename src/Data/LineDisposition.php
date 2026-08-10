<?php

namespace Liberu\Ecommerce\Returns\Data;

use JsonSerializable;

/**
 * What an inspection concluded about goods that arrived: how many of them are
 * saleable again, and how many are not.
 *
 * **A returned unit is not automatically saleable**, which is why this exists at
 * all and why `restockable` is a decision rather than a copy of `quantity`. An
 * unopened box goes back on the shelf; a worn jumper does not; a faulty one goes
 * to the supplier. One number cannot say that, and a `restock_items` boolean on a
 * refund — which is what the host has — says it for the whole return at once.
 *
 * Carried on `Events\ReturnInspected`, which is what a host wires to whatever
 * owns stock. **This module never writes stock**: it publishes what happened and
 * the host decides, because a warehouse that quarantines returns for a week and a
 * warehouse that restocks on the desk are both correct and neither is this
 * package's to assume.
 */
final readonly class LineDisposition implements JsonSerializable
{
    public function __construct(
        public int $orderLineId,
        public int $restockable,
        public int $rejected = 0,
    ) {}

    public function total(): int
    {
        return $this->restockable + $this->rejected;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order_line_id' => $this->orderLineId,
            'restockable' => $this->restockable,
            'rejected' => $this->rejected,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
