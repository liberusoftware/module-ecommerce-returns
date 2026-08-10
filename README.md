# Ecommerce: Returns

> This package is the authoritative, provider-neutral implementation of Returns. It owns domain behavior and data; optional API, Filament, Livewire, React, Vue, and Nuxt packages translate its public contracts for their surfaces.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-returns?sort=semver)](https://github.com/liberusoftware/module-ecommerce-returns/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-returns/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-returns/actions/workflows/tests.yml)

The return: a workflow with goods in it. A state machine with five clocks that
refuses an illegal move, requested and received kept as separate facts that are
refused rather than clamped when they disagree, and a refund modelled as an
amount and a reference because the money belongs to whoever owns the tender.

## Features

- Fully compatible with **Laravel 13**, **PHP 8.5**, and **Pest 5**.
- Built following the domain-driven design guidelines of the Liberu architecture.
- Reusable, presenting a clean public contract and boundaries.
- Adheres to the strict database, security, and authorization standards of Liberu.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)

## Quick start

```bash
composer require liberusoftware/ecommerce-returns
```

Installing boots nothing. The module ships no `extra.laravel.providers`, so
`ModuleManagerServiceProvider` is the only thing that registers it, and only when
the deployment names it:

```dotenv
MODULES_ENABLED=ecommerce-returns
```

```php
use Liberu\Ecommerce\Returns\Actions\{RequestReturn, ApproveReturn, ReceiveGoods, InspectReturn, RecordRefund, TransitionReturn};
use Liberu\Ecommerce\Returns\Data\{ReturnRequestInput, ReturnLineInput, LineReceipt, LineDisposition};
use Liberu\Ecommerce\Returns\Enums\{ReturnReason, ReturnStatus, RefundKind};

// 1. A shopper asks. `returnableQuantity` is an INPUT — see below.
$return = (new RequestReturn())->handle(new ReturnRequestInput(
    orderId: 4711,
    lines: [new ReturnLineInput(
        orderLineId: 88231,
        quantity: 2,
        reason: ReturnReason::Faulty,
        returnableQuantity: 3,
        name: 'Merino Crew',
        sku: 'MC-1',
    )],
    currency: 'GBP',
    teamId: 7,
));

// 2. The merchant authorises, for a quantity of their own choosing.
(new ApproveReturn())->handle($return, [88231 => 2], goodsDueBy: now()->addDays(14));

// 3. Goods arrive. Fewer is fine; more throws; something unrequested throws differently.
(new ReceiveGoods())->handle($return, [new LineReceipt(88231, 2)]);

// 4. Somebody looks at them. This publishes a restock decision; it does not restock.
(new InspectReturn())->handle($return, [new LineDisposition(88231, restockable: 1, rejected: 1)]);

// 5. Money that already went back is written down, with the reference of whoever moved it.
(new RecordRefund())->handle($return, amountMinor: 1999, kind: RefundKind::Tender, reference: 'credit-note-8812');

(new TransitionReturn())->handle($return, ReturnStatus::Resolved);
```

## The two listeners the host must write

**This package imports nothing.** It requires no sibling
`liberusoftware/ecommerce-*` package and its `src/` names no commerce namespace
but its own — a boundary test asserts that as a set, so a sibling appearing in so
much as a docblock fails the build. Its whole suite runs with no orders module
and no fulfillment module installed, over order line ids nothing in the database
has heard of, under a test named for the fact.

So the two places this module's work has to become somebody else's are **events
the host subscribes to**. The host is the only party entitled to know both
modules exist.

### 1. Goods came back → raise the returned counter on the order line

```php
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Orders\Actions\AccountForLine;
use Liberu\Ecommerce\Orders\Enums\LineAccount;
use Liberu\Ecommerce\Orders\Models\OrderLine;
use Liberu\Ecommerce\Returns\Events\ReturnGoodsReceived;

Event::listen(ReturnGoodsReceived::class, function (ReturnGoodsReceived $event): void {
    foreach ($event->receipts as $receipt) {
        (new AccountForLine())->handle(
            OrderLine::query()->findOrFail($receipt->orderLineId),
            LineAccount::Returned,
            $receipt->quantity,
        );
    }
});
```

`$receipts` are **deltas, not totals**. A return that comes back in two parcels
dispatches this twice, with two and then three, never with two and then five —
because the counter on the far side is append-only too, and a total posted twice
is double the goods.

