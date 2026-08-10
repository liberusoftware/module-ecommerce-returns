<?php

namespace Liberu\Ecommerce\Returns\Actions;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Exceptions\IllegalReturnTransition;
use Liberu\Ecommerce\Returns\Exceptions\ReturnQuantityExceeded;
use Liberu\Ecommerce\Returns\Exceptions\UnexpectedReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * The merchant authorises a return — **for a quantity of their own choosing.**
 *
 * Approval is not a rubber stamp on the request, and that is why it is its own
 * action rather than a bare transition. A shopper asks to send back five and the
 * merchant agrees to three; the two numbers are different facts and both are
 * kept, so the shortfall is visible later instead of the request being quietly
 * rewritten.
 *
 * **Less than requested is allowed. More is refused.** Authorising more than was
 * asked for invents a request the shopper never made, and the extra units then
 * arrive, get received, and turn into a refund nobody agreed to. Refused rather
 * than clamped, matching the module that owns order lines.
 *
 * `$goodsDueBy` is the authorisation window, and it is supplied rather than
 * computed: how long a shopper gets is merchant policy, and a package that
 * defaulted it to thirty days would have decided somebody's returns policy in a
 * constant. Leave it null and the return simply never appears in the expiry
 * sweep.
 *
 * The transition is checked **before** any quantity is written, so an approval of
 * an already-approved return leaves the numbers exactly as they were.
 */
final class ApproveReturn
{
    /**
     * @param  array<int, int>  $approvedQuantities  order line id ⇒ quantity, defaulting to what was requested
     */
    public function handle(
        ReturnRequest $return,
        array $approvedQuantities = [],
        ?int $actorId = null,
        ?string $reason = null,
        ?DateTimeInterface $goodsDueBy = null,
    ): ReturnRequest {
        if (! $return->status->canTransitionTo(ReturnStatus::Approved)) {
            throw IllegalReturnTransition::from($return->status, ReturnStatus::Approved);
        }

        $lines = $return->lines()->get()->keyBy('order_line_id');

        foreach (array_keys($approvedQuantities) as $orderLineId) {
            if (! $lines->has($orderLineId)) {
                throw UnexpectedReturnLine::notRequested($return->number, (int) $orderLineId);
            }
        }

        foreach ($lines as $line) {
            /** @var ReturnLine $line */
            $wanted = $approvedQuantities[$line->order_line_id] ?? $line->quantity_requested;

            if ($wanted <= 0) {
                throw ReturnQuantityExceeded::notPositive($wanted);
            }

            if ($wanted > $line->quantity_requested) {
                throw ReturnQuantityExceeded::overRequested($line->order_line_id, $wanted, $line->quantity_requested);
            }
        }

        DB::transaction(function () use ($return, $lines, $approvedQuantities, $goodsDueBy): void {
            foreach ($lines as $line) {
                /** @var ReturnLine $line */
                $line->forceFill([
                    'quantity_approved' => $approvedQuantities[$line->order_line_id] ?? $line->quantity_requested,
                ])->save();
            }

            if ($goodsDueBy !== null) {
                $return->forceFill(['goods_due_by' => $goodsDueBy])->save();
            }
        });

        return (new TransitionReturn())->handle($return, ReturnStatus::Approved, $actorId, $reason);
    }
}
