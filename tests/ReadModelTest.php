<?php

declare(strict_types=1);

use Liberu\Ecommerce\Returns\Actions\InspectReturn;
use Liberu\Ecommerce\Returns\Actions\ReceiveGoods;
use Liberu\Ecommerce\Returns\Actions\RecordRefund;
use Liberu\Ecommerce\Returns\Data\LineDisposition;
use Liberu\Ecommerce\Returns\Data\LineReceipt;
use Liberu\Ecommerce\Returns\Data\RefundData;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Data\ReturnLineData;

/**
 * The values that cross a boundary, pinned.
 *
 * These are what a surface renders and what an event carries — plain readonly
 * values rather than Eloquent models, because a consumer holding a model holds
 * its table name, its casts and its scopes, and every one of those becomes a
 * breaking change the day it moves.
 *
 * The serialised keys are asserted literally. Changing one on purpose means an
 * entry in the changelog and, past 1.0.0, a major version.
 */
it('serialises a whole return in the shape a consumer reads', function () {
    $return = aReceivedReturn(3);
    (new InspectReturn())->handle($return, [new LineDisposition(GHOST_LINE, restockable: 2, rejected: 1)]);
    (new RecordRefund())->handle($return->fresh(), 1999, reference: 'credit-note-3');

    $data = ReturnData::from($return->fresh()->load(['lines', 'refunds']));
    $array = $data->toArray();

    expect(array_keys($array))->toBe([
        'id', 'number', 'order_id', 'status', 'currency', 'exponent',
        'team_id', 'store_id', 'customer_id', 'goods_due_by',
        'received_quantity', 'restockable_quantity', 'refunded_minor', 'lines',
    ])
        ->and($array['order_id'])->toBe(GHOST_ORDER)
        ->and($array['status'])->toBe('inspected')
        ->and($array['received_quantity'])->toBe(3)
        ->and($array['restockable_quantity'])->toBe(2)
        ->and($array['refunded_minor'])->toBe(1999)
        ->and($data->refunded()->decimal())->toBe('19.99')
        ->and(json_decode((string) json_encode($data), true))->toBe($array);
});

it('serialises a line with all six quantities and all three derived ones', function () {
    $return = anApprovedReturn(5);
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 3)]);
    (new InspectReturn())->handle($return, [new LineDisposition(GHOST_LINE, restockable: 2)]);

    $line = ReturnLineData::from($return->fresh()->lines[0]);
    $array = $line->toArray();

    expect(array_keys($array))->toBe([
        'id', 'return_request_id', 'order_line_id', 'name', 'sku', 'product_id',
        'variant_id', 'reason', 'returnable_quantity', 'requested_quantity',
        'approved_quantity', 'received_quantity', 'restockable_quantity',
        'rejected_quantity', 'outstanding_quantity', 'uninspected_quantity',
        'shortfall_quantity',
    ])
        // Nobody derives one of these and gets it wrong: 5 approved, 3 arrived,
        // 2 looked at.
        ->and($array['outstanding_quantity'])->toBe(2)
        ->and($array['uninspected_quantity'])->toBe(1)
        ->and($array['shortfall_quantity'])->toBe(2)
        ->and($array['reason'])->toBe('faulty')
        // **The containment rule**, as a key that is not there.
        ->and($array)->not->toHaveKey('note')
        ->and(json_decode((string) json_encode($line), true))->toBe($array);
});

it('serialises a refund as an amount and a reference and nothing more', function () {
    $return = aReceivedReturn(1);
    $refund = (new RecordRefund())->handle($return, 2500, reference: 'credit-note-4', taxMinor: 417, taxRateBp: 2000);

    $data = RefundData::from($refund);
    $array = $data->toArray();

    expect(array_keys($array))->toBe([
        'id', 'return_request_id', 'kind', 'amount', 'amount_minor', 'currency',
        'exponent', 'tax_minor', 'tax_rate_bp', 'reference', 'actor_id',
    ])
        ->and($array['amount'])->toBe(['minor' => 2500, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '25.00'])
        ->and($data->amount()->minor)->toBe(2500)
        // No status, no provider, no balance.
        ->and($array)->not->toHaveKey('status')
        ->and($array)->not->toHaveKey('provider')
        ->and(json_decode((string) json_encode($data), true))->toBe($array);
});

it('serialises a disposition and a receipt as the two things that cross a boundary', function () {
    $receipt = new LineReceipt(GHOST_LINE, 2);
    $disposition = new LineDisposition(GHOST_LINE, restockable: 1, rejected: 1);

    expect(json_decode((string) json_encode($receipt), true))
        ->toBe(['order_line_id' => GHOST_LINE, 'quantity' => 2])
        ->and(json_decode((string) json_encode($disposition), true))
        ->toBe(['order_line_id' => GHOST_LINE, 'restockable' => 1, 'rejected' => 1])
        ->and($disposition->total())->toBe(2);
});
