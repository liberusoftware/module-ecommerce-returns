<?php

namespace Liberu\Ecommerce\Returns\Actions;

use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\Returns\Data\LineReceipt;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Events\ReturnGoodsReceived;
use Liberu\Ecommerce\Returns\Exceptions\ReturnQuantityExceeded;
use Liberu\Ecommerce\Returns\Exceptions\UnexpectedReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * Goods physically arrived. **The action where requested and received stop being
 * the same number.**
 *
 * Three disagreements are possible at a receiving desk and each gets its own
 * answer, decided here rather than left to whoever is holding the box:
 *
 * - **Fewer arrive than were approved.** Allowed, and not even unusual: a shopper
 *   posts two of the three they meant to. The receipt records two, the line keeps
 *   a `shortfallQuantity()` of one, and the unit that never came is simply never
 *   resolvable. Nothing is refused and nothing is topped up.
 * - **More arrive than were approved.** Refused — `ReturnQuantityExceeded`, which
 *   is the *amendable* one. An operator raises the approval and receives the same
 *   parcel again. Clamping would silently take goods nobody authorised, and the
 *   merchant would find out when the refund was calculated.
 * - **Something arrives that was never requested.** Refused —
 *   `UnexpectedReturnLine`, which is the *permanent* one. No amendment to this
 *   return makes the parcel belong to it; it needs its own request. Creating a
 *   line here on the fly would let a warehouse authorise returns by receiving
 *   them, which is the entire approval step deleted by accident.
 *
 * The two exception classes are opposites on purpose. A surface uses `instanceof`
 * to answer `409` or `422` — never a message substring, which is the seam the
 * checkout module is living with.
 *
 * **Receipts are deltas and repeatable.** A return that comes back in two parcels
 * is received twice, and the second receipt is *another two*, never *now four*.
 * The first receipt moves the return to `received`; later ones write quantities
 * without a transition, because a second parcel is not a second state — and a
 * self-transition would file a history row against a move nobody made.
 *
 * Rows are re-read under a lock inside the transaction, so two desks scanning two
 * parcels of the same line at the same moment cannot both read
 * `quantity_received = 0` and both write `1`.
 */
final class ReceiveGoods
{
    /**
     * @param  list<LineReceipt>  $receipts
     */
    public function handle(ReturnRequest $return, array $receipts, ?int $actorId = null): ReturnRequest
    {
        if (! $return->status->acceptsGoods()) {
            throw UnexpectedReturnLine::notOpenToGoods($return->number, $return->status->value);
        }

        if ($receipts === []) {
            throw UnexpectedReturnLine::nothingToReturn();
        }

        foreach ($receipts as $receipt) {
            if ($receipt->quantity <= 0) {
                throw ReturnQuantityExceeded::notPositive($receipt->quantity);
            }
        }

        DB::transaction(function () use ($return, $receipts): void {
            foreach ($receipts as $receipt) {
                // Re-read under a row lock, once per receipt. The guard has to be
                // checked against what is in the database rather than what this
                // caller loaded, or two concurrent desks each pass a check the
                // other invalidated — and re-reading per receipt is also what
                // makes two entries for one line in a single call accumulate
                // rather than each measure themselves against the same start.
                $line = ReturnLine::query()
                    ->where('return_request_id', $return->id)
                    ->where('order_line_id', $receipt->orderLineId)
                    ->lockForUpdate()
                    ->first();

                if ($line === null) {
                    throw UnexpectedReturnLine::notRequested($return->number, $receipt->orderLineId);
                }

                $outstanding = $line->outstandingQuantity();

                if ($receipt->quantity > $outstanding) {
                    throw ReturnQuantityExceeded::overApproved($line->order_line_id, $receipt->quantity, $outstanding);
                }

                $line->forceFill(['quantity_received' => $line->quantity_received + $receipt->quantity])->save();
            }
        });

        if ($return->status === ReturnStatus::Approved) {
            (new TransitionReturn())->handle($return, ReturnStatus::Received, $actorId, 'goods-received');
        }

        ReturnGoodsReceived::dispatch(
            ReturnData::from($return->load(['lines', 'refunds'])),
            array_values($receipts),
            $actorId,
        );

        return $return;
    }
}
