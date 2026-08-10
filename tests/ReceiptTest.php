<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Returns\Actions\ReceiveGoods;
use Liberu\Ecommerce\Returns\Actions\TransitionReturn;
use Liberu\Ecommerce\Returns\Data\LineReceipt;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Events\ReturnGoodsReceived;
use Liberu\Ecommerce\Returns\Exceptions\ReturnQuantityExceeded;
use Liberu\Ecommerce\Returns\Exceptions\UnexpectedReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * **Where requested and received stop being the same number.**
 *
 * Three disagreements are possible at a receiving desk and each has a decided
 * answer here rather than an answer left to whoever is holding the box:
 *
 * - fewer arrive than were approved — **allowed**, and ordinary;
 * - more arrive than were approved — **refused**, amendably;
 * - something arrives that was never requested — **refused**, permanently.
 *
 * The last two are opposite conditions and they get opposite exception classes,
 * because a surface has to answer `409` or `422` without parsing a message.
 */
it('takes delivery and moves the return along on the first parcel', function () {
    $return = anApprovedReturn(3);

    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 3)], actorId: 9000001);

    expect($return->fresh()->status)->toBe(ReturnStatus::Received)
        ->and($return->fresh()->received_at)->not->toBeNull()
        ->and($return->fresh()->lines[0]->quantity_received)->toBe(3)
        ->and($return->fresh()->lines[0]->outstandingQuantity())->toBe(0);
});

it('accepts fewer than were approved, and calls the difference a shortfall rather than an error', function () {
    // A shopper posts two of the three they meant to. This is what shoppers and
    // couriers do; nothing is refused and nothing is topped up. The unit that
    // never came is simply never resolvable.
    $return = anApprovedReturn(3);

    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2)]);

    $line = $return->fresh()->lines[0];

    expect($return->fresh()->status)->toBe(ReturnStatus::Received)
        ->and($line->quantity_approved)->toBe(3)
        ->and($line->quantity_received)->toBe(2)
        ->and($line->shortfallQuantity())->toBe(1)
        ->and($line->outstandingQuantity())->toBe(1);
});

it('accumulates a second parcel rather than treating it as a second state', function () {
    $return = anApprovedReturn(3);

    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 1)]);
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2)]);

    expect($return->fresh()->lines[0]->quantity_received)->toBe(3)
        // Still `received` — a second parcel is not a second state, and a
        // self-transition would file a history row against a move nobody made.
        ->and($return->fresh()->status)->toBe(ReturnStatus::Received)
        ->and($return->fresh()->statusChanges)->toHaveCount(3);
});

it('adds two receipts for one line in a single call rather than measuring both against the start', function () {
    // Each receipt re-reads the row under a lock, so two entries for one line
    // accumulate. Measuring both against the quantity at the top of the call
    // would let 2 + 2 pass a guard of 3.
    $return = anApprovedReturn(4);

    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2), new LineReceipt(GHOST_LINE, 2)]);

    expect($return->fresh()->lines[0]->quantity_received)->toBe(4);
});

it('refuses more than was approved, and says so amendably', function () {
    $return = anApprovedReturn(2);

    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 3)]);
})->throws(ReturnQuantityExceeded::class, 'Raise the approval');

it('refuses the parcel that tips a line over, across two calls', function () {
    $return = anApprovedReturn(3);
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2)]);

    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2)]);
})->throws(ReturnQuantityExceeded::class);

it('writes nothing when a receipt in the same call is over, because the whole call is one transaction', function () {
    $return = anApprovedReturn(3);

    try {
        (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 1), new LineReceipt(GHOST_LINE, 9)]);
    } catch (ReturnQuantityExceeded) {
        // Expected.
    }

    expect($return->fresh()->lines[0]->quantity_received)->toBe(0);
});

it('refuses goods against a line nobody authorised, permanently', function () {
    // No amendment to *this* return makes the parcel belong to it. Creating a
    // line here on the fly would let a warehouse authorise returns by receiving
    // them, which is the entire approval step deleted by accident.
    $return = anApprovedReturn(3, GHOST_LINE);

    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE_TWO, 1)]);
})->throws(UnexpectedReturnLine::class, 'does not cover order line');

