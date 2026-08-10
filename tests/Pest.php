<?php

declare(strict_types=1);

use Liberu\Ecommerce\Returns\Actions\ApproveReturn;
use Liberu\Ecommerce\Returns\Actions\ReceiveGoods;
use Liberu\Ecommerce\Returns\Actions\RequestReturn;
use Liberu\Ecommerce\Returns\Data\LineReceipt;
use Liberu\Ecommerce\Returns\Data\ReturnLineInput;
use Liberu\Ecommerce\Returns\Data\ReturnRequestInput;
use Liberu\Ecommerce\Returns\Enums\ReturnReason;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;
use Liberu\PackageTestbench\PackageTestCase;
use Liberu\PackageTestbench\UsesTestUser;

/*
 * `UsesTestUser` brings `RefreshDatabase` with it, and both halves are wanted:
 * the policy reads `current_team_id` off a real actor, and the migrations this
 * package's provider loads need a database to run against.
 */
uses(PackageTestCase::class, UsesTestUser::class)->in(__DIR__);

/**
 * Ids for things that live in another module, and are not installed here.
 *
 * Every one is at 9,000,00x, for two reasons that happen to want the same
 * numbers. Nothing in this database has ever heard of an order line — no module
 * that owns one is installed in this suite and none ever will be — so a plausible
 * small integer would be a fixture pretending to be a foreign key. And a fixture
 * id that collides with one the test user factory mints makes an authorization
 * test pass for the wrong reason, because a stranger's record quietly becomes the
 * actor's own.
 */
const GHOST_ORDER = 9000100;
const GHOST_LINE = 9000001;
const GHOST_LINE_TWO = 9000002;

/**
 * One line a shopper wants to send back, described rather than fetched.
 *
 * `returnableQuantity` defaults generously because it is an **input**: this
 * module cannot compute eligibility and refuses to try, so every test hands in
 * the number a caller would have read from whoever owns the order line.
 */
function returnLine(
    int $orderLineId = GHOST_LINE,
    int $quantity = 1,
    int $returnableQuantity = 5,
    ReturnReason $reason = ReturnReason::Faulty,
    ?string $note = null,
    string $name = 'Merino Crew',
): ReturnLineInput {
    return new ReturnLineInput(
        orderLineId: $orderLineId,
        quantity: $quantity,
        reason: $reason,
        returnableQuantity: $returnableQuantity,
        name: $name,
        sku: 'GHOST-1',
        productId: 9000500,
        note: $note,
    );
}

/**
 * A whole request, over an order id nothing in this database has heard of.
 *
 * @param  list<ReturnLineInput>|null  $lines
 */
function returnInput(?array $lines = null, int $orderId = GHOST_ORDER, ?int $teamId = null, ?int $customerId = null): ReturnRequestInput
{
    return new ReturnRequestInput(
        orderId: $orderId,
        lines: $lines ?? [returnLine()],
        currency: 'GBP',
        teamId: $teamId,
        customerId: $customerId,
    );
}

/**
 * A return that exists, made the way production makes one.
 *
 * @param  list<ReturnLineInput>|null  $lines
 */
function aReturn(?array $lines = null, int $orderId = GHOST_ORDER, ?int $teamId = null): ReturnRequest
{
    return (new RequestReturn())->handle(returnInput($lines, $orderId, $teamId));
}

/**
 * A return that has been authorised for everything it asked for.
 *
 * The state most of this suite starts from, because it is the only state goods
 * may arrive in.
 */
function anApprovedReturn(int $quantity = 3, int $orderLineId = GHOST_LINE): ReturnRequest
{
    $return = aReturn([returnLine(orderLineId: $orderLineId, quantity: $quantity, returnableQuantity: $quantity)]);

    (new ApproveReturn())->handle($return);

    return $return;
}

/**
 * A return whose goods have physically arrived, in full.
 *
 * The only state an inspection or a refund may start from — the symmetric
 * invariant, as a fixture.
 */
function aReceivedReturn(int $quantity = 3): ReturnRequest
{
    $return = anApprovedReturn($quantity);

    (new ReceiveGoods())->handle($return, [new LineReceipt(GHOST_LINE, $quantity)]);

    return $return;
}
