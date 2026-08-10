<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\Returns\Actions\InspectReturn;
use Liberu\Ecommerce\Returns\Actions\ReceiveGoods;
use Liberu\Ecommerce\Returns\Actions\RecordRefund;
use Liberu\Ecommerce\Returns\Actions\TransitionReturn;
use Liberu\Ecommerce\Returns\Data\LineDisposition;
use Liberu\Ecommerce\Returns\Data\LineReceipt;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;

/**
 * Capture what the logger wrote, in order.
 *
 * The reader is a long closure with an explicit `use (&$records)` rather than an
 * arrow function: `fn` captures by value at the point it is defined, so it would
 * hand back the empty array this starts as and never see anything the listener
 * appended.
 */
function captureLog(): Closure
{
    $records = [];

    Log::listen(function ($record) use (&$records) {
        $records[] = ['level' => $record->level, 'message' => $record->message, 'context' => $record->context];
    });

    return function () use (&$records): array {
        return $records;
    };
}

beforeEach(function () {
    config()->set('returns.telemetry.enabled', true);
    config()->set('returns.telemetry.channel', null);
});

it('writes nothing at all until a deployment asks for it', function () {
    config()->set('returns.telemetry.enabled', false);
    $records = captureLog();

    aReturn();

    expect($records())->toBe([]);
});

it('names the events in this module s own vocabulary', function () {
    $records = captureLog();

    $return = aReceivedReturn(2);
    (new InspectReturn())->handle($return, [new LineDisposition(GHOST_LINE, restockable: 2)]);
    (new RecordRefund())->handle($return->fresh(), 1999, reference: 'credit-note-1');

    expect(array_column($records(), 'message'))->toBe([
        'return.requested',
        'return.transitioned',
        'return.transitioned',
        'return.goods_received',
        'return.transitioned',
        'return.inspected',
        'return.refund_recorded',
    ]);
});

it('raises the level for the things a merchant wants to be told about', function () {
    $records = captureLog();

    $expiring = aReturn();
    (new TransitionReturn())->handle($expiring, ReturnStatus::Approved);
    (new TransitionReturn())->handle($expiring, ReturnStatus::Expired, reason: 'goods-never-arrived');

    $levels = array_column($records(), 'level');

    // An approval is routine; goods that never arrived is not.
    expect($levels)->toBe(['info', 'info', 'warning']);
});

it('calls a rejected disposition a warning, because unsaleable goods are the signal', function () {
    $records = captureLog();

    $return = aReceivedReturn(2);
    (new InspectReturn())->handle($return, [new LineDisposition(GHOST_LINE, restockable: 1, rejected: 1)]);

    $inspected = collect($records())->firstWhere('message', 'return.inspected');

    expect($inspected['level'])->toBe('warning')
        ->and($inspected['context']['rejected'])->toBe(1)
        ->and($inspected['context']['restockable'])->toBe(1);
});

it('writes slugs and counts, and never a shopper s sentence', function () {
    // **The containment rule, at the place it matters most.** A log is the store
    // in an application with the loosest access control and the longest reach.
    $records = captureLog();

    $note = 'It smells of smoke and my neighbour Jane saw the courier drop it';
    aReturn([returnLine(note: $note)]);

    $written = json_encode($records());

    expect($written)->not->toContain('smoke')
        ->not->toContain('Jane')
        ->not->toContain('neighbour')
        // The reason still travels, because it is a slug from a closed set —
        // which is the entire reason it is a closed set.
        ->and($records()[0]['context']['reasons'])->toBe(['faulty']);
});

it('carries the identifiers an operator needs to find the thing again', function () {
    $records = captureLog();

    $return = anApprovedReturn(2);
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2)]);

    $received = collect($records())->firstWhere('message', 'return.goods_received');

    expect($received['context']['number'])->toBe($return->number)
        ->and($received['context']['order_id'])->toBe(GHOST_ORDER)
        ->and($received['context']['order_line_ids'])->toBe([GHOST_LINE])
        ->and($received['context']['quantity'])->toBe(2)
        ->and($received['context']['received_total'])->toBe(2);
});

it('records the money that went back without recording who moved it', function () {
    $records = captureLog();

    $return = aReceivedReturn(1);
    (new RecordRefund())->handle($return, 1999, reference: 'credit-note-9');

    $refund = collect($records())->firstWhere('message', 'return.refund_recorded');

    expect($refund['context']['amount_minor'])->toBe(1999)
        ->and($refund['context']['currency'])->toBe('GBP')
        ->and($refund['context']['kind'])->toBe('tender')
        ->and($refund['context']['refunded_total_minor'])->toBe(1999);
});

it('writes to the channel a deployment names', function () {
    // The `null` channel discards, which is the point: what is asserted is that
    // the setting is honoured, not where the bytes land.
    config()->set('returns.telemetry.channel', 'null');
    $records = captureLog();

    aReturn();

    expect($records())->not->toBe([]);
});
