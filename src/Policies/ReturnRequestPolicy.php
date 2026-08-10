<?php

namespace Liberu\Ecommerce\Returns\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * Who, among staff, may act on somebody else's return.
 *
 * Registered rather than left absent, because **a model with no policy is exposed
 * and not safe** — Laravel's unanswered gate case is permissive, and that has
 * produced a live leak three times in this fleet. A return holds a shopper's
 * account of what went wrong, in their own words, attached to an order they
 * placed. It is exactly the model you do not want defaulting open.
 *
 * Every ability is answered **by name**, including the ones that are always
 * false. A policy that is present but silent on an ability is the sharper version
 * of no policy at all: a panel's authorization helper returns *allow* when a
 * present policy has no method for the ability it asked about, and the file
 * existing makes it look like a control.
 *
 * Tenancy is read off the actor, so it answers the same way in a console command,
 * a queued job and an API request. A return belonging to nobody (`team_id` null)
 * is nobody's to act on: visible, so an orphan can be found and fixed, and not
 * writable, so it cannot be quietly claimed.
 *
 * **`create` is permanently false, and it is a domain rule rather than a
 * caution.** A return is *requested*, from a `Data\ReturnRequestInput` carrying
 * the returnable quantity the caller read from whoever owns the order. A blank
 * create form cannot supply that, and a return with no lines is not a return —
 * it is an empty workflow in every count of returns in progress. `update` and
 * `delete` are false for the same shape of reason: the quantities are the record,
 * the history and the refunds hang off the row, and every legitimate change to a
 * return is one of the seven named abilities below.
 *
 * The seven are separate abilities because they are different-sized mistakes.
 * Refusing a return annoys a shopper; recording a refund moves money. A
 * deployment that wants a second pair of eyes on the second one needs somewhere
 * to say so without a breaking change.
 */
class ReturnRequestPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return $this->teamOf($actor) !== null;
    }

    public function view(Authenticatable $actor, ReturnRequest $return): bool
    {
        return $this->ownsIt($actor, $return);
    }

    /** Returns are requested, never created from a blank form. See the class docblock. */
    public function create(Authenticatable $actor): bool
    {
        return false;
    }

    /** The quantities are the record. Every legitimate change is a named ability. */
    public function update(Authenticatable $actor, ReturnRequest $return): bool
    {
        return false;
    }

    /** History and refunds hang off this row. */
    public function delete(Authenticatable $actor, ReturnRequest $return): bool
    {
        return false;
    }

    public function restore(Authenticatable $actor, ReturnRequest $return): bool
    {
        return false;
    }

    public function forceDelete(Authenticatable $actor, ReturnRequest $return): bool
    {
        return false;
    }

    /** Authorise the return, for a quantity of the merchant's choosing. */
    public function approve(Authenticatable $actor, ReturnRequest $return): bool
    {
        return $this->ownsIt($actor, $return) && $return->status->canTransitionTo(ReturnStatus::Approved);
    }

    /** Decline it. Only before authorisation — after that a bad item is an inspection outcome. */
    public function refuse(Authenticatable $actor, ReturnRequest $return): bool
    {
        return $this->ownsIt($actor, $return) && $return->status->canTransitionTo(ReturnStatus::Refused);
    }

    public function cancel(Authenticatable $actor, ReturnRequest $return): bool
    {
        return $this->ownsIt($actor, $return) && $return->status->canTransitionTo(ReturnStatus::Cancelled);
    }

    /**
     * Take delivery of goods against it.
     *
     * Gated on the domain's own answer as well as on tenancy: a return that is
     * not open to goods cannot be receipted by anyone, however senior. A staff
     * member with the ability still cannot get round the boundary.
     */
    public function receive(Authenticatable $actor, ReturnRequest $return): bool
    {
        return $this->ownsIt($actor, $return) && $return->acceptsGoods();
    }

    public function inspect(Authenticatable $actor, ReturnRequest $return): bool
    {
        return $this->ownsIt($actor, $return) && $return->status->canTransitionTo(ReturnStatus::Inspected);
    }

    /**
     * Record that money went back.
     *
     * The heaviest of the seven, and the one whose domain guard is the symmetric
     * invariant: goods have to have come back first. `RecordRefund` refuses it
     * too — this is the gate saying the same thing to a surface before the
     * button is drawn.
     */
    public function refund(Authenticatable $actor, ReturnRequest $return): bool
    {
        return $this->ownsIt($actor, $return) && $return->status->allowsRefund();
    }

    public function resolve(Authenticatable $actor, ReturnRequest $return): bool
    {
        return $this->ownsIt($actor, $return) && $return->status->canTransitionTo(ReturnStatus::Resolved);
    }

    private function ownsIt(Authenticatable $actor, ReturnRequest $return): bool
    {
        $team = $this->teamOf($actor);

        // Bound comparison, never `null === null`. An orphan return matching an
        // actor with no team is how a leak is written as a tautology.
        return $team !== null && $return->team_id === $team;
    }

    private function teamOf(Authenticatable $actor): ?int
    {
        $team = $actor->getAttribute('current_team_id');

        return is_numeric($team) ? (int) $team : null;
    }
}
