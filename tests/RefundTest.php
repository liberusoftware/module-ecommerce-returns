<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\Returns\Actions\InspectReturn;
use Liberu\Ecommerce\Returns\Actions\RecordRefund;
use Liberu\Ecommerce\Returns\Actions\TransitionReturn;
use Liberu\Ecommerce\Returns\Data\LineDisposition;
use Liberu\Ecommerce\Returns\Enums\RefundKind;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Events\RefundRecorded;
use Liberu\Ecommerce\Returns\Exceptions\ReturnNotRefundable;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * **A refund is an amount and a reference. It is not a payment.**
 *
 * The host's `Refund::process()` voids a charge with a gateway, then restocks,
 * then increments a total on the order, then transitions the order, then emails
 * the customer — five domains in one method, and the money is a `decimal(10,2)`.
 * This module does one of those five, and the other four are somebody's to
 * subscribe to.
 *
 * The rule underneath it all is the symmetric invariant: whoever owns order lines
 * refuses to record a return of goods that never shipped; this refuses to record
 * money going back for goods that never came back.
 */
it('records an amount and a reference, and nothing that implies a gateway', function () {
    $return = aReceivedReturn(2);

    $refund = (new RecordRefund())->handle(
        $return,
        amountMinor: 3998,
        kind: RefundKind::Tender,
        reference: 'credit-note-8812',
        taxRateBp: 2000,
        taxMinor: 666,
        actorId: 9000001,
    );

    expect($refund->amount_minor)->toBe(3998)
        ->and($refund->currency)->toBe('GBP')
        ->and($refund->currency_exponent)->toBe(2)
        ->and($refund->reference)->toBe('credit-note-8812')
        ->and($refund->kind)->toBe(RefundKind::Tender)
        // Tax is an input, in both shapes, and neither is derived from the other.
        ->and($refund->tax_rate_bp)->toBe(2000)
        ->and($refund->tax_minor)->toBe(666)
        // The API money shape, with `decimal` a string — a JSON number is a float
        // the moment it is parsed on the far side.
        ->and($refund->amount()->toArray())
        ->toBe(['minor' => 3998, 'currency' => 'GBP', 'exponent' => 2, 'decimal' => '39.98']);
});

it('refuses money for goods that never came back', function (ReturnStatus $status) {
    // **The symmetric invariant.** A request can be made, approved, and then the
    // parcel simply never arrives; that return expires, and nothing is owed on
    // it. Without this, the most expensive mistake in the domain is one impatient
    // support ticket away.
    (new RecordRefund())->handle(ReturnRequest::factory()->status($status)->create(), 1000);
})->with([
    'nothing authorised yet' => [ReturnStatus::Requested],
    'authorised, parcel still in a van' => [ReturnStatus::Approved],
    'refused' => [ReturnStatus::Refused],
    'cancelled' => [ReturnStatus::Cancelled],
    'the goods never came' => [ReturnStatus::Expired],
])->throws(ReturnNotRefundable::class);

it('refuses money against a return marked received that took delivery of nothing', function () {
    // The state says a receipt happened; the arithmetic says it was for nothing.
    // Both halves are checked, because a parcel that turns up empty does not
    // entitle anybody to a refund.
    $return = ReturnRequest::factory()->received()->create();
    $return->lines()->create([
        'order_line_id' => GHOST_LINE,
        'name' => 'Merino Crew',
        'reason' => 'faulty',
        'quantity_requested' => 1,
    ]);

    (new RecordRefund())->handle($return->fresh(), 1000);
})->throws(ReturnNotRefundable::class, 'delivery of nothing');

it('lets a merchant refund on receipt or after inspection, because both are honest', function () {
    $onReceipt = aReceivedReturn(1);
    expect((new RecordRefund())->handle($onReceipt, 1999)->amount_minor)->toBe(1999);

    $afterInspection = aReceivedReturn(1);
    (new InspectReturn())->handle($afterInspection, [new LineDisposition(GHOST_LINE, restockable: 1)]);
    expect((new RecordRefund())->handle($afterInspection, 1999)->amount_minor)->toBe(1999);

    $afterResolution = aReceivedReturn(1);
    (new InspectReturn())->handle($afterResolution, [new LineDisposition(GHOST_LINE, restockable: 1)]);
    (new TransitionReturn())->handle($afterResolution, ReturnStatus::Resolved);
    expect((new RecordRefund())->handle($afterResolution, 500)->amount_minor)->toBe(500);
});

it('records several refunds against one return, and stores no total', function () {
    // Goods refunded on receipt and shipping refunded after a conversation are two
    // decisions, and one mutable number cannot hold both. The sum is computed
    // where it is asked for; there is no `refund_total` column and no
    // `fully_refunded` flag, because this module holds no line prices and could
    // not know what "fully" would be.
    $return = aReceivedReturn(2);
    $record = new RecordRefund();

    $record->handle($return, 3998, reference: 'goods');
    $record->handle($return, 495, kind: RefundKind::StoreCredit, reference: 'postage');

    expect($return->fresh()->load('refunds')->refundedMinor())->toBe(4493)
        ->and($return->fresh()->refunds)->toHaveCount(2);
});

it('refuses a zero or negative refund, because charging is not a thing a returns module does', function (int $amount) {
    (new RecordRefund())->handle(aReceivedReturn(1), $amount);
})->with([
    'zero' => [0],
    'a charge in disguise' => [-500],
])->throws(ReturnNotRefundable::class, 'must be positive');

it('refuses a refund in a currency the return was not agreed in', function () {
    // This module holds no rates and converts nothing. A refund in another
    // currency is a different transaction somebody else is responsible for.
    (new RecordRefund())->handle(aReceivedReturn(1), 1999, currency: 'EUR');
})->throws(ReturnNotRefundable::class, 'converts nothing');

it('caps nothing, and says why', function () {
    // **What this module refuses to own.** A cap would be `line price × quantity`,
    // and line money is frozen in the module that owns the order. Holding a copy
    // would be a second answer to what something cost, and the day it disagreed
    // it would be the copy a refund was computed from. Whoever knows the prices
    // decides the amount.
    $return = aReceivedReturn(1);

    $refund = (new RecordRefund())->handle($return, 99999999, reference: 'goodwill');

    expect($refund->amount_minor)->toBe(99999999)
        ->and(collect(Schema::getColumns('ecommerce_returns_refunds'))->pluck('name'))
        ->not->toContain('status')
        ->not->toContain('transaction_id');
});

it('publishes a past-tense event that means the money already moved', function () {
    Event::fake([RefundRecorded::class]);

    $return = aReceivedReturn(2);
    (new RecordRefund())->handle($return, 2500, kind: RefundKind::Exchange, reference: 'swap-771');

    Event::assertDispatched(RefundRecorded::class, function (RefundRecorded $event): bool {
        expect($event->refund->amountMinor)->toBe(2500)
            ->and($event->refund->kind)->toBe(RefundKind::Exchange)
            ->and($event->refund->reference)->toBe('swap-771')
            ->and($event->return->refundedMinor)->toBe(2500)
            ->and($event->refund->amount()->decimal())->toBe('25.00');

        return true;
    });
});

it('keeps the four refund kinds apart, because they are different accounting facts', function () {
    // A store credit is a liability the merchant still owes; a tender refund is
    // cash that has left; an exchange is neither. A report that cannot tell them
    // apart is useless — and none of the four names a provider.
    expect(array_map(fn (RefundKind $kind): string => $kind->value, RefundKind::cases()))
        ->toBe(['tender', 'store_credit', 'exchange', 'manual']);
});
