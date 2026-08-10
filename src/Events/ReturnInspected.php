<?php

namespace Liberu\Ecommerce\Returns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Liberu\Ecommerce\Returns\Data\LineDisposition;
use Liberu\Ecommerce\Returns\Data\ReturnData;

/**
 * Somebody looked at what came back and said what condition it is in.
 *
 * **This is where restocking is published rather than performed.** A returned
 * unit is not automatically saleable, and putting it back on a shelf is a stock
 * movement in the module that owns stock ([#862](https://github.com/liberusoftware/ecommerce-laravel/issues/862),
 * already shipped) — not a side effect of a returns workflow. This package writes
 * no stock, holds no stock, and requires nothing that does.
 *
 * So the disposition is published and the **host** decides: a warehouse that
 * quarantines returns for a week and one that restocks at the desk are both
 * correct, and neither is this package's to assume. `docs/adoption.md` carries
 * the listener.
 */
final class ReturnInspected
{
    use Dispatchable;

    /**
     * @param  list<LineDisposition>  $dispositions
     */
    public function __construct(
        public readonly ReturnData $return,
        public readonly array $dispositions,
        public readonly ?int $actorId = null,
    ) {}

    /** What may go back on a shelf, if the host's policy says so. */
    public function restockableQuantity(): int
    {
        return array_sum(array_map(fn (LineDisposition $disposition): int => $disposition->restockable, $this->dispositions));
    }

    /** What may not. */
    public function rejectedQuantity(): int
    {
        return array_sum(array_map(fn (LineDisposition $disposition): int => $disposition->rejected, $this->dispositions));
    }
}