it('tells the two receipt failures apart by class, so a surface never parses a message', function () {
    // **The wave-4 finding, not repeated.** One exception class for two opposite
    // conditions is a seam: a permanent conflict and an amendable one need
    // different answers, and rebuilding a message to tell them apart is what a
    // sibling package is living with.
    $return = anApprovedReturn(1);

    $unexpected = null;
    $exceeded = null;

    try {
        (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE_TWO, 1)]);
    } catch (Throwable $thrown) {
        $unexpected = $thrown;
    }

    try {
        (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 5)]);
    } catch (Throwable $thrown) {
        $exceeded = $thrown;
    }

    expect($unexpected)->toBeInstanceOf(UnexpectedReturnLine::class)
        ->and($unexpected)->not->toBeInstanceOf(ReturnQuantityExceeded::class)
        ->and($exceeded)->toBeInstanceOf(ReturnQuantityExceeded::class)
        ->and($exceeded)->not->toBeInstanceOf(UnexpectedReturnLine::class);
});

it('refuses goods against a return that is not open to them', function (ReturnStatus $status) {
    $return = ReturnRequest::factory()->status($status)->create();

    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 1)]);
})->with([
    'not yet authorised' => [ReturnStatus::Requested],
    'refused' => [ReturnStatus::Refused],
    'cancelled' => [ReturnStatus::Cancelled],
    // The case this domain is defined by: the goods finally turned up, after the
    // merchant closed the authorisation. Adopting them would write a receipt
    // nobody agreed to.
    'expired' => [ReturnStatus::Expired],
    'already inspected' => [ReturnStatus::Inspected],
    'resolved' => [ReturnStatus::Resolved],
])->throws(UnexpectedReturnLine::class, 'not open to goods');

it('refuses a receipt of nothing', function () {
    (new ReceiveGoods())->handle(anApprovedReturn(2), []);
})->throws(UnexpectedReturnLine::class);

it('refuses a zero or negative quantity, because there is no move that un-receives a parcel', function () {
    (new ReceiveGoods())->handle(anApprovedReturn(2), [new LineReceipt(GHOST_LINE, 0)]);
})->throws(ReturnQuantityExceeded::class, 'must be positive');

it('publishes what came back as ids and quantities, which is all the far side needs', function () {
    Event::fake([ReturnGoodsReceived::class]);

    $return = anApprovedReturn(3);
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2)], actorId: 9000001);

    Event::assertDispatched(ReturnGoodsReceived::class, function (ReturnGoodsReceived $event): bool {
        expect($event->receipts)->toHaveCount(1)
            ->and($event->receipts[0]->orderLineId)->toBe(GHOST_LINE)
            ->and($event->receipts[0]->quantity)->toBe(2)
            ->and($event->quantity())->toBe(2)
            ->and($event->actorId)->toBe(9000001)
            // The state *after* the receipt, so a listener reads rather than
            // recomputes.
            ->and($event->return->receivedQuantity())->toBe(2);

        return true;
    });
});

it('publishes deltas rather than totals, because the counter on the far side is append-only too', function () {
    $dispatched = [];

    Event::listen(ReturnGoodsReceived::class, function (ReturnGoodsReceived $event) use (&$dispatched): void {
        $dispatched[] = $event->quantity();
    });

    $return = anApprovedReturn(5);
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2)]);
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 3)]);

    // Two and then three, never two and then five. A total posted twice would be
    // double the goods on whoever is counting.
    expect($dispatched)->toBe([2, 3]);
});

it('handles two lines of one return independently', function () {
    $return = aReturn([
        returnLine(orderLineId: GHOST_LINE, quantity: 2, returnableQuantity: 2),
        returnLine(orderLineId: GHOST_LINE_TWO, quantity: 1, returnableQuantity: 1),
    ]);
    (new ApproveReturn())->handle($return);

    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2), new LineReceipt(GHOST_LINE_TWO, 1)]);

    $lines = $return->fresh()->lines->keyBy('order_line_id');

    expect($lines[GHOST_LINE]->quantity_received)->toBe(2)
        ->and($lines[GHOST_LINE_TWO]->quantity_received)->toBe(1);
});

it('cannot be receipted after the merchant expired it, even by a transition', function () {
    $return = anApprovedReturn(2);
    (new TransitionReturn())->handle($return, ReturnStatus::Expired, reason: 'goods-never-arrived');

    expect($return->fresh()->status)->toBe(ReturnStatus::Expired)
        ->and($return->fresh()->acceptsGoods())->toBeFalse();
});
