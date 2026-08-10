# Adoption

Installing the module, wiring the two listeners it cannot wire itself, and what
to do with the host's `refunds`, `refund_items`, `return_requests` and
`return_request_items` tables.

---

## 1. Install and enable

```bash
composer require liberusoftware/ecommerce-returns
```

Installing boots nothing. The package ships no `extra.laravel.providers`, so
`ModuleManagerServiceProvider` is the only thing that registers it, and only when
the deployment names it:

```dotenv
MODULES_ENABLED=ecommerce-returns
```

Then:

```bash
php artisan migrate
php artisan vendor:publish --tag=returns-config   # optional
```

### What the host must supply

| Setting | Default | Notes |
| --- | --- | --- |
| `returns.team_model` | `App\Models\Team` | Resolved at call time, never imported. |
| `returns.customer_model` | *none* | No default. Asking for `ReturnRequest::customer()` without setting it **throws**, rather than guessing a class and failing later with a message about a missing table. |
| `returns.telemetry.enabled` | `false` | Structured domain-event records. Off by default. |
| `returns.telemetry.channel` | *null* | A Laravel log channel name. |

---

## 2. The two listeners

This package imports nothing. It requires no sibling `liberusoftware/ecommerce-*`
package and `src/` names no commerce namespace but its own — a boundary test
asserts that as a *set*, so a sibling appearing in so much as a docblock fails the
build. Its whole suite runs with no orders module and no fulfillment module
installed, over order line ids nothing in the database has heard of.

So two things this module knows have to reach modules it does not know, and the
host — the only party entitled to know both exist — writes them.

### 2.1 Goods came back → raise the returned counter on the order line

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

Three things about that listener:

1. **`$receipts` are deltas.** A return coming back in two parcels dispatches this
   twice, with two and then three, never with two and then five. The counter on
   the far side is append-only too, so a total posted twice is double the goods.
2. **`AccountForLine` may refuse**, with `returned > fulfilled`. That is the far
   side's invariant — nothing can come back that never went out — and it firing
   means the two systems disagree about what was delivered. See
   [`runbook.md`](./runbook.md) §3; do not catch and ignore it.
3. **Queue it if you like, but make it idempotent at your end.** A redelivered
   job would post the same delta twice. This module has no idempotency key on a
   receipt, because the guarantee it *can* offer — one return may not list an
   order line twice — is at the index, and a queue's redelivery is the host's
   problem to solve where the queue is.

### 2.2 Inspection said what is saleable → move the stock

```php
use Liberu\Ecommerce\Returns\Events\ReturnInspected;

Event::listen(ReturnInspected::class, function (ReturnInspected $event): void {
    foreach ($event->dispositions as $disposition) {
        if ($disposition->restockable > 0) {
            // The inventory ledger's own action, with $disposition->orderLineId
            // or the product id off the return's line.
        }
    }
});
```

A returned unit is not automatically saleable, and this module writes no stock.
Whether the movement happens at the desk or after a week in quarantine is a
warehouse policy, and both answers are correct.

### 2.3 Reading eligibility before raising a request

`RequestReturn` needs `returnableQuantity` per line, and the host reads it from
the order module's published line contract:

```php
use Liberu\Ecommerce\Orders\Queries\OrderQuery;
use Liberu\Ecommerce\Returns\Data\ReturnLineInput;
use Liberu\Ecommerce\Returns\Enums\ReturnReason;

$line = (new OrderQuery())->line($orderLineId);

$input = new ReturnLineInput(
    orderLineId: $line->id,
    quantity: $wanted,
    reason: ReturnReason::Faulty,
    returnableQuantity: $line->returnableQuantity(),   // ← the ceiling, read there
    name: $line->name,
    sku: $line->sku,
    productId: $line->productId,
);
```

`returnableQuantity()` is published there precisely so nobody derives it
differently. Read it, do not recompute it, and do not cache it across a request —
another return may have landed in between, and the value is stored here as
evidence of what you were told, not as a running total.

---

## 3. The host's existing returns and refunds tables

**This module does not adopt them.** Every table here is new and carries the
`ecommerce_returns_` prefix, and `SchemaTest` asserts both halves — that the
module's tables are prefixed, and that `refunds`, `refund_items`,
`return_requests` and `return_request_items` are *not* present.

That is a deliberate departure from `MODULE_DEVELOPMENT.md` §1.5's "a table that
existed in the host keeps its bare name". The rule exists so an extraction does
not rename a hundred tables mid-migration. It does not fit here, because the
host's tables are not these tables.

### 3.1 What each host column did, and where it went

**`return_requests`**

| Host column | Goes to | Why |
| --- | --- | --- |
| `order_id`, `customer_id` | **Returns**, as plain indexed columns | The host constrains both with foreign keys into `orders` and `users`. A package that constrains a table it does not own cannot be installed without it. |
| `rma_number` | **Returns**, as `number` | Same idea, generated from the CSPRNG rather than `uniqid()` — `uniqid()` is the microsecond clock in hex, so two requests in the same tick collide and every one of them leaks when the others were made. |
| `reason`, `description` | **Returns**, as a per-**line** `reason` enum and `note` | A return of three items has three reasons. And the reason is a closed set of slugs, because it is copied into an event. |
| `status` | **Returns**, as a seven-state machine | The host has five, written by `update()` from anywhere. See [`domain.md`](./domain.md) §3. |
| `approved_by`, `approved_at`, `received_at` | **Returns**, as history rows and eight clocks | Two timestamps cannot describe five clocks, and one `approved_by` cannot say who refused it. |
| `return_method`, `tracking_number` | **Shipping / Fulfillment** | Getting a parcel from a shopper to a warehouse is a shipment. One return can come back in two parcels on two carriers, so a column here can only hold the first answer and then be wrong. |

