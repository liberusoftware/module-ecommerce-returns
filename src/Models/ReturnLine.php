<?php

namespace Liberu\Ecommerce\Returns\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Ecommerce\Returns\Database\Factories\ReturnLineFactory;
use Liberu\Ecommerce\Returns\Enums\ReturnReason;

/**
 * One order line coming back.
 *
 * **`order_line_id` is a number.** No foreign key, no relation, and no import of
 * whatever owns order lines. That id is published as stable and public where it
 * comes from — never deleted, never replaced — and holding it is the entire
 * integration. This module never turns it back into a model.
 *
 * The five quantities are **not** fillable beyond the two set at request time.
 * `quantity_approved`, `quantity_received`, `quantity_restockable` and
 * `quantity_rejected` move through the actions and nowhere else, because the
 * chain that keeps them honest lives there and a mass-assigned counter is a way
 * round all of it.
 *
 * ### Where `note` may travel
 *
 * `note` is the one free-text field in this package, and it exists because a
 * shopper returning a faulty item genuinely does have something to say that seven
 * slugs cannot hold. A free-text field next to an event logger is also exactly
 * where personal data gets typed, so its containment is a rule rather than a
 * hope:
 *
 * - **Not in any read model.** `Data\ReturnLineData` omits it, so nothing that
 *   crosses a module boundary carries it.
 * - **Not in any event.** The events carry ids, quantities and reason slugs.
 * - **Not in any log line.** `Telemetry\DomainEventLogger` writes reason slugs
 *   and counts, and a test asserts a note never appears in what it writes.
 *
 * Where it *does* travel is to a staff surface, through this model, behind the
 * policy — which is the one place in an application where reading a shopper's
 * sentence is the job. A test pins each of those three refusals.
 *
 * @property int $id
 * @property int $return_request_id
 * @property int $order_line_id
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property string|null $sku
 * @property string $name
 * @property ReturnReason $reason
 * @property string|null $note
 * @property int $returnable_quantity
 * @property int $quantity_requested
 * @property int $quantity_approved
 * @property int $quantity_received
 * @property int $quantity_restockable
 * @property int $quantity_rejected
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ReturnRequest $returnRequest
 */
class ReturnLine extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_returns_lines';

    protected $fillable = [
        'return_request_id', 'order_line_id', 'product_id', 'variant_id',
        'sku', 'name', 'reason', 'note', 'returnable_quantity', 'quantity_requested',
    ];

    protected $attributes = [
        'returnable_quantity' => 0,
        'quantity_requested' => 1,
        'quantity_approved' => 0,
        'quantity_received' => 0,
        'quantity_restockable' => 0,
        'quantity_rejected' => 0,
    ];

    protected $casts = [
        'reason' => ReturnReason::class,
        'order_line_id' => 'integer',
        'returnable_quantity' => 'integer',
        'quantity_requested' => 'integer',
        'quantity_approved' => 'integer',
        'quantity_received' => 'integer',
        'quantity_restockable' => 'integer',
        'quantity_rejected' => 'integer',
    ];

    /** @return BelongsTo<ReturnRequest, $this> */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }

    /**
     * How many more units this line may still take delivery of.
     *
     * Zero once everything authorised has arrived. Never negative — receiving
     * more than this throws rather than clamping.
     */
    public function outstandingQuantity(): int
    {
        return $this->quantity_approved - $this->quantity_received;
    }

    /**
     * How much of what arrived has not yet been given a disposition.
     *
     * The gap an inspection closes. A line whose goods arrived in two parcels is
     * inspected twice, and this is what the second inspection is measured
     * against.
     */
    public function uninspectedQuantity(): int
    {
        return $this->quantity_received - $this->quantity_restockable - $this->quantity_rejected;
    }

    /**
     * The gap between what was authorised and what turned up.
     *
     * A **short receipt**, and it is an ordinary event rather than an error:
     * three arriving against five approved is what shoppers and couriers do. It
     * is here because it is the number a merchant wants to see, not because
     * anything refuses it.
     */
    public function shortfallQuantity(): int
    {
        return max(0, $this->quantity_approved - $this->quantity_received);
    }

    protected static function newFactory(): Factory
    {
        return ReturnLineFactory::new();
    }
}
