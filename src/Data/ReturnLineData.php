<?php

namespace Liberu\Ecommerce\Returns\Data;

use JsonSerializable;
use Liberu\Ecommerce\Returns\Enums\ReturnReason;
use Liberu\Ecommerce\Returns\Models\ReturnLine;

/**
 * A returning line as a published read model.
 *
 * The value a surface renders and an event carries, rather than the Eloquent
 * model — a consumer holding the model holds its table name, its casts and its
 * scopes, and every one of those becomes a breaking change the day it moves.
 *
 * **`note` is deliberately absent**, and its absence is the field's containment
 * rule made mechanical. A shopper's free text stays behind the policy on the
 * model; nothing that crosses a module boundary carries it, and a test asserts
 * that this value never does. `reason` is a slug and travels freely, which is the
 * whole reason the reason is a closed set.
 *
 * All six quantities are here so nobody derives one and gets it wrong, and the
 * derived ones are methods so there is one implementation of each subtraction.
 */
final readonly class ReturnLineData implements JsonSerializable
{
    public function __construct(
        public int $id,
        public int $returnRequestId,
        public int $orderLineId,
        public string $name,
        public ReturnReason $reason,
        public int $returnableQuantity,
        public int $requestedQuantity,
        public int $approvedQuantity,
        public int $receivedQuantity,
        public int $restockableQuantity,
        public int $rejectedQuantity,
        public ?int $productId = null,
        public ?int $variantId = null,
        public ?string $sku = null,
    ) {}

    public static function from(ReturnLine $line): self
    {
        return new self(
            id: $line->id,
            returnRequestId: $line->return_request_id,
            orderLineId: $line->order_line_id,
            name: $line->name,
            reason: $line->reason,
            returnableQuantity: $line->returnable_quantity,
            requestedQuantity: $line->quantity_requested,
            approvedQuantity: $line->quantity_approved,
            receivedQuantity: $line->quantity_received,
            restockableQuantity: $line->quantity_restockable,
            rejectedQuantity: $line->quantity_rejected,
            productId: $line->product_id,
            variantId: $line->variant_id,
            sku: $line->sku,
        );
    }

    /** How many more units this line may still take delivery of. */
    public function outstandingQuantity(): int
    {
        return $this->approvedQuantity - $this->receivedQuantity;
    }

    /** How much of what arrived has not yet been given a disposition. */
    public function uninspectedQuantity(): int
    {
        return $this->receivedQuantity - $this->restockableQuantity - $this->rejectedQuantity;
    }

    /** What was authorised and never turned up. An ordinary event, not an error. */
    public function shortfallQuantity(): int
    {
        return max(0, $this->approvedQuantity - $this->receivedQuantity);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'return_request_id' => $this->returnRequestId,
            'order_line_id' => $this->orderLineId,
            'name' => $this->name,
            'sku' => $this->sku,
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'reason' => $this->reason->value,
            'returnable_quantity' => $this->returnableQuantity,
            'requested_quantity' => $this->requestedQuantity,
            'approved_quantity' => $this->approvedQuantity,
            'received_quantity' => $this->receivedQuantity,
            'restockable_quantity' => $this->restockableQuantity,
            'rejected_quantity' => $this->rejectedQuantity,
            'outstanding_quantity' => $this->outstandingQuantity(),
            'uninspected_quantity' => $this->uninspectedQuantity(),
            'shortfall_quantity' => $this->shortfallQuantity(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
