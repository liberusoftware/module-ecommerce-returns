<?php

namespace Liberu\Ecommerce\Returns\Data;

use Liberu\Ecommerce\Returns\Enums\ReturnReason;

/**
 * One line a shopper wants to send back, described rather than fetched.
 *
 * **`returnableQuantity` is an input, and that is the whole boundary in one
 * field.** Eligibility is arithmetic over what was delivered and what has already
 * come back, and both of those counters live in the module that owns order lines.
 * This package cannot compute it, must not guess it, and refuses to look it up —
 * so the caller, who is the only party entitled to know both modules exist, reads
 * it there and hands it in. It is the same rule tax follows: a rate arrives, it is
 * never looked up.
 *
 * `RequestReturn` refuses a `quantity` larger than it. That is not this module
 * enforcing somebody else's invariant; it is this module refusing to write down a
 * request the caller has itself just said is impossible.
 *
 * `name` and `sku` are **copied labels**. A receiving desk has to know what is in
 * the box, and this package cannot join anything to find out.
 */
final readonly class ReturnLineInput
{
    public function __construct(
        public int $orderLineId,
        public int $quantity,
        public ReturnReason $reason,
        public int $returnableQuantity,
        public string $name,
        public ?string $sku = null,
        public ?int $productId = null,
        public ?int $variantId = null,
        /**
         * What the shopper wrote, if anything.
         *
         * Contained by rule — never in a read model, an event or a log line. See
         * `Models\ReturnLine`.
         */
        public ?string $note = null,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'order_line_id' => $this->orderLineId,
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'sku' => $this->sku,
            'name' => $this->name,
            'reason' => $this->reason,
            'note' => $this->note,
            'returnable_quantity' => $this->returnableQuantity,
            'quantity_requested' => $this->quantity,
        ];
    }
}
