<?php

declare(strict_types=1);

use Liberu\Ecommerce\Returns\Actions\TransitionReturn;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Exceptions\IllegalReturnTransition;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Liberu\Ecommerce\Returns\Models\ReturnStatusChange;

/**
 * **A return is a workflow, not a flag.**
 *
 *     requested ──▶ approved ──▶ received ──▶ inspected ──▶ resolved
 *         │             │
 *         ├──▶ refused  └──▶ expired
 *         └──▶ cancelled
 *
 * Every legal move has a case here and every illegal one has a case here,
 * including all eight self-transitions — a no-op is not harmless, it files a
 * history row against a move nobody made and re-stamps a timestamp, so the record
 * of when a merchant authorised a return becomes the record of when somebody last
 * double-clicked.
 */
it('walks the whole workflow, stamping a clock at every step', function () {
    $return = aReturn();
    $transition = new TransitionReturn();

    $transition->handle($return, ReturnStatus::Approved, actorId: 9000001, reason: 'within-window');
    expect($return->fresh()->status)->toBe(ReturnStatus::Approved)
        ->and($return->fresh()->approved_at)->not->toBeNull();

    $transition->handle($return, ReturnStatus::Received);
    $transition->handle($return, ReturnStatus::Inspected);
    $transition->handle($return, ReturnStatus::Resolved);

    $fresh = $return->fresh();

    expect($fresh->status)->toBe(ReturnStatus::Resolved)
        ->and($fresh->received_at)->not->toBeNull()
        ->and($fresh->inspected_at)->not->toBeNull()
        ->and($fresh->resolved_at)->not->toBeNull()
        ->and($fresh->status->isTerminal())->toBeTrue();
});

it('records both ends of every move it made, and nothing it refused', function () {
    $return = aReturn();
    $transition = new TransitionReturn();

    $transition->handle($return, ReturnStatus::Approved, actorId: 9000001, reason: 'within-window');
    $transition->handle($return, ReturnStatus::Expired, reason: 'goods-never-arrived');

    try {
        $transition->handle($return, ReturnStatus::Received);
    } catch (IllegalReturnTransition) {
        // Deliberately swallowed: the point is what did *not* get written.
    }

    $history = ReturnStatusChange::query()->where('return_request_id', $return->id)->orderBy('id')->get();

    expect($history)->toHaveCount(3)
        // The request itself came from nowhere.
        ->and($history[0]->from_status)->toBeNull()
        ->and($history[0]->to_status)->toBe(ReturnStatus::Requested)
        ->and($history[1]->from_status)->toBe(ReturnStatus::Requested)
        ->and($history[1]->to_status)->toBe(ReturnStatus::Approved)
        ->and($history[1]->actor_id)->toBe(9000001)
        ->and($history[1]->reason)->toBe('within-window')
        ->and($history[2]->to_status)->toBe(ReturnStatus::Expired);
});

it('allows exactly the moves the transition table names', function (ReturnStatus $from, ReturnStatus $to) {
    $return = ReturnRequest::factory()->status($from)->create();

    (new TransitionReturn())->handle($return, $to);

    expect($return->fresh()->status)->toBe($to);
})->with([
    'requested to approved' => [ReturnStatus::Requested, ReturnStatus::Approved],
    'requested to refused' => [ReturnStatus::Requested, ReturnStatus::Refused],
    'requested to cancelled' => [ReturnStatus::Requested, ReturnStatus::Cancelled],
    'approved to received' => [ReturnStatus::Approved, ReturnStatus::Received],
    'approved to cancelled' => [ReturnStatus::Approved, ReturnStatus::Cancelled],
    'approved to expired' => [ReturnStatus::Approved, ReturnStatus::Expired],
    'received to inspected' => [ReturnStatus::Received, ReturnStatus::Inspected],
    'inspected to resolved' => [ReturnStatus::Inspected, ReturnStatus::Resolved],
]);

