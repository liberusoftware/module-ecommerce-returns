<?php

namespace Liberu\Ecommerce\Returns\Actions;

use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Events\ReturnTransitioned;
use Liberu\Ecommerce\Returns\Exceptions\IllegalReturnTransition;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Liberu\Ecommerce\Returns\Models\ReturnStatusChange;

/**
 * The only way a return's status changes.
 *
 * `status` is not in `ReturnRequest::$fillable`, none of the eight state
 * timestamps are either, and there is no setter — so this is not a convenience,
 * it is the single door, and the transition table on `Enums\ReturnStatus` is
 * consulted before anything is written.
 *
 * **An illegal move throws and writes nothing.** Not the status, not the
 * timestamp, not a history row, not an event. An attempt that was refused is not
 * a transition that happened.
 *
 * **A self-transition is illegal too.** Approving an already-approved return is
 * not harmlessly idempotent: it files a history row against a move nobody made
 * and re-stamps `approved_at`, so the record of when the merchant authorised the
 * return becomes the record of when somebody last double-clicked. A caller that
 * wants idempotence checks the status; a caller that has lost track gets an
 * exception, which is the honest answer.
 *
 * The status and its timestamp move in one transaction with the history row, so a
 * return can never be `approved` with no record of when it was approved.
 *
 * `$reason` is a **short slug**, not free text. The domain event logger copies it,
 * and a text box next to an event logger is where a shopper's email address gets
 * typed into a log line. Sixty-four characters, enforced by the column.
 */
final class TransitionReturn
{
    public function handle(ReturnRequest $return, ReturnStatus $to, ?int $actorId = null, ?string $reason = null): ReturnRequest
    {
        $from = $return->status;

        if (! $from->canTransitionTo($to)) {
            throw IllegalReturnTransition::from($from, $to);
        }

        DB::transaction(function () use ($return, $from, $to, $actorId, $reason): void {
            $attributes = ['status' => $to];

            $stamp = $to->timestampColumn();

            if ($stamp !== null) {
                $attributes[$stamp] = now();
            }

            // `forceFill` because `status` is deliberately not fillable: the
            // guard above is the whole control, and a fillable status is a way
            // round it from any caller holding a request array.
            $return->forceFill($attributes)->save();

            ReturnStatusChange::query()->create([
                'return_request_id' => $return->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actorId,
                'reason' => $reason,
            ]);
        });

        ReturnTransitioned::dispatch(
            ReturnData::from($return->load(['lines', 'refunds'])),
            $from,
            $to,
            $reason,
            $actorId,
        );

        return $return;
    }
}
