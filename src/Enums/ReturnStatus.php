<?php

namespace Liberu\Ecommerce\Returns\Enums;

/**
 * **A return is a workflow, not a flag**, and it has more than one clock.
 *
 *     requested ──▶ approved ──▶ received ──▶ inspected ──▶ resolved
 *         │             │
 *         ├──▶ refused  └──▶ expired
 *         └──▶ cancelled ◀───┘
 *
 * Seven states, because a return answers seven different questions and a single
 * boolean answers none of them: *did the shopper ask*, *did the merchant agree*,
 * *did the goods turn up*, *what condition are they in*, and *is it finished*.
 * Each is a separate clock and each can stop.
 *
 * The transition table lives here and nowhere else, and `Actions\TransitionReturn`
 * refuses anything it does not name — an illegal move throws and writes nothing.
 *
 * **What the states mean:**
 *
 * - `requested` — a shopper asked to send something back. Nothing is authorised
 *   and no goods have moved.
 * - `approved` — the merchant authorised it, for a quantity that may be smaller
 *   than the one asked for. This is the state a label is issued in, and the only
 *   state in which goods may arrive.
 * - `refused` — the merchant declined. Terminal. Refusal happens *before*
 *   authorisation; once a label is out, a bad item is an inspection outcome and
 *   not a refusal.
 * - `cancelled` — called off before the goods arrived, by either side. Terminal.
 * - `expired` — authorised, and the goods never came. Terminal, and the reason
 *   this enum has seven states rather than six: without it, an approved return
 *   whose parcel never arrives sits open forever and every count of "returns in
 *   progress" is wrong. See `Queries\ReturnQuery::awaitingGoodsSince()`.
 * - `received` — something arrived. Partial and repeatable: a second parcel is
 *   another receipt, not another state.
 * - `inspected` — every received unit has a disposition. This is what a restock
 *   decision is made from, and the restock itself belongs to Inventory Ledger.
 * - `resolved` — finished. Terminal.
 *
 * **`approved → resolved` is the illegal move that matters most.** It is the
 * symmetric partner of the rule Orders enforces on its own side. Orders refuses
 * to record a return of goods that never shipped; this refuses to finish a return
 * of goods that never came back. A return that resolves without a receipt is a
 * refund for a parcel nobody has, and it is precisely the move an impatient
 * support queue would like to make.
 *
 * **Progress within a state is not a state.** A return of five units can be two
 * received, one rejected on inspection and two still in a van, and one column
 * cannot say that — the quantities on `Models\ReturnLine` do.
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Refused = 'refused';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Received = 'received';
    case Inspected = 'inspected';
    case Resolved = 'resolved';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Refused, self::Cancelled],
            self::Approved => [self::Received, self::Cancelled, self::Expired],
            self::Received => [self::Inspected],
            self::Inspected => [self::Resolved],
            self::Refused, self::Cancelled, self::Expired, self::Resolved => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether goods arriving now belong to this return.
     *
     * Only `approved` and `received`. Goods arriving against a `requested`
     * return were never authorised, and goods arriving against an `expired` one
     * arrived after the merchant closed it — both are refused rather than
     * quietly adopted, because adopting them writes a receipt nobody agreed to.
     */
    public function acceptsGoods(): bool
    {
        return $this === self::Approved || $this === self::Received;
    }

    /**
     * Whether money may be recorded as having gone back.
     *
     * **The symmetric invariant, as a state.** Something has to have physically
     * come back first. This module does not decide *when* a merchant refunds —
     * on receipt or after inspection is policy, and both are honest — but it
     * refuses to record a refund against a return that has taken delivery of
     * nothing at all.
     */
    public function allowsRefund(): bool
    {
        return $this === self::Received || $this === self::Inspected || $this === self::Resolved;
    }

    /** The column stamped when a return arrives here, or null for `requested`. */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Requested => null,
            self::Approved => 'approved_at',
            self::Refused => 'refused_at',
            self::Cancelled => 'cancelled_at',
            self::Expired => 'expired_at',
            self::Received => 'received_at',
            self::Inspected => 'inspected_at',
            self::Resolved => 'resolved_at',
        };
    }
}
