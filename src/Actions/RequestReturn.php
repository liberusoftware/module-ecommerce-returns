<?php

namespace Liberu\Ecommerce\Returns\Actions;

use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Data\ReturnRequestInput;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Events\ReturnRequested;
use Liberu\Ecommerce\Returns\Exceptions\ReturnQuantityExceeded;
use Liberu\Ecommerce\Returns\Exceptions\UnexpectedReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Liberu\Ecommerce\Returns\Models\ReturnStatusChange;

/**
 * A shopper asks to send something back.
 *
 * **This module does not decide eligibility, and refuses to pretend it can.**
 * Whether a unit may come back is `delivered − already returned`, and both of
 * those counters live in the module that owns order lines. So
 * `ReturnLineInput::$returnableQuantity` is an input — read by the caller from
 * wherever that lives and handed in, exactly the way a tax rate is handed in.
 *
 * What this action does with it is refuse a request the caller has itself just
 * said is impossible, and **store the number as evidence**: three months later,
 * the argument is about what the shopper was told they could send back on the day
 * they asked, and that is a fact worth keeping.
 *
 * There is no idempotency key here, unlike order placement, and that is a
 * decision rather than an omission. A duplicated *placement* charges somebody
 * twice; a duplicated *return request* is a second piece of paper a merchant can
 * refuse. What is guarded is the thing that would actually corrupt the
 * arithmetic — a unique index on `(return_request_id, order_line_id)`, so one
 * return can never list the same order line twice and make every quantity on it
 * ambiguous.
 *
 * A return opens with a history row whose `from_status` is null: the request came
 * from nowhere.
 */
final class RequestReturn
{
    public function handle(ReturnRequestInput $input, ?int $actorId = null): ReturnRequest
    {
        if ($input->lines === []) {
            throw UnexpectedReturnLine::nothingToReturn();
        }

        foreach ($input->lines as $line) {
            if ($line->quantity <= 0) {
                throw ReturnQuantityExceeded::notPositive($line->quantity);
            }

            if ($line->quantity > $line->returnableQuantity) {
                throw ReturnQuantityExceeded::overReturnable($line->orderLineId, $line->quantity, $line->returnableQuantity);
            }
        }

        $return = DB::transaction(function () use ($input, $actorId): ReturnRequest {
            $return = ReturnRequest::query()->create([
                'number' => ReturnRequest::generateNumber(),
                'team_id' => $input->teamId,
                'store_id' => $input->storeId,
                'customer_id' => $input->customerId,
                'order_id' => $input->orderId,
                'currency' => $input->currency,
                'currency_exponent' => $input->currencyExponent,
                'requested_at' => $input->requestedAt ?? now(),
            ]);

            foreach ($input->lines as $line) {
                $return->lines()->create($line->toAttributes());
            }

            ReturnStatusChange::query()->create([
                'return_request_id' => $return->id,
                'from_status' => null,
                'to_status' => ReturnStatus::Requested,
                'actor_id' => $actorId,
                'reason' => null,
            ]);

            return $return;
        });

        ReturnRequested::dispatch(ReturnData::from($return->load(['lines', 'refunds'])));

        return $return;
    }
}
