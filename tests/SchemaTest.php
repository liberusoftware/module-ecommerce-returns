<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migrations are this module's public surface as much as its classes are — a
 * consumer's data lives in these tables, and a column quietly renamed or dropped
 * between releases is an outage on deploy rather than a failing build.
 *
 * These assert the shape a consumer may rely on. Changing one on purpose means an
 * entry in the changelog and, past 1.0.0, a major version.
 */
const RETURNS_TABLES = [
    'ecommerce_returns_requests',
    'ecommerce_returns_lines',
    'ecommerce_returns_status_changes',
    'ecommerce_returns_refunds',
];

it('creates every table the module owns', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(RETURNS_TABLES);

it('prefixes every table, because this module invented all of them', function (string $table) {
    // MODULE_DEVELOPMENT.md §1.5. There is no bare-name exception here: between
    // them the host's `refunds`, `refund_items`, `return_requests` and
    // `return_request_items` hold a `decimal(10,2)` amount, a payment gateway's
    // transaction id, a `restock_items` boolean and four foreign keys into tables
    // this package does not own. Keeping the names would mean keeping the shape,
    // and the shape is what is being fixed. `docs/adoption.md` says what the host
    // does with its own.
    expect($table)->toStartWith('ecommerce_returns_');
})->with(RETURNS_TABLES);

it('claims none of the bare names the host already uses', function (string $bare) {
    expect(Schema::hasTable($bare))->toBeFalse();
})->with([
    'refunds', 'refund_items', 'return_requests', 'return_request_items',
    'orders', 'order_items', 'products', 'stock_levels',
]);

it('gives each table the columns a consumer reads', function (string $table, array $columns) {
    foreach ($columns as $column) {
        expect(Schema::hasColumn($table, $column))->toBeTrue();
    }
})->with([
    'requests' => ['ecommerce_returns_requests', [
        'id', 'number', 'team_id', 'store_id', 'customer_id', 'order_id',
        'status', 'currency', 'currency_exponent', 'goods_due_by',
        'requested_at', 'approved_at', 'refused_at', 'cancelled_at',
        'expired_at', 'received_at', 'inspected_at', 'resolved_at',
        'created_at', 'updated_at',
    ]],
    'lines' => ['ecommerce_returns_lines', [
        'id', 'return_request_id', 'order_line_id', 'product_id', 'variant_id',
        'sku', 'name', 'reason', 'note', 'returnable_quantity',
        'quantity_requested', 'quantity_approved', 'quantity_received',
        'quantity_restockable', 'quantity_rejected',
    ]],
    'status changes' => ['ecommerce_returns_status_changes', [
        'id', 'return_request_id', 'from_status', 'to_status', 'actor_id', 'reason', 'created_at',
    ]],
    'refunds' => ['ecommerce_returns_refunds', [
        'id', 'return_request_id', 'kind', 'amount_minor', 'currency',
        'currency_exponent', 'tax_rate_bp', 'tax_minor', 'reference',
        'actor_id', 'recorded_at',
    ]],
]);

it('holds none of the columns that belong to another module or to nobody', function (string $table, string $column) {
    // Every one of these is argued in `docs/adoption.md`; this is the argument
    // made mechanical, so a future convenience column fails the build rather than
    // the boundary.
    expect(Schema::hasColumn($table, $column))->toBeFalse();
})->with([
    // A refund row means money already moved. A second copy of somebody else's
    // state machine is a second answer to whether it did.
    'refund status' => ['ecommerce_returns_refunds', 'status'],
    'refund processed flag' => ['ecommerce_returns_refunds', 'processed_at'],
    // No gateway, no provider, no capture. This package names none.
    'refund transaction id' => ['ecommerce_returns_refunds', 'transaction_id'],
    'refund method' => ['ecommerce_returns_refunds', 'refund_method'],
    'refund gateway' => ['ecommerce_returns_refunds', 'gateway'],
    'refund provider' => ['ecommerce_returns_refunds', 'provider'],
    // Restocking is a stock movement in the module that owns stock. Inspection
    // publishes a disposition; nothing here writes a shelf.
    'refund restock flag' => ['ecommerce_returns_refunds', 'restock_items'],
    'line restock flag' => ['ecommerce_returns_lines', 'restock'],
    // A maintained balance this module cannot compute, because it holds no line
    // prices. A sum over the refund rows is the answer.
    'maintained total' => ['ecommerce_returns_requests', 'refund_total'],
    'fully refunded' => ['ecommerce_returns_requests', 'fully_refunded'],
    'partially refunded' => ['ecommerce_returns_requests', 'partially_refunded'],
    // Getting a parcel from a shopper to a warehouse is a shipment, and one
    // return can come back in two parcels on two carriers — a column here can
    // only hold the first answer and then be wrong.
    'carrier' => ['ecommerce_returns_requests', 'shipping_carrier'],
    'tracking number' => ['ecommerce_returns_requests', 'tracking_number'],
    'return method' => ['ecommerce_returns_requests', 'return_method'],
]);

it('stores every money column as an integer, with no decimal anywhere', function (string $table) {
    // Integer minor units, settled in wave 3 and not up for rediscovery. The
    // host's `refunds.amount` is `decimal(10,2)`, which is a float in every
    // driver that implements it as one. The naming convention is what makes this
    // assertable at all: every money column ends `_minor`, so a new one cannot
    // slip past.
    foreach (Schema::getColumns($table) as $column) {
        expect(strtolower($column['type_name']))
            ->not->toContain('decimal')
            ->not->toContain('float')
            ->not->toContain('double')
            ->not->toContain('numeric')
            ->not->toContain('real');
    }
})->with(RETURNS_TABLES);

it('makes every money column on the refund an integer count of minor units', function () {
    $money = collect(Schema::getColumns('ecommerce_returns_refunds'))
        ->filter(fn (array $column): bool => str_ends_with($column['name'], '_minor'));

    // Asserted non-empty first: a loop over nothing is a test that passes after
    // the thing it guards has been deleted.
    expect($money)->toHaveCount(2);

    foreach ($money as $column) {
        expect(strtolower($column['type_name']))->toContain('int');
    }
});

it('carries no foreign key into another module s tables', function (string $table) {
    // The boundary, proved rather than asserted. Every key this schema declares
    // points at a table this package created. `order_id`, `order_line_id`,
    // `product_id`, `variant_id`, `team_id`, `store_id`, `customer_id` and
    // `actor_id` are plain columns — a package that constrains a table it does
    // not own cannot be installed without it.
    $foreign = collect(Schema::getForeignKeys($table))
        ->pluck('foreign_table')
        ->unique()
        ->values()
        ->all();

    // Asserted as a set rather than in a loop, so a table with no foreign keys at
    // all still makes an assertion.
    expect(array_diff($foreign, RETURNS_TABLES))->toBe([]);
})->with(RETURNS_TABLES);

it('leaves every cross-boundary identifier unconstrained and indexed', function (string $table, string $column) {
    $constrained = collect(Schema::getForeignKeys($table))
        ->contains(fn (array $key): bool => in_array($column, $key['columns'], true));

    expect(Schema::hasColumn($table, $column))->toBeTrue()
        ->and($constrained)->toBeFalse();
})->with([
    'the order' => ['ecommerce_returns_requests', 'order_id'],
    'the team' => ['ecommerce_returns_requests', 'team_id'],
    'the store' => ['ecommerce_returns_requests', 'store_id'],
    'the customer' => ['ecommerce_returns_requests', 'customer_id'],
    'the order line' => ['ecommerce_returns_lines', 'order_line_id'],
    'the product' => ['ecommerce_returns_lines', 'product_id'],
    'the variant' => ['ecommerce_returns_lines', 'variant_id'],
    'the status change actor' => ['ecommerce_returns_status_changes', 'actor_id'],
    'the refund actor' => ['ecommerce_returns_refunds', 'actor_id'],
]);

it('takes a return s children with it, because none of them means anything alone', function (string $table) {
    $key = collect(Schema::getForeignKeys($table))
        ->first(fn (array $key): bool => in_array('return_request_id', $key['columns'], true));

    // The declaration is asserted rather than the deletion. SQLite enforces
    // foreign keys only with the pragma on, and a pragma issued inside
    // `RefreshDatabase`'s transaction is a no-op, so a behavioural test here
    // would pass or fail on how the suite is wired.
    expect($key)->not->toBeNull()
        ->and($key['foreign_table'])->toBe('ecommerce_returns_requests')
        ->and(strtolower((string) $key['on_delete']))->toBe('cascade');
})->with(['ecommerce_returns_lines', 'ecommerce_returns_status_changes', 'ecommerce_returns_refunds']);

it('refuses one return listing the same order line twice, at the index', function () {
    // **The guarantee.** Two rows for one order line would make every quantity on
    // this table ambiguous, and the ambiguity would first show up as a refund. It
    // is the index that closes it, not a check in application code.
    $return = aReturn();

    $row = [
        'return_request_id' => $return->id,
        'order_line_id' => GHOST_LINE,
        'name' => 'Merino Crew',
        'reason' => 'faulty',
        'quantity_requested' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('ecommerce_returns_lines')->insert($row);
})->throws(QueryException::class);

it('lets two different returns name the same order line, because a second parcel is a second decision', function () {
    $first = aReturn([returnLine(quantity: 1)]);
    $second = aReturn([returnLine(quantity: 1)]);

    expect($first->id)->not->toBe($second->id)
        ->and(DB::table('ecommerce_returns_lines')->where('order_line_id', GHOST_LINE)->count())->toBe(2);
});

it('refuses two returns on one public number', function () {
    $row = fn (): array => [
        'number' => 'RMA-ABCDEF123456',
        'order_id' => GHOST_ORDER,
        'currency' => 'GBP',
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('ecommerce_returns_requests')->insert($row());
    DB::table('ecommerce_returns_requests')->insert($row());
})->throws(QueryException::class);
