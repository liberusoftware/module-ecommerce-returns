<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Returns\Actions\RecordRefund;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Models\Refund;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Liberu\PackageTestbench\TestUser;

/**
 * An actor working in a team, the way a team switcher leaves them.
 *
 * Team ids here start at 9,000,00x so they cannot collide with anything
 * `TestUser::factory()` mints. A fixture id that collides makes an authorization
 * test pass for the wrong reason — a "stranger's" record quietly becomes the
 * actor's own — and that failure mode is invisible in a green suite.
 */
function actorInTeam(?int $teamId): TestUser
{
    $user = TestUser::factory()->create();
    $user->current_team_id = $teamId;

    return $user;
}

it('registers a policy on both models, because an unpolicied model is exposed and not safe', function () {
    // Laravel's unanswered gate case is permissive, and this fleet has shipped
    // that leak three times. A return holds a shopper's account of what went
    // wrong in their own words; a refund holds an amount an accountant
    // reconciles against a bank.
    expect(Gate::getPolicyFor(ReturnRequest::class))->not->toBeNull()
        ->and(Gate::getPolicyFor(Refund::class))->not->toBeNull();
});

it('lets a merchant read and work on their own return', function () {
    $actor = actorInTeam(9000001);
    $return = ReturnRequest::factory()->ownedBy(9000001)->create();

    expect($actor->can('viewAny', ReturnRequest::class))->toBeTrue()
        ->and($actor->can('view', $return))->toBeTrue()
        ->and($actor->can('approve', $return))->toBeTrue()
        ->and($actor->can('refuse', $return))->toBeTrue()
        ->and($actor->can('cancel', $return))->toBeTrue();
});

it('refuses another merchant s return outright', function () {
    $actor = actorInTeam(9000001);
    $theirs = ReturnRequest::factory()->ownedBy(9000002)->create();

    foreach (['view', 'approve', 'refuse', 'cancel', 'receive', 'inspect', 'refund', 'resolve'] as $ability) {
        expect($actor->can($ability, $theirs))->toBeFalse();
    }
});

it('refuses a return belonging to nobody, so an orphan cannot be quietly claimed', function () {
    $actor = actorInTeam(9000001);
    $orphan = ReturnRequest::factory()->create(['team_id' => null]);

    expect($actor->can('view', $orphan))->toBeFalse()
        ->and($actor->can('approve', $orphan))->toBeFalse();
});

it('refuses an actor with no team anything at all', function () {
    $actor = actorInTeam(null);
    $orphan = ReturnRequest::factory()->create(['team_id' => null]);
    $owned = ReturnRequest::factory()->ownedBy(9000001)->create();

    // The comparison is bound, never `null === null`. An orphan matching an actor
    // with no team is how a leak gets written as a tautology.
    expect($actor->can('viewAny', ReturnRequest::class))->toBeFalse()
        ->and($actor->can('view', $orphan))->toBeFalse()
        ->and($actor->can('view', $owned))->toBeFalse();
});

it('answers every ability by name, including the ones that are always false', function (string $ability) {
    // A policy that is *present* but silent on an ability is the sharper version
    // of no policy at all: a panel's authorization helper returns allow when a
    // present policy has no method for the ability it asked about.
    expect(method_exists(Gate::getPolicyFor(ReturnRequest::class), $ability))->toBeTrue();
})->with([
    'viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete',
    'approve', 'refuse', 'cancel', 'receive', 'inspect', 'refund', 'resolve',
]);

it('refuses creating, editing and deleting a return to everybody, for a domain reason each', function (string $ability) {
    $actor = actorInTeam(9000001);
    $own = ReturnRequest::factory()->ownedBy(9000001)->create();

    expect($ability === 'create' ? $actor->can('create', ReturnRequest::class) : $actor->can($ability, $own))->toBeFalse();
})->with(['create', 'update', 'delete', 'restore', 'forceDelete']);

it('closes an ability the moment the domain closes it', function () {
    $actor = actorInTeam(9000001);
    $resolved = ReturnRequest::factory()->ownedBy(9000001)->status(ReturnStatus::Resolved)->create();

    // Gated on the domain's own answer as well as on tenancy, so a staff member
    // with the ability still cannot get round the state machine.
    foreach (['approve', 'refuse', 'cancel', 'receive', 'inspect', 'resolve'] as $ability) {
        expect($actor->can($ability, $resolved))->toBeFalse();
    }
});

it('opens receiving only while the return is open to goods', function () {
    $actor = actorInTeam(9000001);

    $approved = ReturnRequest::factory()->ownedBy(9000001)->approved()->create();
    $expired = ReturnRequest::factory()->ownedBy(9000001)->status(ReturnStatus::Expired)->create();

    expect($actor->can('receive', $approved))->toBeTrue()
        ->and($actor->can('receive', $expired))->toBeFalse();
});

it('opens recording a refund only once goods have come back', function () {
    $actor = actorInTeam(9000001);

    $approved = ReturnRequest::factory()->ownedBy(9000001)->approved()->create();
    $received = ReturnRequest::factory()->ownedBy(9000001)->received()->create();

    expect($actor->can('refund', $approved))->toBeFalse()
        ->and($actor->can('refund', $received))->toBeTrue();
});

it('lets a merchant read their own refund and write none at all', function () {
    $actor = actorInTeam(9000001);

    $return = aReceivedReturn(1);
    $return->forceFill(['team_id' => 9000001])->save();
    $refund = (new RecordRefund())->handle($return->fresh(), 1999, reference: 'credit-note-1');

    expect($actor->can('viewAny', Refund::class))->toBeTrue()
        ->and($actor->can('view', $refund))->toBeTrue()
        // Money already moved, or there is no row. A create form here would mint
        // a record for money nobody sent, and an edit form would let somebody
        // change an amount after the fact.
        ->and($actor->can('create', Refund::class))->toBeFalse()
        ->and($actor->can('update', $refund))->toBeFalse()
        ->and($actor->can('delete', $refund))->toBeFalse()
        ->and($actor->can('restore', $refund))->toBeFalse()
        ->and($actor->can('forceDelete', $refund))->toBeFalse();
});

it('refuses another merchant s refund, reading tenancy through the return', function () {
    $stranger = actorInTeam(9000002);

    $return = aReceivedReturn(1);
    $return->forceFill(['team_id' => 9000001])->save();
    $refund = (new RecordRefund())->handle($return->fresh(), 1999);

    expect($stranger->can('view', $refund))->toBeFalse();
});
