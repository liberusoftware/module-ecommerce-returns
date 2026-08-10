<?php

namespace Liberu\Ecommerce\Returns\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Liberu\Ecommerce\Returns\Database\Factories\ReturnRequestFactory;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use RuntimeException;

/**
 * What came back after delivery, and what the merchant decided about it.
 *
 * **This model never reads an order.** It has no `order_id` foreign key, no
 * relation to one, and this package requires no sibling commerce package at all.
 * `order_id` and its lines' `order_line_id` are numbers, and the whole
 * integration is that they are stable and public where they come from.
 *
 * **The status is a state machine, not a column somebody writes.** `$fillable`
 * deliberately contains neither `status` nor any of the eight state timestamps:
 * every move goes through `Actions\TransitionReturn`, which consults
 * `Enums\ReturnStatus` and throws on an illegal move — including a self-move,
 * because a retried click must not stamp `approved_at` twice.
 *
 * @property int $id
 * @property string $number
 * @property int|null $team_id
 * @property int|null $store_id
 * @property int|null $customer_id
 * @property int $order_id
 * @property ReturnStatus $status
 * @property string $currency
 * @property int $currency_exponent
 * @property CarbonImmutable|null $goods_due_by
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $refused_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $inspected_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ReturnLine> $lines
 * @property-read Collection<int, ReturnStatusChange> $statusChanges
 * @property-read Collection<int, Refund> $refunds
 * @property-read Model|null $customer
 * @property-read Model|null $team
 */
class ReturnRequest extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_returns_requests';

    protected $fillable = [
        'number', 'team_id', 'store_id', 'customer_id', 'order_id',
        'currency', 'currency_exponent', 'requested_at',
    ];

    /*
     * Restated here as well as in the migration. `create()` does not read a
     * column default back, so a model built through Eloquent holds null for
     * anything whose default lives only in the schema — and a null `status` cast
     * to an enum is a fatal, not a fallback.
     */
    protected $attributes = [
        'status' => 'requested',
        'currency_exponent' => 2,
    ];

    protected $casts = [
        'status' => ReturnStatus::class,
        'order_id' => 'integer',
        'currency_exponent' => 'integer',
        'goods_due_by' => 'immutable_datetime',
        'requested_at' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
        'refused_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'expired_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'inspected_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
    ];

    /**
     * A public reference that is not an incrementing id.
     *
     * An id in a customer-facing URL or a support email is an enumeration of
     * everybody's returns. Forty-eight bits from the CSPRNG, rendered as hex,
     * because this number gets read down a telephone and written on a box.
     *
     * Uniqueness is still the index's job, not this function's. A collision is a
     * `QueryException` on insert, which is loud and rare, rather than a second
     * return quietly filed under somebody else's number.
     */
    public static function generateNumber(): string
    {
        return 'RMA-'.strtoupper(bin2hex(random_bytes(6)));
    }

    /** @return HasMany<ReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ReturnLine::class)->orderBy('id');
    }

    /** @return HasMany<ReturnStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(ReturnStatusChange::class)->orderBy('id');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    /**
     * The shopper, if the host has told this package where its customers live.
     *
     * Opt-in and resolved from configuration at call time, never imported. A
     * package that names another package's class in a `use` statement has quietly
     * acquired a dependency on it, and `customer_id` alone is enough for every
     * rule this module enforces — the relation exists so a panel can show a name
     * instead of a number.
     *
     * Throws rather than guessing. A `belongsTo` against a guessed class name
     * fails at query time with a message about a missing table, which is a much
     * worse place to find out.
     *
     * @return BelongsTo<Model, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->relateToConfigured('returns.customer_model', 'customer_id', 'customer');
    }

    /**
     * The owning team, resolved the same way and for the same reason.
     *
     * @return BelongsTo<Model, $this>
     */
    public function team(): BelongsTo
    {
        return $this->relateToConfigured('returns.team_model', 'team_id', 'team');
    }

    /** Whether goods arriving now belong to this return. */
    public function acceptsGoods(): bool
    {
        return $this->status->acceptsGoods();
    }

    /**
     * Whether anything has physically come back.
     *
     * The arithmetic half of the symmetric invariant. The state says a receipt
     * happened; this says a receipt was for something. A return received for zero
     * of everything is a parcel that turned up empty, and it does not entitle
     * anybody to a refund.
     */
    public function hasGoods(): bool
    {
        return $this->lines->sum('quantity_received') > 0;
    }

    /**
     * What has been recorded as going back, in minor units.
     *
     * **Computed, never stored.** A maintained total is a second copy of a sum,
     * and the day it disagrees with the rows it is the copy that gets believed.
     * There is deliberately no `fully_refunded` companion either: this module
     * holds no line prices, so it cannot know what "fully" would be.
     */
    public function refundedMinor(): int
    {
        return (int) $this->refunds->sum('amount_minor');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [
            ReturnStatus::Requested->value,
            ReturnStatus::Approved->value,
            ReturnStatus::Received->value,
            ReturnStatus::Inspected->value,
        ]);
    }

    /**
     * Approved returns whose goods are overdue — what an expiry sweep reads.
     *
     * The comparison is bound and the status is a value, never
     * `where('goods_due_by', null)`: that compiles to `is null` and would return
     * every return that was approved without a window rather than none.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingGoodsSince(Builder $query, DateTimeInterface $moment): void
    {
        $query->where('status', ReturnStatus::Approved->value)
            ->whereNotNull('goods_due_by')
            ->where('goods_due_by', '<', $moment);
    }

    protected static function newFactory(): Factory
    {
        return ReturnRequestFactory::new();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    private function relateToConfigured(string $setting, string $foreignKey, string $what): BelongsTo
    {
        $model = config($setting);

        if (! is_string($model) || $model === '') {
            throw new RuntimeException("No {$what} model is configured. Set `{$setting}` before loading the `{$what}` relation.");
        }

        return $this->belongsTo($model, $foreignKey);
    }
}
