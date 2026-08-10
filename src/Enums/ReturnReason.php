<?php

namespace Liberu\Ecommerce\Returns\Enums;

/**
 * Why something is coming back — **a closed set of slugs, not a sentence.**
 *
 * A reason is evidence: it is what a merchant groups by to find the batch that
 * is faulty, the description that is wrong, and the courier that keeps arriving
 * late. Free text answers none of those questions, because nobody types the same
 * sentence twice.
 *
 * It is also the field a shopper is most likely to type a personal detail into.
 * Checkout's abandonment reason is a five-slug select and Orders' cancellation
 * reason is a 64-character slug, both for that reason, and both because the value
 * is copied into a domain event. This one goes the same way, and the enum is what
 * makes a surface's control a select rather than a text box.
 *
 * A shopper who genuinely has to describe a fault writes it in
 * `ReturnLine::$note`, whose containment rule is on that model: it stays behind
 * the policy, it is never in a read model, an event or a log line, and a test
 * asserts it.
 *
 * `faultRequiresEvidence()` is the one behaviour attached to the set. A merchant's
 * own return window normally does not apply to a faulty item, and that is a
 * distinction an eligibility rule has to be able to make without parsing prose.
 */
enum ReturnReason: string
{
    case Faulty = 'faulty';
    case DamagedInTransit = 'damaged_in_transit';
    case WrongItemSent = 'wrong_item_sent';
    case NotAsDescribed = 'not_as_described';
    case ArrivedLate = 'arrived_late';
    case NoLongerWanted = 'no_longer_wanted';
    case BetterPriceElsewhere = 'better_price_elsewhere';

    /**
     * Whether the merchant is at fault, on the shopper's account of it.
     *
     * Not a judgement and not a refund rule — an inspection may disagree, and
     * what a merchant does about it is their policy. It is here so a surface can
     * ask for a photograph, and so a report can separate "our fault" from
     * "changed their mind" without reading a paragraph.
     */
    public function isMerchantFault(): bool
    {
        return match ($this) {
            self::Faulty, self::DamagedInTransit, self::WrongItemSent, self::NotAsDescribed, self::ArrivedLate => true,
            self::NoLongerWanted, self::BetterPriceElsewhere => false,
        };
    }
}