That listener is also where the two invariants meet. `AccountForLine` refuses
`returned > fulfilled`: nothing can come back that never went out. This module
refuses to resolve or refund a return that took delivery of nothing: nothing is
settled that never came back. Neither module can enforce the other's half,
because neither holds the other's counters.

### 2. Inspection said what is saleable → move the stock, if that is your policy

```php
use Liberu\Ecommerce\Returns\Events\ReturnInspected;

Event::listen(ReturnInspected::class, function (ReturnInspected $event): void {
    foreach ($event->dispositions as $disposition) {
        if ($disposition->restockable > 0) {
            // Whatever the inventory ledger's own action is called.
        }
    }
});
```

**A returned unit is not automatically saleable**, and putting it back on a shelf
is a stock movement in the module that owns stock — not a side effect of a
returns workflow. A warehouse that quarantines returns for a week and one that
restocks at the desk are both correct, and neither is this package's to assume.
So the disposition is published and the host decides.

## Eligibility is an input, the way tax is an input

Whether a unit may come back is `delivered − already returned`, and both of those
counters live in the module that owns order lines. This package cannot compute
it, must not guess it, and refuses to look it up.

So `ReturnLineInput::$returnableQuantity` is handed in. The caller reads it from
wherever order lines live, and `RequestReturn` refuses a request larger than it —
which is not this module enforcing somebody else's invariant, it is this module
refusing to write down a request the caller has itself just said is impossible.
The number is **stored** as well as checked, because three months later the
argument is about what the shopper was told they could send back on the day they
asked.

## A return is a workflow, not a flag

```
requested ──▶ approved ──▶ received ──▶ inspected ──▶ resolved
    │             │
    ├──▶ refused  └──▶ expired
    └──▶ cancelled
```

`Actions\TransitionReturn` is the only door — `status` and all eight state
timestamps are deliberately absent from `$fillable` — and an illegal move throws
`IllegalReturnTransition` and writes nothing: not the status, not the timestamp,
not a history row, not an event.

**Self-transitions are illegal too.** A no-op files a history row against a move
nobody made and re-stamps `approved_at`, so the record of when a merchant
authorised the return becomes the record of when somebody last double-clicked.

**`approved → resolved` is the move that matters most.** Finishing a return whose
goods never arrived is a refund for a parcel nobody has. A request can be made,
approved, and then the goods simply never come — that return **expires**, which is
why there are seven states rather than six, and `ReturnQuery::awaitingGoodsSince()`
is what finds them.

## Requested and received are different facts

Five quantities per line, because they answer five questions:

| | |
| --- | --- |
| `returnable_quantity` | what the caller was told was still returnable — evidence |
| `quantity_requested` | what the shopper asked to send back |
| `quantity_approved` | what the merchant authorised — may be less, never more |
| `quantity_received` | what physically arrived |
| `quantity_restockable` / `quantity_rejected` | what inspection concluded |

- **Fewer arrive than approved** — allowed, and ordinary. `shortfallQuantity()`
  reports it; the units that never came are simply never resolvable.
- **More arrive than approved** — `ReturnQuantityExceeded`. **Amendable:** raise
  the approval and receive the same parcel again. A surface answers `409`.
- **Something arrives that was never requested** — `UnexpectedReturnLine`.
  **Permanent:** no amendment to this return makes the parcel belong to it. A
  surface answers `422`.

Those last two are **two exception classes for two opposite conditions**, on
purpose. A surface uses `instanceof`, never a message substring.

## A refund is not a return

Money movement belongs to whoever owns the tender. This package:

- **records** an amount and a reference; it does not move money, and `src/` names
  no payment provider at all — a boundary test greps for five of them;
- stores **no status** on a refund, because a row exists only when the money
  moved;
- keeps **no balance** — no `refund_total`, no `fully_refunded`. This module holds
  no line prices, so it cannot know what "fully" would be. `refundedMinor()` sums
  the rows;
- caps nothing, for the same reason. Whoever knows the prices decides the amount.

Money is integer minor units everywhere, and tax is an input: a rate in basis
points, an already-computed amount, both, or neither. Nothing is derived and no
rate is ever looked up.

## Documentation

- [`docs/domain.md`](docs/domain.md) — the states, the invariants, and what this
  module deliberately does not own.
- [`docs/adoption.md`](docs/adoption.md) — installing, the listeners, and what to
  do with the host's `refunds` and `return_requests` tables.
- [`docs/runbook.md`](docs/runbook.md) — what breaks in production and how to tell
  which thing broke.
- [`CHANGELOG.md`](CHANGELOG.md)

## License

MIT. See [`LICENSE.md`](LICENSE.md).
