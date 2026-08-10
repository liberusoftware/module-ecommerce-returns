<?php

namespace Liberu\Ecommerce\Returns\Actions;

use Liberu\Ecommerce\Returns\Data\RefundData;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Enums\RefundKind;
use Liberu\Ecommerce\Returns\Events\RefundRecorded;
use Liberu\Ecommerce\Returns\Exceptions\ReturnNotRefundable;
use Liberu\Ecommerce\Returns\Models\Refund;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * Write down that money went back. **Record, not refund.**
 *
 * The verb is the design. Money movement belongs to whoever owns the tender; this
 * package has no gateway, names no provider anywhere in `src/`, and does not know
 * what a capture is. The host moves the money and hands the resulting reference
 * here, in that order — the event this dispatches is past tense and a listener
 * that calls a provider on it refunds every shopper twice.
 *
 * **The symmetric invariant is enforced here.** Whoever owns order lines refuses
 * to record a return of goods that never shipped; this refuses to record money
 * going back for goods that never came back. Both halves are needed because
 * neither module can see the other's counters, and between them they close the
 * loop: a request can be made, approved, and then the parcel never arrives — that
 * return expires, and nothing is owed on it.
 *
 * Within the loop, *when* to refund stays merchant policy: on receipt or after
 * inspection are both allowed, because both are honest and this package is not
 * entitled to an opinion.
 *
 * **Nothing caps the amount, and that is deliberate.** A cap would be
 * `line price × quantity`, and line money is frozen in the module that owns the
 * order — this package holds none of it and refuses to hold a copy. A copy would
 * be a second answer to what something cost, and the day it disagreed it would be
 * the copy a refund was computed from. Whoever knows the prices decides the
 * amount; this records it.
 *
 * Tax is an input in the strongest sense: a rate in basis points, an
 * already-computed amount, both, or neither. Nothing is derived, nothing is
 * looked up, no jurisdiction is known.
 *
 * Several refunds per return are ordinary — goods on receipt and shipping after a
 * conversation are two decisions. `ReturnRequest::refundedMinor()` sums the rows,
 * and no total is stored anywhere.
 */
final class RecordRefund
{
    public function handle(
        ReturnRequest $return,
        int $amountMinor,
        RefundKind $kind = RefundKind::Tender,
        ?string $reference = null,
        ?int $taxMinor = null,
        ?int $taxRateBp = null,
        ?int $actorId = null,
        ?string $currency = null,
    ): Refund {
        if (! $return->status->allowsRefund() || ! $return->load('lines')->hasGoods()) {
            throw ReturnNotRefundable::nothingReceived($return->number, $return->status->value);
        }

        if ($amountMinor <= 0) {
            throw ReturnNotRefundable::notPositive($amountMinor);
        }

        $currency ??= $return->currency;

        if ($currency !== $return->currency) {
            throw ReturnNotRefundable::currencyMismatch($return->currency, $currency);
        }

        $refund = $return->refunds()->create([
            'kind' => $kind,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'currency_exponent' => $return->currency_exponent,
            'tax_minor' => $taxMinor,
            'tax_rate_bp' => $taxRateBp,
            'reference' => $reference,
            'actor_id' => $actorId,
            'recorded_at' => now(),
        ]);

        RefundRecorded::dispatch(
            ReturnData::from($return->load(['lines', 'refunds'])),
            RefundData::from($refund),
        );

        return $refund;
    }
}
