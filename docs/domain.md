# Domain

What this module owns, what it refuses to own, and why each line is where it is.

---

## 1. Where this module starts

Whoever owns orders drew the boundary **at delivery**. Cancelling is calling off
something that has not happened: `completed → cancelled` is not a legal
transition there, and cancelling an order with anything fulfilled is refused.

Everything after delivery is here. A return is not a late cancellation — there
are goods in a van somewhere, somebody has to look at them, and there is a refund
decision attached. None of that is a status change on an order.

The seam is a single number crossing in each direction:

- **In:** an order line id, and the quantity still returnable, both handed to
  `RequestReturn` by the caller.
- **Out:** an order line id and a quantity that came back, carried on
  `ReturnGoodsReceived`, which the **host** hands to the order module's counter
  action.

This package requires no sibling package, imports from none, and its whole suite
runs with none installed.

---

## 2. The two invariants, one on each side

| Side | Rule | Enforced by |
| --- | --- | --- |
| Orders | `returned ≤ fulfilled` | its own line-accounting action |
| **Returns** | **nothing is settled that never came back** | `Enums\ReturnStatus::allowsRefund()`, `ReturnRequest::hasGoods()`, and the absent `approved → resolved` edge |

Nothing can come back that never went out; nothing is refunded that never came
back. **Neither module can enforce the other's half**, because neither holds the
other's counters — which is exactly why both halves exist rather than one.

The case the second one is defined by: a request is made, approved, a label goes
out, and the parcel never arrives. That return **expires**. It does not resolve,
and no refund may be recorded against it.

---

## 3. The state machine

```
requested ──▶ approved ──▶ received ──▶ inspected ──▶ resolved
    │             │
    ├──▶ refused  └──▶ expired
    └──▶ cancelled
```

| State | Means | Terminal |
| --- | --- | --- |
| `requested` | A shopper asked. Nothing is authorised, nothing has moved. | |
| `approved` | Authorised, for a quantity the merchant chose. The only state a label is issued in. | |
| `refused` | Declined, **before** authorisation. | ✅ |
| `cancelled` | Called off before the goods arrived, by either side. | ✅ |
| `expired` | Authorised, and the goods never came. | ✅ |
| `received` | Something arrived. Partial and repeatable. | |
| `inspected` | Every received unit has a disposition. | |
| `resolved` | Finished. | ✅ |

`Actions\TransitionReturn` is the only door. An illegal move throws
`IllegalReturnTransition` and **writes nothing** — not the status, not the
timestamp, not a history row, not an event. An attempt that was refused is not a
transition that happened, and a history containing refusals answers a different
question from the one it is kept for.

### Illegal moves that are decisions rather than omissions

| Move | Why it is refused |
| --- | --- |
| `approved → resolved` | The symmetric invariant. Finishing a return whose goods never came is a refund for a parcel nobody has. |
| `requested → received` | Goods arriving before authorisation. Accepting them would let a warehouse authorise returns by receiving them, which deletes the approval step by accident. |
| `received → resolved` | Skipping inspection. A restock decision has no input if nobody looked. |
| `approved → refused` | Refusal happens before a label goes out. Once goods are on their way, a bad item is an **inspection outcome**, not a refusal. |
| `received → cancelled` | The goods are here. Calling it off is not available any more. |
| `expired → received` | The parcel arrived after the merchant closed the authorisation. Adopting it would write a receipt nobody agreed to. |
| **every self-transition** | A no-op files a history row against a move nobody made and re-stamps a timestamp, so "when the merchant approved this" quietly becomes "when somebody last pressed the button". |

### Progress is not a state

A return of five units can be two received, one rejected on inspection and two
still in a van at the same moment, and one column cannot say that. The quantities
on `ReturnLine` do.

---

## 4. Requested, approved, received: three numbers, kept apart

| Column | Question it answers |
| --- | --- |
| `returnable_quantity` | What was the caller told was still returnable, on the day? |
| `quantity_requested` | What did the shopper ask for? |
| `quantity_approved` | What did the merchant authorise? |
| `quantity_received` | What physically arrived? |
| `quantity_restockable` / `quantity_rejected` | What did inspection conclude? |

