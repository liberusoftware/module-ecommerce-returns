<?php

namespace Liberu\Ecommerce\Returns\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Ecommerce\Returns\Models\Refund;

/**
 * Its own policy, because money is the model most worth having a gate say no
 * about.
 *
 * A refund row could have been left to inherit whatever answer its parent got. It
 * is not, for two reasons. A refund is reachable directly — a relation manager, a
 * list of everything refunded this month, a nested API route — and each of those
 * asks the gate about *this* model, not about its return. And an unpolicied model
 * is exposed rather than safe, which is the finding this fleet has now paid for
 * three times; a table of amounts is the wrong place to test it a fourth.
 *
 * **Everything that writes is permanently false.** A refund row means money
 * already moved, recorded through `Actions\RecordRefund` with a reference from
 * whoever moved it. A create form here would let a surface mint a refund record
 * for money nobody sent, and an edit form would let somebody change the amount
 * after the fact — in the one table an accountant reconciles against a bank.
 * Recording is an ability on the *return* (`refund`), where the domain guard that
 * goods came back first can be applied.
 *
 * Tenancy is read through the parent return, because that is where `team_id`
 * lives. A refund whose return belongs to nobody is nobody's to read.
 */
class RefundPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return $this->teamOf($actor) !== null;
    }

    public function view(Authenticatable $actor, Refund $refund): bool
    {
        $team = $this->teamOf($actor);

        return $team !== null && $refund->returnRequest->team_id === $team;
    }

    /** Money already moved, or there is no row. Recording is an ability on the return. */
    public function create(Authenticatable $actor): bool
    {
        return false;
    }

    /** An amount an accountant reconciles against a bank is not an editable field. */
    public function update(Authenticatable $actor, Refund $refund): bool
    {
        return false;
    }

    public function delete(Authenticatable $actor, Refund $refund): bool
    {
        return false;
    }

    public function restore(Authenticatable $actor, Refund $refund): bool
    {
        return false;
    }

    public function forceDelete(Authenticatable $actor, Refund $refund): bool
    {
        return false;
    }

    private function teamOf(Authenticatable $actor): ?int
    {
        $team = $actor->getAttribute('current_team_id');

        return is_numeric($team) ? (int) $team : null;
    }
}
