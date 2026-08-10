<?php

namespace Liberu\Ecommerce\Returns\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Ecommerce\Returns\Data\Money;
use Liberu\Ecommerce\Returns\Enums\RefundKind;

/**
 * Money that went back, recorded as an **amount and a reference**.
 *
 * **A refund is not a payment**, and this model is the shape of that refusal.
 * There is no gateway here, no provider name anywhere in `src/`, no `status`
 * duplicating a state machine somebody else owns, and no `process()` that voids a
 * charge and then restocks and then transitions an order — the host's `Refund`
 * model does all four in one method, and that is the design being replaced.
 *
 * A row means *this went back*. The host moves the money and hands back a
 * `reference`; this package records it and publishes an event. If the money did
 * not move, there is no row.
 *
 * Append-only, and several rows per return are normal. Goods refunded on receipt
 * and shipping refunded after a conversation are two decisions, and one mutable
 * number cannot hold both.
 *
 * No policy of its own registers `create` or `update` as anything but false —
 * see `Policies\RefundPolicy`. Money is the model most worth having a gate say no
 * about, and an unpolicied model is exposed rather than safe.
 *
 * @property int $id
 * @property int $return_request_id
 * @property RefundKind $kind
 * @property int $amount_minor
 * @property string $currency
 * @property int $currency_exponent
 * @property int|null $tax_rate_bp
 * @property int|null $tax_minor
 * @property string|null $reference
 * @property int|null $actor_id
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ReturnRequest $returnRequest
 */
class Refund extends Model
{
    protected $table = 'ecommerce_returns_refunds';

    protected $fillable = [
        'return_request_id', 'kind', 'amount_minor', 'currency', 'currency_exponent',
        'tax_rate_bp', 'tax_minor', 'reference', 'actor_id', 'recorded_at',
    ];

    protected $attributes = [
        'kind' => 'tender',
        'currency_exponent' => 2,
    ];

    protected $casts = [
        'kind' => RefundKind::class,
        'amount_minor' => 'integer',
        'currency_exponent' => 'integer',
        'tax_rate_bp' => 'integer',
        'tax_minor' => 'integer',
        'actor_id' => 'integer',
        'recorded_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<ReturnRequest, $this> */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }

    public function amount(): Money
    {
        return new Money($this->amount_minor, $this->currency, $this->currency_exponent);
    }
}
