<?php

namespace Liberu\Ecommerce\Returns\Data;

use JsonSerializable;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Models\ReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * A whole return as a published read model.
 *
 * What the events carry and what a surface renders. Plain values only, so a
 * listener can be queued without a model being serialised into a job payload and
 * coming back stale.
 *
 * `refundedMinor` is a **sum over rows**, computed here and stored nowhere. There
 * is no `fully_refunded` companion, because this module holds no line prices and
 * therefore cannot know what "fully" would be — the host's `orders` table has
 * three such columns and they are the first thing to disagree with reality when
 * shipping is refunded separately.
 */
final readonly class ReturnData implements JsonSerializable
{
    /**
     * @param  list<ReturnLineData>  $lines
     */
    public function __construct(
        public int $id,
        public string $number,
        public int $orderId,
        public ReturnStatus $status,
        public string $currency,
        public int $currencyExponent,
        public array $lines,
        public int $refundedMinor = 0,
        public ?int $teamId = null,
        public ?int $storeId = null,
        public ?int $customerId = null,
        public ?string $goodsDueBy = null,
    ) {}

    public static function from(ReturnRequest $return): self
    {
        return new self(
            id: $return->id,
            number: $return->number,
            orderId: $return->order_id,
            status: $return->status,
            currency: $return->currency,
            currencyExponent: $return->currency_exponent,
            lines: $return->lines->map(fn (ReturnLine $line): ReturnLineData => ReturnLineData::from($line))->values()->all(),
            refundedMinor: (int) $return->refunds->sum('amount_minor'),
            teamId: $return->team_id,
            storeId: $return->store_id,
            customerId: $return->customer_id,
            goodsDueBy: $return->goods_due_by?->toIso8601String(),
        );
    }

    /** Everything that physically arrived, as receipts. */
    public function receivedQuantity(): int
    {
        return array_sum(array_map(fn (ReturnLineData $line): int => $line->receivedQuantity, $this->lines));
    }

    /** What inspection said may go back on a shelf — a *statement*, not a stock write. */
    public function restockableQuantity(): int
    {
        return array_sum(array_map(fn (ReturnLineData $line): int => $line->restockableQuantity, $this->lines));
    }

    public function refunded(): Money
    {
        return new Money($this->refundedMinor, $this->currency, $this->currencyExponent);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'order_id' => $this->orderId,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'exponent' => $this->currencyExponent,
            'team_id' => $this->teamId,
            'store_id' => $this->storeId,
            'customer_id' => $this->customerId,
            'goods_due_by' => $this->goodsDueBy,
            'received_quantity' => $this->receivedQuantity(),
            'restockable_quantity' => $this->restockableQuantity(),
            'refunded_minor' => $this->refundedMinor,
            'lines' => array_map(fn (ReturnLineData $line): array => $line->toArray(), $this->lines),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
