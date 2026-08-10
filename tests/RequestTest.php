<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Returns\Actions\ApproveReturn;
use Liberu\Ecommerce\Returns\Actions\RequestReturn;
use Liberu\Ecommerce\Returns\Data\ReturnData;
use Liberu\Ecommerce\Returns\Enums\ReturnReason;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Events\ReturnRequested;
use Liberu\Ecommerce\Returns\Exceptions\IllegalReturnTransition;
use Liberu\Ecommerce\Returns\Exceptions\ReturnQuantityExceeded;
use Liberu\Ecommerce\Returns\Exceptions\UnexpectedReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * Asking to send something back, and the merchant's answer.
 *
 * **Eligibility is an input.** Whether a unit may come back is
 * `delivered − already returned`, and both counters live in the module that owns
 * order lines. This package cannot compute it, refuses to guess it, and stores
 * the number it was handed as evidence of what the shopper was told on the day.
 */
it('opens a return over identifiers alone', function () {
    $return = (new RequestReturn())->handle(returnInput([
        returnLine(orderLineId: GHOST_LINE, quantity: 2, returnableQuantity: 3, reason: ReturnReason::NotAsDescribed),
    ], teamId: 9000001));

    expect($return->status)->toBe(ReturnStatus::Requested)
        ->and($return->number)->toStartWith('RMA-')
        ->and($return->order_id)->toBe(GHOST_ORDER)
        ->and($return->team_id)->toBe(9000001)
        ->and($return->lines)->toHaveCount(1)
        ->and($return->lines[0]->order_line_id)->toBe(GHOST_LINE)
        ->and($return->lines[0]->quantity_requested)->toBe(2)
        // The ceiling, kept as evidence rather than recomputed.
        ->and($return->lines[0]->returnable_quantity)->toBe(3)
        ->and($return->lines[0]->reason)->toBe(ReturnReason::NotAsDescribed)
        // Nothing is authorised yet, and nothing has moved.
        ->and($return->lines[0]->quantity_approved)->toBe(0)
        ->and($return->lines[0]->quantity_received)->toBe(0);
});

it('refuses a request for more than the caller says is returnable', function () {
    // Not this module enforcing somebody else's invariant — it is refusing to
    // write down a request the caller has itself just said is impossible.
    (new RequestReturn())->handle(returnInput([returnLine(quantity: 4, returnableQuantity: 3)]));
})->throws(ReturnQuantityExceeded::class, 'has 3 still returnable');

it('refuses a request for nothing at all', function () {
    // An empty workflow in every count of returns in progress, and nothing to
    // authorise. Refused rather than written.
    (new RequestReturn())->handle(returnInput([]));
})->throws(UnexpectedReturnLine::class);

it('refuses a zero or negative quantity, because every count here is append-only', function () {
    (new RequestReturn())->handle(returnInput([returnLine(quantity: 0)]));
})->throws(ReturnQuantityExceeded::class, 'must be positive');

it('writes nothing when a later line in the same request is bad', function () {
    // Validation runs over the whole request before a single row is written, so
    // one impossible line does not leave a half-made return behind.
    try {
        (new RequestReturn())->handle(returnInput([
            returnLine(orderLineId: GHOST_LINE, quantity: 1, returnableQuantity: 5),
            returnLine(orderLineId: GHOST_LINE_TWO, quantity: 9, returnableQuantity: 2),
        ]));
    } catch (ReturnQuantityExceeded) {
        // Expected.
    }

    expect(ReturnRequest::query()->count())->toBe(0);
});

it('publishes a past-tense event about an intention, carrying no free text', function () {
    Event::fake([ReturnRequested::class]);

    $return = (new RequestReturn())->handle(returnInput([
        returnLine(note: 'It smells of smoke and my neighbour Jane saw the courier drop it'),
    ]));

    Event::assertDispatched(ReturnRequested::class, function (ReturnRequested $event) use ($return): bool {
        expect($event->return)->toBeInstanceOf(ReturnData::class)
            ->and($event->return->id)->toBe($return->id)
            ->and($event->return->status)->toBe(ReturnStatus::Requested);

        // **The containment rule.** The reason is a slug and travels; the
        // shopper's sentence does not, in any form, anywhere on the value.
        expect(json_encode($event->return))->not->toContain('smoke')
            ->and(json_encode($event->return))->not->toContain('Jane')
            ->and(json_encode($event->return))->toContain('faulty');

        return true;
    });
});