**Approval may be smaller than the request and never larger.** A shopper asks for
five, the merchant agrees to three, and both numbers survive — rewriting the
request to match the approval loses the thing an argument three months later is
about. Authorising *more* invents a request the shopper never made, and the extra
units then arrive, get received, and turn into a refund nobody agreed to.

**The receipt decisions:**

| What happened | Answer | Class | Remedy |
| --- | --- | --- | --- |
| Fewer arrived than approved | **Allowed** | — | None. `shortfallQuantity()` reports it; the missing units are simply never resolvable. |
| More arrived than approved | **Refused** | `ReturnQuantityExceeded` | Raise the approval, receive the same parcel again. |
| Something arrived that was never requested | **Refused** | `UnexpectedReturnLine` | Quarantine it and raise a new request. Nothing amendable here. |

The asymmetry between the first two rows is deliberate: **arriving with fewer is
what a courier does, and arriving with more is what a mistake does.** Refused
rather than clamped, matching the order module — quietly accepting the extras
would surface as a wrong refund, which is the worst place to find an error.

### Two exception classes for two opposite conditions

`UnexpectedReturnLine` is **permanent** and `ReturnQuantityExceeded` is
**amendable**, and a surface answers `422` and `409` from `instanceof` alone.

This is the wave-4 finding not repeated. A sibling module publishes one class for
a permanent conflict and a transient one, and its API now rebuilds a message
string from the domain's own factory to tell them apart. Getting these two the
wrong way round is expensive in both directions: treating the permanent one as
amendable invites an operator to authorise goods after the fact to make an error
message go away, and treating the amendable one as permanent sends a perfectly
good parcel to quarantine.

---

## 5. Inspection, and the restock this module will not perform

**A returned unit is not automatically saleable.** That sentence is the reason
inspection is a step rather than a flag on a refund — a single box holds an
unopened item and a worn one, and `restock_items` answers for the whole return at
once.

`InspectReturn` records `restockable` and `rejected` per line, refuses more than
arrived, and allows less: an inspection that has not finished is a real state and
`uninspectedQuantity()` reports the gap.

