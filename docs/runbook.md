# Runbook

What breaks in production, how to tell which thing broke, and what to do about
it.

---

## Nothing in this package runs on a timer

There is no scheduler, no queue worker and no background job here. Every sweep
below is a query this module exposes and a decision the host makes. That is
deliberate: a package that schedules work has decided somebody else's queue
depth.

```php
// app/Console/Kernel.php, or wherever the host schedules
$schedule->command('returns:expire-overdue')->daily();
```

---

## 1. Approved returns are piling up and the goods never arrive

**Symptom.** `ReturnQuery::awaitingGoodsSince(now())` returns a growing set.
Shoppers say they posted the parcel.

**What it means.** `approved` means a label went out and nothing has come back.
This is the state the whole module is shaped around — a request can be made,
approved, and then the goods simply never arrive.

Usual causes, in order of likelihood:

1. The returns label is broken, or was never sent. This module issues no label; it
   has no carrier, no tracking and no `return_method` column, because a return can
   come back in two parcels on two carriers. Ask whoever ships.
2. Receipts are landing but nobody calls `ReceiveGoods`. Check whether the
   warehouse surface is throwing — a `UnexpectedReturnLine` at the desk looks like
   nothing happening.
3. The window is too short and every return expires before a second-class parcel
   arrives. `goods_due_by` is supplied by the caller; look at what the surface
   passes.

**Find them:**

```php
(new ReturnQuery())->awaitingGoodsSince(now())->get();
```

**Close them:**

```php
(new TransitionReturn())->handle($return, ReturnStatus::Expired, reason: 'goods-never-arrived');
```

`expired` is terminal and refuses goods afterwards. If the parcel then turns up,
the answer is a **new request** with its own authorisation, not a reopened one —
see §4.

**What not to do.** Do not write `status` directly. It is not fillable, the
transition table is the only control, and a direct write leaves no history row, so
the next person investigating has a return in a state with no explanation of how
it got there.

---

## 2. `ReturnQuantityExceeded` at the receiving desk

**Amendable. The parcel is fine; the number is not.**

The line is the right line, and more of it arrived than was authorised. Read the
message — it names the line, the quantity and what was outstanding.

**Diagnose:**

```php
$return->lines->map->only(['order_line_id', 'quantity_approved', 'quantity_received']);
```

**Fix.** Somebody with the `approve` ability decides whether the extra units are
genuinely wanted back. If they are, the approval has to be raised — and note that
`ApproveReturn` only runs from `requested`, so a return already `received` cannot
be amended in place. In practice: receive what was authorised, and raise a second
return for the surplus.

**What not to do.** Do not clamp it in a surface. Silently accepting the extras
surfaces later as a refund for goods nobody agreed to take back, which is the
worst possible place to find the error.

---

## 3. `UnexpectedReturnLine` at the receiving desk

**Permanent. Nothing you do to this return makes the parcel belong to it.**

Two shapes:

- *"does not cover order line N"* — the parcel names something this return never
  asked for. Quarantine the goods and raise a request that covers them. Do **not**
  add a line to the existing return by hand: creating lines at receipt would let a
  warehouse authorise returns by receiving them, which deletes the approval step
  by accident.
- *"is `<status>` and is not open to goods"* — the return is not `approved` or
  `received`. If it is `expired`, the parcel arrived after the merchant closed the
  authorisation. That is a commercial decision, not a data one: somebody raises a
  new request, or the goods go back.

---

## 4. The counters disagree with the order module