it('keeps the shopper s own words on the model, behind the policy, and nowhere else', function () {
    $note = 'The seam split after one wash and it smells of smoke';
    $return = aReturn([returnLine(note: $note)]);

    // On the model, which is where a staff surface reads it — that is the one
    // place in an application where reading a shopper's sentence is the job.
    expect($return->lines[0]->note)->toBe($note);

    // Not in the published read model.
    expect(json_encode(ReturnData::from($return->load(['lines', 'refunds']))))->not->toContain('seam');
});

it('authorises less than was asked for, and keeps both numbers', function () {
    $return = aReturn([returnLine(quantity: 5, returnableQuantity: 5)]);

    (new ApproveReturn())->handle($return, [GHOST_LINE => 3], actorId: 9000001, reason: 'policy-limit');

    $line = $return->fresh()->lines[0];

    expect($return->fresh()->status)->toBe(ReturnStatus::Approved)
        // Both facts survive. Rewriting the request to match the approval would
        // lose the thing an argument three months later is about.
        ->and($line->quantity_requested)->toBe(5)
        ->and($line->quantity_approved)->toBe(3);
});

it('authorises everything requested when the merchant says nothing', function () {
    $return = aReturn([returnLine(quantity: 4, returnableQuantity: 4)]);

    (new ApproveReturn())->handle($return);

    expect($return->fresh()->lines[0]->quantity_approved)->toBe(4);
});

it('refuses to authorise more than was asked for', function () {
    // Authorising more invents a request the shopper never made; the extra units
    // then arrive, get received, and turn into a refund nobody agreed to.
    $return = aReturn([returnLine(quantity: 2, returnableQuantity: 9)]);

    (new ApproveReturn())->handle($return, [GHOST_LINE => 3]);
})->throws(ReturnQuantityExceeded::class, 'never more');

it('refuses to authorise a line the return does not name', function () {
    $return = aReturn([returnLine(orderLineId: GHOST_LINE)]);

    (new ApproveReturn())->handle($return, [GHOST_LINE_TWO => 1]);
})->throws(UnexpectedReturnLine::class);

it('leaves the quantities untouched when the approval itself is illegal', function () {
    $return = aReturn([returnLine(quantity: 2, returnableQuantity: 2)]);
    (new ApproveReturn())->handle($return);

    // Already approved. The transition is checked *before* any quantity is
    // written, so a retried approval cannot rewrite the numbers on its way to
    // throwing.
    try {
        (new ApproveReturn())->handle($return, [GHOST_LINE => 1]);
        $this->fail('A second approval was allowed.');
    } catch (IllegalReturnTransition) {
        // Expected.
    }

    expect($return->fresh()->lines[0]->quantity_approved)->toBe(2);
});

it('records the window the merchant gave, without inventing one', function () {
    $return = aReturn();
    (new ApproveReturn())->handle($return, goodsDueBy: now()->addDays(14));

    expect($return->fresh()->goods_due_by)->not->toBeNull();

    // And a merchant who names no window gets none — a package that defaulted
    // this to thirty days would have written somebody's returns policy into a
    // constant.
    $other = aReturn();
    (new ApproveReturn())->handle($other);

    expect($other->fresh()->goods_due_by)->toBeNull();
});

it('mints a public reference that is not an incrementing id', function () {
    $numbers = collect(range(1, 5))->map(fn (): string => aReturn()->number);

    expect($numbers->unique())->toHaveCount(5)
        ->and($numbers->first())->toMatch('/^RMA-[0-9A-F]{12}$/');
});

it('separates the merchant s fault from a change of mind, without reading prose', function () {
    expect(ReturnReason::Faulty->isMerchantFault())->toBeTrue()
        ->and(ReturnReason::WrongItemSent->isMerchantFault())->toBeTrue()
        ->and(ReturnReason::DamagedInTransit->isMerchantFault())->toBeTrue()
        ->and(ReturnReason::NotAsDescribed->isMerchantFault())->toBeTrue()
        ->and(ReturnReason::ArrivedLate->isMerchantFault())->toBeTrue()
        ->and(ReturnReason::NoLongerWanted->isMerchantFault())->toBeFalse()
        ->and(ReturnReason::BetterPriceElsewhere->isMerchantFault())->toBeFalse();
});
