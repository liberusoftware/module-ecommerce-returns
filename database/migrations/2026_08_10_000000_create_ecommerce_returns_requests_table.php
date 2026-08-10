<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The return request: a workflow with goods in it.
 *
 * Every table this module owns is invented here, so every one carries the
 * `ecommerce_returns_` prefix (MODULE_DEVELOPMENT.md §1.5). There is no bare-name
 * exception: the host's `return_requests` and `refunds` tables are not this
 * module's to keep — between them they hold a `decimal(10,2)` amount, a payment
 * gateway's transaction id, a `restock_items` boolean and four foreign keys into
 * tables this package does not own. `docs/adoption.md` lists every column and
 * where it went.
 *
 * **`order_id` is an identifier and nothing else.** No foreign key, no relation,
 * no `use` of anything that owns orders. This package requires no sibling
 * commerce package and its whole suite runs with none installed, over order line
 * ids nothing in the database has heard of. What crosses the boundary is a
 * number.
 *
 * **`number` is the public reference**, not `id`. An incrementing id in a
 * customer-facing URL or a support email is an enumeration of everybody's
 * returns, which is the same argument that gave an order its number.
 *
 * **Eight timestamps, because a return has more than one clock.** Requested,
 * approved or refused, goods received, inspected, resolved — and `expired`, for
 * the case this domain is defined by: a request made, approved, and then the
 * parcel never comes. `goods_due_by` is what a sweep reads to find those, and it
 * is set at approval from a window the caller supplies; this module holds no
 * merchant policy about how long a shopper gets.
 *
 * There is deliberately no carrier, label or tracking column here. Getting a
 * parcel from a shopper to a warehouse is a shipment, and a shipment is somebody
 * else's table — one return can come back in two parcels on two carriers, so a
 * carrier column can only hold the first answer and then be wrong.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_returns_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();

            $table->foreignId('team_id')->nullable()->index();
            $table->foreignId('store_id')->nullable()->index();
            $table->foreignId('customer_id')->nullable()->index();

            // The order these goods came from. A plain indexed column: whoever
            // owns orders owns that table, and a package that constrains a table
            // it does not own cannot be installed without it.
            $table->unsignedBigInteger('order_id')->index();

            $table->string('status')->default('requested')->index();

            // The currency the order was agreed in. A refund recorded against
            // this return has to match it — this module holds no rates and
            // converts nothing.
            $table->string('currency', 3);
            $table->unsignedTinyInteger('currency_exponent')->default(2);

            // When the authorisation runs out. Set at approval from a window the
            // caller supplies, and null until then. Nothing here runs on a timer;
            // `ReturnQuery::awaitingGoodsSince()` is what the host's schedule
            // reads, and expiring is a decision the host makes.
            $table->timestamp('goods_due_by')->nullable();

            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // The three reads a staff surface actually performs.
            $table->index(['team_id', 'status']);
            $table->index(['order_id', 'status']);
            $table->index(['status', 'goods_due_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_returns_requests');
    }
};
