# Changelog

All notable changes to `liberusoftware/ecommerce-returns` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
versions are bare `MAJOR.MINOR.PATCH` tags — no `v` prefix — per ADR 0005 of the
Ecommerce repository.

## 0.1.0 — 2026-08-10

First release. The module is extracted from
`liberusoftware/ecommerce-laravel`, where these classes shipped as
`App\Models\ReturnRequest`, `App\Models\ReturnRequestItem`, `App\Models\Refund`
and `App\Models\RefundItem`. The namespace is new, and so are the tables — see
*Changed* below for why nothing kept a bare name.

### Added

- **The return as a workflow.** `ReturnRequest` with lines, a seven-state machine,
  eight clocks, an append-only history and a public `number` that is not `id` —
  an incrementing id in a customer-facing URL is an enumeration of everybody's
  returns.
- **A state machine with more than one clock.** `requested → approved → received
  → inspected → resolved`, with `refused` and `cancelled` reachable before the
  goods move and **`expired`** for the case this domain is defined by: a request
  made, approved, and then the parcel never comes.
  `Actions\TransitionReturn` is the only door — `status` and all eight timestamps
  are deliberately absent from `$fillable` — and an illegal move throws
  `IllegalReturnTransition` and writes nothing: not the status, not the timestamp,
  not a history row, not an event. Fourteen illegal moves have a test each, plus
  **every self-transition**, because a no-op files a history row against a move
  nobody made and re-stamps a timestamp a retried click would otherwise move.
- **The symmetric invariant.** Whoever owns order lines refuses `returned >
  fulfilled` — nothing can come back that never went out. This module refuses the
  mirror: **nothing is settled that never came back.** `approved → resolved` is
  absent from the transition table, `RecordRefund` refuses a return that has taken
  delivery of nothing, and both the state and the arithmetic are checked. Neither
  module can enforce the other's half, because neither holds the other's counters.
- **Requested, approved and received kept as separate facts.** Five quantities per
  line, refused rather than clamped at every link:
  `requested ≤ returnable`, `approved ≤ requested`, `received ≤ approved`,
  `restockable + rejected ≤ received`. A **short receipt is allowed** and reported
  as `shortfallQuantity()` — three arriving against five is what couriers and
  shoppers do — while arriving with *more* is refused, because that is what a
  mistake looks like.
- **Two exception classes for two opposite conditions.** `UnexpectedReturnLine` is
  permanent (goods nobody authorised; answer `422`, quarantine them) and
  `ReturnQuantityExceeded` is amendable (the right line, too many of it; answer
  `409`, raise the approval and retry). A sibling module publishes one class for
  both of its conditions and its API rebuilds a message string to tell them apart;
  a surface over this module uses `instanceof`.
- **Eligibility as an input, never a lookup.** `ReturnLineInput::$returnableQuantity`
  is handed in by the caller, who read it from whoever owns the order line — the
  same rule tax follows. It is stored as well as checked, because three months
  later the argument is about what the shopper was told on the day they asked.
- **Inspection dispositions.** `restockable` and `rejected` per line, because a
  returned unit is not automatically saleable and one box holds an unopened item
  and a worn one. An unfinished inspection is a real state and
  `uninspectedQuantity()` reports the gap.
- **Refunds as an amount and a reference.** `RecordRefund` writes down money that
  already moved; the host moves it and hands back the reference. Four kinds —
  `tender`, `store_credit`, `exchange`, `manual` — because they are different
  accounting facts. Tax is an input in both shapes and neither is derived from the
  other.
- **`ReturnRequestPolicy` and `RefundPolicy`**, both registered explicitly.
  Fourteen abilities answered by name on the return, including the five that are
  always false; everything that writes a refund refused outright.
- **Five domain events** — `ReturnRequested`, `ReturnTransitioned`,
  `ReturnGoodsReceived`, `ReturnInspected`, `RefundRecorded` — carrying plain
  values rather than Eloquent models.
- **`Queries\ReturnQuery`** — `byNumber`, `dataByNumber`, `forOrder`,
  `forOrderLine`, `receivedForOrderLine`, `open`, `awaitingGoodsSince`,
  `forCustomer`. No `byId`, on purpose.
- **Telemetry**, off by default, writing five structured records and no personal
  data at all.

### Changed