**Symptom.** An order line says three returned; this module says two came back.
Or the host's listener is logging `LineAccountingExceeded` (`returned >
fulfilled`).

**What it means.** The host's `ReturnGoodsReceived` listener is the only thing
that raises that counter, and both sides are append-only. So a disagreement is
one of three things:

1. **The listener ran twice** — a queued job redelivered. `$receipts` are deltas,
   so a redelivery posts the same delta again. This module offers no idempotency
   key on a receipt; making the listener idempotent is the host's job, where the
   queue is.
2. **The listener did not run** — queue stopped, or it threw. Check for
   `LineAccountingExceeded` in the log.
3. **`returned > fulfilled` genuinely** — goods came back that the order module
   does not think were ever delivered. That is a fulfillment record that never
   got written, not a returns bug. The order module refuses rather than clamping
   precisely so this surfaces here rather than as a refund.

**Reconcile:**

```php
(new ReturnQuery())->receivedForOrderLine($orderLineId);   // what came back, here
```

against `returnableQuantity()` / the `returned` counter on the order line. This
module's number is the physical truth — it is what somebody scanned at a desk.

---

## 5. `ReturnNotRefundable` when somebody tries to refund

**Working as intended, almost always.** The symmetric invariant: nothing is
settled that never came back.

Read which of the three it is:

- *"has taken delivery of nothing"* — the return has not reached `received`, or
  reached it with zero quantities. Receive the goods first, or expire the return.
  Do **not** transition it to `received` by hand to unblock a refund; that writes
  a receipt for a parcel nobody has.
- *"must be positive"* — somebody passed a negative amount. Charging is not a
  thing a returns module does.
- *"converts nothing"* — the refund currency is not the return's. This module
  holds no rates. A cross-currency refund is a different transaction somebody else
  is responsible for.

---

## 6. A refund looks wrong

**First: this module did not move the money.** A row here means the host moved it
and recorded it, with a reference. Check the reference against whatever system
actually holds the tender before looking at anything here.

**There is no total to be wrong.** No `refund_total`, no `fully_refunded`, no
`partially_refunded` — `ReturnRequest::refundedMinor()` sums the rows every time
it is asked. If the sum looks wrong, one of the rows is wrong or one is missing;
there is no cached number to be stale.

**There is no cap.** This module holds no line prices — frozen line money belongs
to the order module — so it will record whatever amount it is handed. An
over-refund is a bug in whoever computed the amount, and the `actor_id` on the
row says who was signed in.

```sql
SELECT kind, amount_minor, currency, reference, actor_id, recorded_at
FROM ecommerce_returns_refunds WHERE return_request_id = ?;
```

---

## 7. Goods went back on the shelf that should not have

**This module writes no stock.** It publishes `ReturnInspected` with per-line
`restockable` and `rejected`, and the host's listener does the movement.

So: read the dispositions and see whether the inspection was wrong or the listener
was.

```php
$return->lines->map->only(['order_line_id', 'quantity_received', 'quantity_restockable', 'quantity_rejected']);
```

If `quantity_rejected` is right and stock moved anyway, the listener is ignoring
it. If the inspection itself was wrong, there is no correction path here by
design: `inspected` is terminal-ish and dispositions are append-only. Correct the
stock where stock lives.

---

## 8. Telemetry

Off by default. Turn it on while investigating:

```dotenv
RETURNS_TELEMETRY=true
RETURNS_TELEMETRY_CHANNEL=stack
```

Five records, and the levels carry meaning so an alert needs no message parsing:

| Event | Level |
| --- | --- |
| `return.requested` | `info` |
| `return.transitioned` | `warning` for `refused`, `cancelled`, `expired`; `info` otherwise |
| `return.goods_received` | `info` |
| `return.inspected` | `warning` when anything was rejected |
| `return.refund_recorded` | `info` |

A spike in `return.transitioned` at `warning` with `to: expired` is a broken
returns label. A spike in `return.inspected` at `warning` is a product arriving
damaged, or a courier.

**No personal data is written, ever** — and in particular never a line's `note`,
which is the one free-text field in this package. Reasons travel as slugs, which
is what makes them safe to log at all. A test asserts a note never reaches a log
line.

---

## 9. Things that are not this module's problem

| Symptom | Where it lives |
| --- | --- |
| The shopper was not emailed | The host. Five domain events; subscribe to one. |
| The label never arrived | Shipping / Fulfillment. No carrier here. |
| The money did not reach the card | Whoever owns the tender. A row here says it was recorded, not that it cleared. |
| The order still says `completed` | The order module. A return does not change an order's status; `refunded` and `partially_refunded` are deliberately not statuses anywhere. |
| Stock did not go up | Inventory Ledger, via the host's `ReturnInspected` listener. |
| A shopper can return more than they bought | Eligibility is an input. Whatever computed `returnableQuantity` got it wrong; this module stored what it was told. |