**`return_request_items`**

| Host column | Goes to | Why |
| --- | --- | --- |
| `order_item_id` | **Returns**, as `order_line_id`, unconstrained | The identifier, and the whole integration. |
| `quantity` | **Returns**, as **five** quantities | Requested, approved, received, restockable and rejected are different facts and will disagree. One column cannot hold a short receipt. |
| `condition` (`unopened / opened / damaged`) | **Returns**, as an inspection **disposition** | The host derives `restock = condition !== 'damaged'` in a model method. Restockable is a decision somebody makes about goods they are holding, not a string comparison. |
| `notes` | **Returns**, as `note`, with a containment rule | See [`domain.md`](./domain.md) §7. |

**`refunds`**

| Host column | Goes to | Why |
| --- | --- | --- |
| `amount` `decimal(10,2)` | **Returns**, as `amount_minor` | See §3.2. |
| `status`, `processed_at`, `processed_by` | **Refused** (`actor_id` and `recorded_at` survive) | `pending / approved / rejected / processed` is a second, diverging copy of a state machine the payment system already owns. A row here means *this went back*. |
| `refund_method`, `transaction_id` | **Whoever owns the tender** | A `transaction_id` is a provider reference in a package that names no provider. The opaque `reference` is what survives, and this module never parses it. |
| `restock_items` | **Inventory Ledger (#862)** | One boolean for a whole return, in the one place where a box holds an unopened item and a worn one. Inspection publishes per-line dispositions instead. |
| `reason`, `notes` | **Returns**, on the line | A refund is money; the reason is about goods. |

**On `orders`** — the host added `refund_total`, `fully_refunded` and
`partially_refunded` in the same migration. All three are **refused**, on both
sides: the order module's schema test asserts their absence, and so does this
one. This module holds no line prices, so it cannot compute what "fully" means,
and a maintained total is a second copy of a sum that will eventually disagree
with the rows. `ReturnRequest::refundedMinor()` adds them up where the question is
asked.

### 3.2 The money conversion

The host's `refunds.amount` is `decimal(10,2)`. Money here is an integer count of
minor units, there is no decimal column anywhere, and `SchemaTest` asserts it
across all four tables.

Converting is **string arithmetic**:

```php
use Liberu\Ecommerce\Returns\Support\MinorUnits;

MinorUnits::fromDecimalString('19.99');   // 1999
(int) (19.99 * 100);                      // 1998  ← what not to do
```

`19.99` is not representable in binary floating point and the cast truncates what
is left. `tests/MoneyTest.php` pins that with the wrong answer written next to the
right one. A value more precise than its currency is **refused**, not rounded:
rounding `19.995` needs a rule, and picking one silently on somebody's refund is
not this module's decision.

### 3.3 The migration itself

There is no data migration in this package, deliberately — a package that moved a
host's rows would need to know that host's shape, and there is more than one.

The shape of the move, if you are writing one:

1. `return_requests` → `ecommerce_returns_requests`. Map the five statuses onto
   the seven: `pending → requested`, `approved → approved`, `rejected → refused`,
   `received → received`, `completed → resolved`. Nothing maps to `expired` or
   `cancelled`; those are states the old model could not express, which is the
   point.
2. `return_request_items` → `ecommerce_returns_lines`, with `quantity` copied into
   `quantity_requested` **and** `quantity_approved` for anything already approved,
   and into `quantity_received` as well for anything already `received`. Do not
   guess a `returnable_quantity`; write the requested quantity and accept that the
   evidence is only as good as the old schema.
3. `refunds` → `ecommerce_returns_refunds`, **only the processed ones**. A
   `pending` refund is not money that moved, and this table has no state to put it
   in. Carry `transaction_id` into `reference`.
4. `refund_items` — not reproduced. Its `amount` per line is a price this module
   does not hold, and its `restock` boolean is superseded by the disposition.
5. Delete the host's own migration for those four tables, and the three columns it
   added to `orders`.

Write it in the host, against both schemas, where the host's own data is.

---

## 4. What the host still has to decide

| Decision | Where it lands |
| --- | --- |
| How long a shopper has to post the goods back | `ApproveReturn(..., goodsDueBy: …)`. Never defaulted here. |
| Whether an expiry sweep runs, and how often | The host's schedule, over `ReturnQuery::awaitingGoodsSince()`. |
| Whether a returned unit goes back on a shelf | The `ReturnInspected` listener. |
| Whether the shopper is emailed at any step | Five domain events; subscribe to whichever means "tell them". |
| How much to refund | Whoever knows the line prices. This module records the amount and caps nothing. |
| Who may approve, refuse, receive, inspect and refund | Seven named policy abilities, all deniable independently. |

---

## 5. Authorization

Two policies are registered explicitly, because Laravel's unanswered gate case is
permissive and an unpolicied model is exposed rather than safe.

`ReturnRequestPolicy` answers **fourteen abilities by name**, including the five
that are always false — a policy that is present but silent on an ability is the
sharper version of no policy at all, because a panel's authorization helper
returns *allow* when a present policy has no method for what it asked about.

`create`, `update`, `delete`, `restore` and `forceDelete` are permanently refused.
A return is *requested*, from an input carrying an eligibility number a blank
create form cannot supply; the quantities are the record; and the history and the
refunds hang off the row.

`RefundPolicy` refuses everything that writes. A refund row means money already
moved; a create form would mint a record for money nobody sent, and an edit form
would let somebody change an amount an accountant reconciles against a bank.

Tenancy is read off the actor's `current_team_id`, so it answers the same way in a
console command, a queued job and an API request. A return belonging to nobody is
visible to nobody: the comparison is bound, never `null === null`.
