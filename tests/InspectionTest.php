<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\Returns\Actions\InspectReturn;
use Liberu\Ecommerce\Returns\Actions\ReceiveGoods;
use Liberu\Ecommerce\Returns\Data\LineDisposition;
use Liberu\Ecommerce\Returns\Data\LineReceipt;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Events\ReturnInspected;
use Liberu\Ecommerce\Returns\Exceptions\IllegalReturnTransition;
use Liberu\Ecommerce\Returns\Exceptions\ReturnQuantityExceeded;
use Liberu\Ecommerce\Returns\Exceptions\UnexpectedReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * **A returned unit is not automatically saleable.**
 *
 * That sentence is the whole reason inspection is a step rather than a flag. The
 * host's design put a `restock_items` boolean on the refund and restocked
 * everything it was true for — one answer for the whole return, in the one place
 * where a single box holds an unopened item and a worn one.
 *
 * And **this module writes no stock.** It publishes the disposition; whoever owns
 * the stock ledger takes the movement, and the host decides whether it happens at
 * the desk or after a week in quarantine.
 */
it('splits what arrived into what is saleable and what is not', function () {
    $return = aReceivedReturn(3);

    (new InspectReturn())->handle($return, [new LineDisposition(GHOST_LINE, restockable: 2, rejected: 1)], actorId: 9000001);

    $line = $return->fresh()->lines[0];

    expect($return->fresh()->status)->toBe(ReturnStatus::Inspected)
        ->and($return->fresh()->inspected_at)->not->toBeNull()
        ->and($line->quantity_restockable)->toBe(2)
        ->and($line->quantity_rejected)->toBe(1)
        ->and($line->uninspectedQuantity())->toBe(0);
});

it('allows an inspection that has not finished, and reports the gap', function () {
    // Two of three looked at. The remainder is a real state, not an error — and
    // `uninspectedQuantity()` is how a merchant finds it rather than by
    // subtracting two columns at a call site.
    $return = aReceivedReturn(3);

    (new InspectReturn())->handle($return, [new LineDisposition(GHOST_LINE, restockable: 2)]);

    expect($return->fresh()->lines[0]->uninspectedQuantity())->toBe(1);
});

it('refuses a disposition for more than arrived', function () {
    // A disposition is a statement about goods somebody physically has.
    $return = aReceivedReturn(2);

    (new InspectReturn())->handle($return, [new LineDisposition(GHOST_LINE, restockable: 2, rejected: 1)]);
})->throws(ReturnQuantityExceeded::class, 'can never exceed what arrived');

it('refuses a disposition for a line that took no delivery', function () {
    $return = anApprovedReturn(2);
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 1)]);

    // The return is `received`, but this line is not the one that arrived.
    $return->lines()->create([
        'order_line_id' => GHOST_LINE_TWO,
        'name' => 'Lambswool Scarf',
        'reason' => 'faulty',
        'quantity_requested' => 1,
    ]);

    (new InspectReturn())->handle($return->fresh(), [new LineDisposition(GHOST_LINE_TWO, restockable: 1)]);
})->throws(UnexpectedReturnLine::class, 'nothing on it to inspect');

it('refuses a disposition for a line the return does not name at all', function () {
    (new InspectReturn())->handle(aReceivedReturn(2), [new LineDisposition(GHOST_LINE_TWO, restockable: 1)]);
})->throws(UnexpectedReturnLine::class, 'does not cover order line');

it('refuses an inspection that says nothing', function () {
    (new InspectReturn())->handle(aReceivedReturn(2), [new LineDisposition(GHOST_LINE, restockable: 0, rejected: 0)]);
})->throws(ReturnQuantityExceeded::class, 'must be positive');

it('refuses a negative disposition', function () {
    (new InspectReturn())->handle(aReceivedReturn(2), [new LineDisposition(GHOST_LINE, restockable: -1, rejected: 2)]);
})->throws(ReturnQuantityExceeded::class);

it('refuses to inspect a return no goods have reached', function (ReturnStatus $status) {
    (new InspectReturn())->handle(
        ReturnRequest::factory()->status($status)->create(),
        [new LineDisposition(GHOST_LINE, restockable: 1)],
    );
})->with([
    'requested' => [ReturnStatus::Requested],
    'approved, with the parcel still in a van' => [ReturnStatus::Approved],
    'expired' => [ReturnStatus::Expired],
    'already inspected' => [ReturnStatus::Inspected],
])->throws(IllegalReturnTransition::class);

it('writes no disposition when any line in the same call is bad', function () {
    $return = aReceivedReturn(3);

    try {
        (new InspectReturn())->handle($return, [
            new LineDisposition(GHOST_LINE, restockable: 1),
            new LineDisposition(GHOST_LINE_TWO, restockable: 1),
        ]);
    } catch (UnexpectedReturnLine) {
        // Expected.
    }

    expect($return->fresh()->lines[0]->quantity_restockable)->toBe(0)
        ->and($return->fresh()->status)->toBe(ReturnStatus::Received);
});

it('publishes the disposition and writes no stock', function () {
    Event::fake([ReturnInspected::class]);

    $return = aReceivedReturn(3);
    (new InspectReturn())->handle($return, [new LineDisposition(GHOST_LINE, restockable: 2, rejected: 1)]);

    Event::assertDispatched(ReturnInspected::class, function (ReturnInspected $event): bool {
        expect($event->restockableQuantity())->toBe(2)
            ->and($event->rejectedQuantity())->toBe(1)
            ->and($event->dispositions[0]->orderLineId)->toBe(GHOST_LINE)
            ->and($event->return->restockableQuantity())->toBe(2);

        return true;
    });

    // And nothing anywhere near a stock table, because there is none — no module
    // that owns stock is installed in this suite, and this package requires none.
    expect(Schema::hasTable('stock_levels'))->toBeFalse()
        ->and(Schema::hasTable('ecommerce_inventory_ledger_entries'))->toBeFalse();
});

it('closes the return to further goods once a disposition exists, which is a one-way door', function () {
    // Deliberate, and recorded in `docs/domain.md`. Once a disposition is
    // recorded the merchant prices the outcome, and a third parcel arriving
    // afterwards would land against arithmetic that has already been settled. A
    // late parcel is a new request, with its own authorisation — refused loudly
    // rather than absorbed quietly.
    $return = anApprovedReturn(3);
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2)]);
    (new InspectReturn())->handle($return, [new LineDisposition(GHOST_LINE, restockable: 2)]);

    expect($return->fresh()->acceptsGoods())->toBeFalse();

    (new ReceiveGoods())->handle($return->fresh(), [new LineReceipt(GHOST_LINE, 1)]);
})->throws(UnexpectedReturnLine::class, 'not open to goods');