- **Every table is new and prefixed `ecommerce_returns_`.** There is no bare-name
  exception here, which is a deliberate departure from `MODULE_DEVELOPMENT.md`
  §1.5. Between them the host's four tables hold a `decimal(10,2)` amount, a
  payment gateway's transaction id, a `restock_items` boolean and foreign keys
  into `orders` and `users`; keeping the names would have meant keeping the shape.
  `docs/adoption.md` §3 lists every column and where it went.
- **Money is integer minor units.** The host's `refunds.amount` is
  `decimal(10,2)`. There is no decimal column anywhere here, asserted across all
  four tables. Converting is string arithmetic — `(int) (19.99 * 100)` is `1998` —
  and a value more precise than its currency is refused rather than rounded.
- **One quantity became five.** The host's `return_request_items.quantity` cannot
  express a short receipt, and its `condition` string cannot express a box in
  which two units are saleable and one is not.
- **Five statuses became seven**, and two of the new ones (`cancelled`, `expired`)
  are states the old model could not express at all.
- **`rma_number` is generated from the CSPRNG**, not `uniqid()`. `uniqid()` is the
  microsecond clock in hex: two requests in the same tick collide, and every
  number leaks when the others were made.
- **One `create` migration per table**, with every column already on it.

### Deliberately not included

- **No payment capture, no gateway, and no provider name anywhere in `src/`.** A
  boundary test greps for five of them. The host's `Refund::process()` voids a
  charge, restocks, increments a total on the order, transitions the order and
  emails the customer — five domains in one method. This module does one.
- **No refund status.** `pending / approved / rejected / processed` is a second,
  diverging copy of a state machine the payment system already owns. A row means
  *this went back*; if it did not, there is no row.
- **No maintained balance and no cap.** `refund_total`, `fully_refunded` and
  `partially_refunded` are absent from this schema and asserted absent. This module
  holds no line prices, so it cannot know what "fully" would be, and a column that
  claimed to know would be wrong the first time shipping was refunded separately.
  `refundedMinor()` sums the rows.
- **No restocking.** A returned unit is not automatically saleable. Inspection
  publishes per-line dispositions and the host wires them to Inventory Ledger
  ([#862](https://github.com/liberusoftware/ecommerce-laravel/issues/862)); this
  package writes no stock and requires nothing that does.
- **No labels, carriers or tracking.** Getting a parcel from a shopper to a
  warehouse is a shipment, and one return can come back in two parcels on two
  carriers — a column here can only hold the first answer and then be wrong.
- **No returns policy.** No default window, no restocking fee, no rule about who
  pays postage. `goods_due_by` is supplied at approval; a package that defaulted
  it to thirty days would have written somebody's returns policy into a constant.
- **No free-text reason.** `ReturnReason` is a closed set of seven slugs, because
  the value is copied into a domain event and a merchant needs to group by it.
  `ReturnLine::$note` exists for a shopper who genuinely has to describe a fault,
  and is contained by rule: never in a read model, never in an event, never in a
  log line, and a test pins each.
- **No idempotency key on a request.** A duplicated *placement* charges somebody
  twice; a duplicated *return request* is a second piece of paper a merchant can
  refuse. What is guarded is the thing that would corrupt arithmetic — one return
  may not list the same order line twice, at the index.
- **No scheduler.** `ReturnQuery::awaitingGoodsSince()` finds returns whose goods
  are overdue; what to do about them is the host's policy.
- **No exchange fulfilment.** `RefundKind::Exchange` records that an exchange was
  the outcome. Shipping the replacement is an order, and orders are placed
  elsewhere.

### Boundary

- **Returns imports nothing.** No `require` on any sibling
  `liberusoftware/ecommerce-*` package — asserted by prefix, so a sibling that
  does not exist yet is covered — and `src/` names no commerce namespace but this
  one, asserted as a *set* so a sibling appearing in a docblock fails the build.
- **The host writes two listeners**, carried verbatim in `README.md` and
  `docs/adoption.md`: `ReturnGoodsReceived` → the order module's line-accounting
  action, and `ReturnInspected` → whatever owns stock. Receipts are **deltas, not
  totals**, because the counter on the far side is append-only too.
- The suite runs with **no orders module and no fulfillment module present**, over
  order line ids nothing in the database has heard of, under a test named for the
  fact.
- **No foreign key leaves this module.** `order_id`, `order_line_id`,
  `product_id`, `variant_id`, `team_id`, `store_id`, `customer_id` and `actor_id`
  are plain indexed columns, asserted as a set so a table with no keys still makes
  an assertion.
