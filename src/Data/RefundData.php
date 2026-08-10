<?php

namespace Liberu\Ecommerce\Returns\Data;

use JsonSerializable;
use Liberu\Ecommerce\Returns\Enums\RefundKind;
use Liberu\Ecommerce\Returns\Models\Refund;

/**
 * Money that went back, as a published read model: **an amount and a reference.**
 *
 * There is no status on it, because a row exists only when the money moved. There
 * is no provider on it, because this package names none. There is no balance on
 * it, because this package holds no line prices and a balance it could not
 * compute would be a number somebody trusted.
 *
 * `reference` is opaque — whatever the host calls this movement. It travels so a
 * row can be reconciled against the system that actually moved the money, and it
 * is never parsed here.
 */
final readonly class RefundData implements JsonSerializable
{
    public function __construct(
        public int $id,
        public int $returnRequestId,
        public RefundKind $kind,
        public int $amountMinor,
        public string $currency,
        public int $currencyExponent,
        public ?int $taxMinor = null,
        public ?int $taxRateBp = null,
        public ?string $reference = null,
        public ?int $actorId = null,
    ) {}

    public static function from(Refund $refund): self
    {
        return new self(
            id: $refund->id,
            returnRequestId: $refund->return_request_id,
            kind: $refund->kind,
            amountMinor: $refund->amount_minor,
            currency: $refund->currency,
            currencyExponent: $refund->currency_exponent,
            taxMinor: $refund->tax_minor,
            taxRateBp: $refund->tax_rate_bp,
            reference: $refund->reference,
            actorId: $refund->actor_id,
        );
    }

    public function amount(): Money
    {
        return new Money($this->amountMinor, $this->currency, $this->currencyExponent);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'return_request_id' => $this->returnRequestId,
            'kind' => $this->kind->value,
            'amount' => $this->amount()->toArray(),
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'exponent' => $this->currencyExponent,
            'tax_minor' => $this->taxMinor,
            'tax_rate_bp' => $this->taxRateBp,
            'reference' => $this->reference,
            'actor_id' => $this->actorId,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