it('refuses the moves it does not, and writes nothing at all when it does', function (ReturnStatus $from, ReturnStatus $to) {
    $return = ReturnRequest::factory()->status($from)->create();
    $before = $return->fresh()->toArray();

    try {
        (new TransitionReturn())->handle($return, $to);
        $this->fail("{$from->value} → {$to->value} was allowed and should not have been.");
    } catch (IllegalReturnTransition) {
        // Expected.
    }

    // Not the status, not the timestamp, not a history row.
    expect($return->fresh()->toArray())->toBe($before)
        ->and(ReturnStatusChange::query()->where('return_request_id', $return->id)->count())->toBe(0);
})->with([
    // **The one that matters most.** Finishing a return whose goods never came
    // back is a refund for a parcel nobody has, and it is the symmetric partner
    // of the rule the module that owns order lines enforces on its own side.
    'approved straight to resolved, skipping the goods' => [ReturnStatus::Approved, ReturnStatus::Resolved],
    'requested straight to received, skipping authorisation' => [ReturnStatus::Requested, ReturnStatus::Received],
    'requested straight to inspected' => [ReturnStatus::Requested, ReturnStatus::Inspected],
    'requested straight to resolved' => [ReturnStatus::Requested, ReturnStatus::Resolved],
    'approved straight to inspected' => [ReturnStatus::Approved, ReturnStatus::Inspected],
    'approved back to refused, once a label is already out' => [ReturnStatus::Approved, ReturnStatus::Refused],
    'received back to approved' => [ReturnStatus::Received, ReturnStatus::Approved],
    'received cancelled, with the goods already here' => [ReturnStatus::Received, ReturnStatus::Cancelled],
    'received straight to resolved, skipping inspection' => [ReturnStatus::Received, ReturnStatus::Resolved],
    'inspected back to received' => [ReturnStatus::Inspected, ReturnStatus::Received],
    'refused reopened as approved' => [ReturnStatus::Refused, ReturnStatus::Approved],
    'cancelled reopened as approved' => [ReturnStatus::Cancelled, ReturnStatus::Approved],
    'expired taking delivery after the window' => [ReturnStatus::Expired, ReturnStatus::Received],
    'resolved reopened' => [ReturnStatus::Resolved, ReturnStatus::Received],
]);

it('refuses every self-transition, because a retried click is not a second decision', function (ReturnStatus $status) {
    $return = ReturnRequest::factory()->status($status)->create();
    $stamped = $return->fresh()->getAttribute($status->timestampColumn() ?? 'requested_at');

    try {
        (new TransitionReturn())->handle($return, $status);
        $this->fail("A self-transition on {$status->value} was allowed.");
    } catch (IllegalReturnTransition $exception) {
        expect($exception->getMessage())->toContain('A no-op is not a transition');
    }

    // The timestamp is the thing actually at risk: re-stamping it turns "when the
    // merchant approved this" into "when somebody last pressed the button".
    expect($return->fresh()->getAttribute($status->timestampColumn() ?? 'requested_at'))->toEqual($stamped)
        ->and(ReturnStatusChange::query()->where('return_request_id', $return->id)->count())->toBe(0);
})->with(fn () => ReturnStatus::cases());

it('tells a caller which moves are open, rather than making them guess', function () {
    expect(ReturnStatus::Requested->allowedTransitions())
        ->toBe([ReturnStatus::Approved, ReturnStatus::Refused, ReturnStatus::Cancelled])
        ->and(ReturnStatus::Resolved->allowedTransitions())->toBe([])
        ->and(ReturnStatus::Requested->canTransitionTo(ReturnStatus::Approved))->toBeTrue()
        ->and(ReturnStatus::Requested->canTransitionTo(ReturnStatus::Received))->toBeFalse();
});

it('knows which states take goods and which allow money to be recorded', function () {
    // The two predicates the actions are built on, asserted directly so a change
    // to either is a deliberate one.
    $accepts = array_values(array_filter(ReturnStatus::cases(), fn (ReturnStatus $s): bool => $s->acceptsGoods()));
    $refundable = array_values(array_filter(ReturnStatus::cases(), fn (ReturnStatus $s): bool => $s->allowsRefund()));

    expect($accepts)->toBe([ReturnStatus::Approved, ReturnStatus::Received])
        ->and($refundable)->toBe([ReturnStatus::Received, ReturnStatus::Inspected, ReturnStatus::Resolved]);
});

it('reports every terminal state as terminal', function () {
    $terminal = array_values(array_filter(ReturnStatus::cases(), fn (ReturnStatus $s): bool => $s->isTerminal()));

    expect($terminal)->toBe([
        ReturnStatus::Refused,
        ReturnStatus::Cancelled,
        ReturnStatus::Expired,
        ReturnStatus::Resolved,
    ]);
});