Then it **publishes** `ReturnInspected` and stops. Putting a unit back on a shelf
is a stock movement in the module that owns stock (#862, already shipped), and
this package writes no stock, holds no stock and requires nothing that does. A
warehouse that quarantines returns for a week and one that restocks at the desk
are both correct, and neither is this package's to assume.

### The one-way door

**Inspecting closes the return to further goods.** Once a disposition is recorded
the merchant prices the outcome, and a third parcel arriving afterwards would land
against arithmetic that has already been settled. A late parcel is a new request
with its own authorisation, refused loudly rather than absorbed quietly.

The trade is recorded rather than hidden: a merchant who expects several parcels
should receive them all before inspecting. If that turns out to be the wrong
default in practice, the fix is a state, not a silent adoption.

---

## 6. A refund is an amount and a reference

Money movement belongs to whoever owns the tender. The order module explicitly
refused `payment_status`, `payment_method` and `transaction_id` as facts it does
not own; this module does not pick them up on the way past.

What a refund row is:

- an **amount** in integer minor units, with the currency the return was agreed
  in;
- an opaque **reference** — a credit note number, a ledger entry, whatever the
  host calls the movement. Never parsed, never validated, never called with;
- a **kind**: `tender`, `store_credit`, `exchange`, `manual`. Four genuinely
  different accounting facts — a store credit is a liability the merchant still
  owes, a tender refund is cash that has left, an exchange is neither.

What it is **not**:

| Not this | Why |
| --- | --- |
| A gateway call | `src/` names no provider at all; a boundary test greps for five. The host moves the money and hands back the reference, in that order. `RefundRecorded` is past tense, and a listener that calls a provider on it refunds every shopper twice. |
| A status | `pending / approved / rejected / processed` is a second, diverging copy of a state machine somebody else's system owns. A row means *this went back*; if it did not, there is no row. |
| A maintained balance | No `refund_total`, no `fully_refunded`, no `partially_refunded`. This module holds no line prices — frozen line money belongs to the order module — so it cannot know what "fully" would be, and a column claiming to know would be wrong the first time shipping was refunded separately. |
| A capped amount | Same reason. A cap is `line price × quantity`, and holding a copy of the price would be a second answer to what something cost. Whoever knows the prices decides the amount. |

**Tax is an input.** A rate in basis points, an already-computed amount, both, or
neither. Nothing is derived from the other and no rate is ever looked up.

Several refunds per return are ordinary: goods on receipt and shipping after a
conversation are two decisions.

---

## 7. A reason is evidence; a note is contained

`ReturnReason` is a **closed set of seven slugs**. A merchant groups by it to find
the batch that is faulty, the description that is wrong and the courier that keeps
arriving late — and nobody types the same sentence twice. It is also the field a
shopper is most likely to type a personal detail into, which is the reason a
sibling module's abandonment reason is a five-slug select and the order module's
cancellation reason is a 64-character slug.

A shopper who genuinely has to describe a fault writes `ReturnLine::$note`. Its
containment is a **rule, not a hope**, and each half is pinned by a test:

| Where | Does the note travel? |
| --- | --- |
| `Models\ReturnLine`, behind the policy | **Yes.** A staff surface reading a shopper's sentence is the job. |
| `Data\ReturnLineData` | **No** — the field does not exist on the read model, so nothing crossing a module boundary carries it. |
| Any domain event | **No.** Events carry ids, quantities and reason slugs. |
| Any log line | **No.** A log is the store in an application with the loosest access control and the longest reach. |

`ReturnStatusChange::$reason` is a 64-character slug for the same reason, capped
by the column.

---

## 8. What this module does not own

| Not here | Whose | Why |
| --- | --- | --- |
| Eligibility arithmetic | The order module | It is `delivered − already returned`, over counters that live there. Handed in as an input. |
| Cancellation before delivery | The order module | Calling off something that has not happened. |
| Line prices, and any total derived from them | The order module | Frozen at the moment the customer agreed. A copy here would be a second answer. |
| Moving money | Whoever owns the tender | This module records; it does not move. |
| Restocking | Inventory Ledger (#862) | A returned unit is not automatically saleable. Published, not performed. |
| Return labels, carriers, tracking | Shipping / Fulfillment | One return can come back in two parcels on two carriers, so a column here can only hold the first answer and then be wrong. |
| A returns *policy* — windows, restocking fees, who pays postage | The host | `goods_due_by` is supplied, never computed. A package that defaulted it to thirty days would have written somebody's returns policy into a constant. |
| Notifications | The host | Five domain events; subscribe to whichever means "tell the shopper". |
| Scheduling the expiry sweep | The host | `ReturnQuery::awaitingGoodsSince()` finds them; what to do is a decision. A package that schedules work has decided somebody else's queue depth. |

---

## 9. Tables

All four carry the `ecommerce_returns_` prefix, and `SchemaTest` proves it in both
directions — that these are prefixed, and that the host's bare names are absent.

| Table | Holds |
| --- | --- |
| `ecommerce_returns_requests` | The return, its public `number`, eight clocks and the authorisation window |
| `ecommerce_returns_lines` | One order line coming back, and its five quantities |
| `ecommerce_returns_status_changes` | Append-only history, both ends of every move |
| `ecommerce_returns_refunds` | Amount, currency, tax input, kind, reference |

**No foreign key leaves this package.** `order_id`, `order_line_id`,
`product_id`, `variant_id`, `team_id`, `store_id`, `customer_id` and `actor_id`
are plain indexed columns — a package that constrains a table it does not own
cannot be installed without it. `SchemaTest` asserts that as a set, so a table
with no keys at all still makes an assertion.

**No decimal or float column anywhere**, asserted across all four tables. Money
columns end `_minor` so a `decimal` slipping back in is visible in a diff.

One unique index does real work: `(return_request_id, order_line_id)`. Two rows
for one order line would make every quantity on that table ambiguous, and the
ambiguity would first show up as a refund.
