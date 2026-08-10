<?php

declare(strict_types=1);

use Liberu\Ecommerce\Returns\Actions\ApproveReturn;
use Liberu\Ecommerce\Returns\Actions\ReceiveGoods;
use Liberu\Ecommerce\Returns\Data\LineReceipt;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Liberu\Ecommerce\Returns\Queries\ReturnQuery;

it('finds a return by its public reference and by nothing else', function () {
    $return = aReturn();

    expect((new ReturnQuery())->byNumber($return->number)?->id)->toBe($return->id)
        ->and((new ReturnQuery())->byNumber('RMA-NOTHING'))->toBeNull()
        // No `byId`, on purpose: an incrementing id in a URL is an enumeration of
        // everybody's returns.
        ->and(method_exists(ReturnQuery::class, 'byId'))->toBeFalse();
});

it('returns the published read model when asked for one', function () {
    $return = aReturn([returnLine(quantity: 2, returnableQuantity: 2)]);

    $data = (new ReturnQuery())->dataByNumber($return->number);

    expect($data?->number)->toBe($return->number)
        ->and($data?->lines)->toHaveCount(1)
        ->and($data?->lines[0]->orderLineId)->toBe(GHOST_LINE)
        ->and($data?->lines[0]->requestedQuantity)->toBe(2)
        ->and((new ReturnQuery())->dataByNumber('RMA-NOTHING'))->toBeNull();
});

it('lists every return raised against one order', function () {
    aReturn(orderId: GHOST_ORDER);
    aReturn(orderId: GHOST_ORDER);
    aReturn(orderId: 9000900);

    expect((new ReturnQuery())->forOrder(GHOST_ORDER)->count())->toBe(2);
});

it('finds returns by an order line id, which is all a host holds', function () {
    aReturn([returnLine(orderLineId: GHOST_LINE)]);
    aReturn([returnLine(orderLineId: GHOST_LINE_TWO)]);

    expect((new ReturnQuery())->forOrderLine(GHOST_LINE)->count())->toBe(1)
        ->and((new ReturnQuery())->forOrderLine(9000999)->count())->toBe(0);
});

it('adds up what came back for one order line across every return that names it', function () {
    // The number a host reconciles against the counter held by whoever owns the
    // order line. They should agree, because the host raises that counter from
    // this module's receipt event — and when they do not, this says by how much.
    $first = anApprovedReturn(2);
    (new ReceiveGoods())->handle($first, [new LineReceipt(GHOST_LINE, 2)]);

    $second = anApprovedReturn(1);
    (new ReceiveGoods())->handle($second, [new LineReceipt(GHOST_LINE, 1)]);

    expect((new ReturnQuery())->receivedForOrderLine(GHOST_LINE))->toBe(3)
        ->and((new ReturnQuery())->receivedForOrderLine(GHOST_LINE_TWO))->toBe(0);
});

it('lists what is still in progress, scoped to a team', function () {
    $mine = aReturn(teamId: 9000001);
    aReturn(teamId: 9000002);
    ReturnRequest::factory()->ownedBy(9000001)->status(ReturnStatus::Resolved)->create();

    $open = (new ReturnQuery())->open(9000001)->get();

    expect($open)->toHaveCount(1)
        ->and($open->first()->id)->toBe($mine->id);
});

it('lists everything open when no team is named, without listing the orphans a policy denies', function () {
    // `where('team_id', null)` compiles to `is null` and would list exactly the
    // rows nobody may act on. The scope is applied only when a value is bound.
    aReturn(teamId: 9000001);
    aReturn();

    expect((new ReturnQuery())->open()->count())->toBe(2);
});

it('finds approved returns whose goods are overdue, and only those', function () {
    $overdue = aReturn();
    (new ApproveReturn())->handle($overdue, goodsDueBy: now()->subDay());

    $inTime = aReturn();
    (new ApproveReturn())->handle($inTime, goodsDueBy: now()->addDays(10));

    // A merchant who named no window gets no expiry, rather than an immediate
    // one — `whereNotNull` rather than a comparison against null.
    $noWindow = aReturn();
    (new ApproveReturn())->handle($noWindow);

    $due = (new ReturnQuery())->awaitingGoodsSince(now())->get();

    expect($due)->toHaveCount(1)
        ->and($due->first()->id)->toBe($overdue->id);
});

it('lists one shopper s returns newest first', function () {
    ReturnRequest::factory()->create(['customer_id' => 9000700, 'requested_at' => now()->subDays(2)]);
    $newest = ReturnRequest::factory()->create(['customer_id' => 9000700, 'requested_at' => now()]);
    ReturnRequest::factory()->create(['customer_id' => 9000701]);

    $theirs = (new ReturnQuery())->forCustomer(9000700)->get();

    expect($theirs)->toHaveCount(2)
        ->and($theirs->first()->id)->toBe($newest->id);
});
