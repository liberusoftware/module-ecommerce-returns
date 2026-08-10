<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\Returns\Actions\ApproveReturn;
use Liberu\Ecommerce\Returns\Actions\ReceiveGoods;
use Liberu\Ecommerce\Returns\Data\LineReceipt;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Liberu\Ecommerce\Returns\Tests\Fixtures\FakeCustomer;

/**
 * **Returns imports nothing**, proved rather than asserted.
 *
 * No `require` on any sibling `liberusoftware/ecommerce-*` package, and no `use`
 * of any commerce namespace but this one anywhere in `src/`. Everything that
 * crosses is an identifier or a value already resolved: an order id, an order
 * line id, a quantity, a currency code, an amount in integer minor units.
 *
 * The assertions here are written **generally** — "every commerce namespace this
 * file mentions is this one", "nothing required starts with the sibling prefix" —
 * rather than by naming the things they forbid. A test that spells out a
 * forbidden token puts that token in the repository in order to look for it, and
 * that has already cost a sibling package two red CI runs.
 */
$sourceOf = function (): string {
    $files = array_merge(
        glob(__DIR__.'/../src/*.php') ?: [],
        glob(__DIR__.'/../src/**/*.php') ?: [],
    );

    return implode("\n", array_map(fn (string $file): string => (string) file_get_contents($file), $files));
};

it('runs its whole suite with no module that owns orders present, over line ids nothing has heard of', function () {
    // **The named test.** This is the place the rule is most tempting to break:
    // raising the *returned* counter on an order line is one method call away,
    // and making it would be an import. So no table belonging to a module that
    // owns orders, order lines, fulfillment or a catalogue exists in this
    // database, none of their classes is loadable, and a whole return is
    // requested, approved and received against ids that name nothing.
    foreach (['ecommerce_orders_orders', 'ecommerce_orders_lines', 'ecommerce_fulfillment_shipments', 'orders', 'order_items', 'products'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    // Concatenated so the class name is not a literal in this repository.
    expect(class_exists('Liberu'.'\\Ecommerce\\Orders\\Models\\OrderLine'))->toBeFalse()
        ->and(class_exists('Liberu'.'\\Ecommerce\\Orders\\Actions\\AccountForLine'))->toBeFalse();

    $return = aReturn([returnLine(quantity: 2, returnableQuantity: 2)]);
    (new ApproveReturn())->handle($return);
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 2)]);

    expect($return->fresh()->lines[0]->quantity_received)->toBe(2)
        ->and($return->order_id)->toBe(GHOST_ORDER)
        ->and($return->lines[0]->order_line_id)->toBe(GHOST_LINE);
});

it('mentions no commerce namespace but its own, anywhere in src', function () use ($sourceOf) {
    // A grep rather than a reflection check, because the thing being forbidden is
    // the *text*: a `use` statement is a dependency whether or not the class is
    // ever loaded, and a docblock explaining that this package does not import
    // something counts as importing it for exactly this test.
    //
    // Asserted as a set of every namespace found, so nothing has to be named. A
    // sibling appearing in a comment fails this without the comment's author
    // having to have guessed which siblings were on a list.
    preg_match_all('/Liberu\\\\Ecommerce\\\\(\w+)/', $sourceOf(), $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(array_values(array_unique($matches[1])))->toBe(['Returns']);
});

it('reaches the application namespace nowhere in src', function () use ($sourceOf) {
    expect($sourceOf())->not->toMatch('/(?:use|new|extends|implements)\s+App\\\\/');
});

it('names no payment provider anywhere in src', function (string $provider) use ($sourceOf) {
    // **A refund is an amount and a reference.** If one of these ever appears in
    // `src/`, this package has acquired an opinion about moving money that it is
    // not entitled to — and the SDK that goes with the name is one commit behind.
    expect($sourceOf())->not->toContain($provider);
})->with(['Stripe', 'PayPal', 'Braintree', 'Adyen', 'Klarna', 'Gateway']);

it('requires no sibling domain package at all', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);
    $required = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

    // Stated as a prefix rather than as a list of names, so a sibling that does
    // not exist yet is covered too.
    foreach ($required as $package) {
        expect($package)->not->toStartWith('liberusoftware/ecommerce-');
    }

    expect($required)->toContain('liberusoftware/module-manager');
});

it('subscribes to no event it does not own', function () {
    // Stated separately from the whole-`src/` grep because it is the specific
    // rule for this module: raising a counter that lives elsewhere is the host's
    // listener, not this provider's. Asserted as *every* commerce namespace the
    // provider mentions being this one, rather than by naming the class it must
    // not mention.
    $provider = (string) file_get_contents(__DIR__.'/../src/ReturnsServiceProvider.php');

    preg_match_all('/Liberu\\\\Ecommerce\\\\(\w+)/', $provider, $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(array_values(array_unique($matches[1])))->toBe(['Returns']);
});

it('publishes a receipt a host can hand to another module without importing anything', function () {
    // The contract, as plain values. This array is what a listener sees, written
    // out by hand — an order line id and a quantity, which is all the far side
    // needs and all this side is entitled to know.
    $return = aReturn([returnLine(orderLineId: GHOST_LINE, quantity: 3, returnableQuantity: 3)]);
    (new ApproveReturn())->handle($return);

    $receipts = [new LineReceipt(GHOST_LINE, 2)];

    expect(array_map(fn (LineReceipt $receipt): array => $receipt->toArray(), $receipts))
        ->toBe([['order_line_id' => 9000001, 'quantity' => 2]]);

    (new ReceiveGoods())->handle($return, $receipts);

    // And the delta property the far side depends on: a second parcel is another
    // two, never a corrected four.
    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, 1)]);

    expect($return->fresh()->lines[0]->quantity_received)->toBe(3);
});

it('resolves a host model from configuration at call time, against a class it has never seen', function () {
    Schema::create('fake_customers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('returns.customer_model', FakeCustomer::class);

    $customer = FakeCustomer::query()->create(['name' => 'A Shopper']);
    $return = ReturnRequest::factory()->create(['customer_id' => $customer->id]);

    expect($return->customer()->getRelated())->toBeInstanceOf(FakeCustomer::class)
        ->and($return->customer)->not->toBeNull()
        ->and($return->customer->name)->toBe('A Shopper');
});

it('throws rather than guessing when the host has named no model', function (string $relation, string $setting) {
    config()->set($setting, null);

    ReturnRequest::factory()->create()->{$relation}();
})->with([
    'the customer' => ['customer', 'returns.customer_model'],
    'the team' => ['team', 'returns.team_model'],
])->throws(RuntimeException::class);

it('ships no auto-registered provider, so installing boots nothing', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../module.json'), true);

    expect($composer['extra']['laravel']['providers'] ?? [])->toBe([])
        ->and($composer['version'])->toBe($manifest['version'])
        ->and($composer['extra']['liberu']['name'])->toBe($manifest['name'])
        ->and($manifest['category'])->toBe('product')
        ->and($manifest['name'])->toBe('ecommerce-returns');
});
