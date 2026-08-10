<?php

namespace Liberu\Ecommerce\Returns\Queries;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Models\ReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * The reads a surface actually performs, in one place.
 *
 * `byNumber` is the only lookup a customer-facing surface should use. There is
 * **no `byId`** here on purpose: an incrementing id in a URL is an enumeration of
 * everybody's returns, and the number exists so that never has to be weighed up
 * at a call site.
 *
 * `receivedForOrderLine` is the read the **host** performs — it holds an order
 * line id, and this is how it finds out what this module has taken delivery of
 * against that id without joining another module's tables or importing anything.
 */
final class ReturnQuery
{
    /** Everything a return page renders, in one query plus two eager loads. */
    public function byNumber(string $number): ?ReturnRequest
    {
        return ReturnRequest::query()->with(['lines', 'refunds'])->where('number', $number)->first();
    }

    /** The same, as the published read model. */
    public function dataByNumber(string $number): ?ReturnData
    {
        $return = $this->byNumber($number);

        return $return === null ? null : ReturnData::from($return);
    }

    /**
     * Every return raised against one order, newest first.
     *
     * @return Builder<ReturnRequest>
     */
    public function forOrder(int $orderId): Builder
    {
        return ReturnRequest::query()->where('order_id', $orderId)->orderByDesc('requested_at');
    }

    /**
     * Every return that names one order line.
     *
     * @return Builder<ReturnRequest>
     */
    public function forOrderLine(int $orderLineId): Builder
    {
        return ReturnRequest::query()
            ->whereHas('lines', fn (Builder $query): Builder => $query->where('order_line_id', $orderLineId))
            ->orderByDesc('requested_at');
    }

    /**
     * How many units of an order line this module has taken delivery of, across
     * every return that names it.
     *
     * The number a host reconciles against the counter held by whoever owns the
     * order line. They should agree, because the host raises that counter from
     * this module's own receipt event — and when they do not, this is the query
     * that says by how much. See `docs/runbook.md`.
     */
    public function receivedForOrderLine(int $orderLineId): int
    {
        return (int) ReturnLine::query()->where('order_line_id', $orderLineId)->sum('quantity_received');
    }

    /**
     * Returns still in progress — what a staff queue lists.
     *
     * @return Builder<ReturnRequest>
     */
    public function open(?int $teamId = null, ?int $storeId = null): Builder
    {
        return ReturnRequest::query()
            ->open()
            // Bound values, never `where(…, null)` — that compiles to `is null`
            // and would list exactly the orphan rows the policy denies.
            ->when($teamId !== null, fn (Builder $query): Builder => $query->where('team_id', $teamId))
            ->when($storeId !== null, fn (Builder $query): Builder => $query->where('store_id', $storeId))
            ->orderBy('requested_at');
    }

    /**
     * Approved returns whose goods are overdue — the expiry sweep.
     *
     * Nothing here runs on a timer. The host's schedule decides what to do with
     * these, and expiring one is a `TransitionReturn` the host calls. See the
     * runbook.
     *
     * @return Builder<ReturnRequest>
     */
    public function awaitingGoodsSince(DateTimeInterface $moment): Builder
    {
        return ReturnRequest::query()->awaitingGoodsSince($moment)->orderBy('goods_due_by');
    }

    /**
     * One shopper's returns, newest first.
     *
     * @return Builder<ReturnRequest>
     */
    public function forCustomer(int $customerId): Builder
    {
        return ReturnRequest::query()->where('customer_id', $customerId)->orderByDesc('requested_at');
    }
}
