<?php

namespace Liberu\Ecommerce\Returns\Actions;

use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\Returns\Data\LineDisposition;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Events\ReturnInspected;
use Liberu\Ecommerce\Returns\Exceptions\IllegalReturnTransition;
use Liberu\Ecommerce\Returns\Exceptions\ReturnQuantityExceeded;
use Liberu\Ecommerce\Returns\Exceptions\UnexpectedReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * Somebody looked at what came back and said what condition it is in.
 *
 * **A returned unit is not automatically saleable**, which is the entire reason
 * this step exists. The host's design put a `restock_items` boolean on the refund
 * and restocked everything it was true for; that answers for the whole return at
 * once, and a return is exactly where one box holds an unopened item and a worn
 * one.
 *
 * **This module writes no stock.** It publishes `Events\ReturnInspected` with the
 * dispositions and the host decides — a warehouse that quarantines returns for a
 * week and one that restocks at the desk are both correct, and neither is this
 * package's to assume. Whoever owns the stock ledger takes the movement.
 *
 * `restockable + rejected` may be **less** than what arrived — an inspection that
 * has not finished is a real state, and `uninspectedQuantity()` reports the gap.
 * It may never be **more**: a disposition is a statement about goods somebody
 * physically has.
 *
 * **Inspecting closes the return to further goods**, and that is a deliberate
 * one-way door rather than an oversight. Once a disposition is recorded the
 * merchant prices the outcome, and a third parcel arriving afterwards would land
 * against arithmetic that has already been settled. A late parcel is a new
 * request, with its own authorisation — refused loudly here rather than absorbed
 * quietly. `docs/domain.md` records the trade.
 */
final class InspectReturn
{
    /**
     * @param  list<LineDisposition>  $dispositions
     */
    public function handle(ReturnRequest $return, array $dispositions, ?int $actorId = null, ?string $reason = null): ReturnRequest
    {
        if (! $return->status->canTransitionTo(ReturnStatus::Inspected)) {
            throw IllegalReturnTransition::from($return->status, ReturnStatus::Inspected);
        }

        if ($dispositions === []) {
            throw UnexpectedReturnLine::nothingToReturn();
        }

        $lines = $return->lines()->get()->keyBy('order_line_id');

        foreach ($dispositions as $disposition) {
            $line = $lines->get($disposition->orderLineId);

            if ($line === null) {
                throw UnexpectedReturnLine::notRequested($return->number, $disposition->orderLineId);
            }

            /** @var ReturnLine $line */
            if ($line->quantity_received <= 0) {
                throw UnexpectedReturnLine::nothingToInspect($return->number, $disposition->orderLineId);
            }

            if ($disposition->restockable < 0 || $disposition->rejected < 0) {
                throw ReturnQuantityExceeded::notPositive(min($disposition->restockable, $disposition->rejected));
            }

            if ($disposition->total() <= 0) {
                throw ReturnQuantityExceeded::notPositive($disposition->total());
            }

            if ($disposition->total() > $line->uninspectedQuantity()) {
                throw ReturnQuantityExceeded::overReceived($line->order_line_id, $disposition->total(), $line->uninspectedQuantity());
            }
        }

        DB::transaction(function () use ($dispositions, $lines): void {
            foreach ($dispositions as $disposition) {
                /** @var ReturnLine $line */
                $line = $lines->get($disposition->orderLineId);

                $line->forceFill([
                    'quantity_restockable' => $line->quantity_restockable + $disposition->restockable,
                    'quantity_rejected' => $line->quantity_rejected + $disposition->rejected,
                ])->save();
            }
        });

        (new TransitionReturn())->handle($return, ReturnStatus::Inspected, $actorId, $reason);

        ReturnInspected::dispatch(
            ReturnData::from($return->load(['lines', 'refunds'])),
            array_values($dispositions),
            $actorId,
        );

        return $return;
    }
}
