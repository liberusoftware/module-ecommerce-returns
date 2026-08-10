<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One order line coming back, and the five different numbers that are all
 * "how many".
 *
 * **`order_line_id` is the identifier this whole module is built on**, and it is
 * a plain indexed column with no foreign key and no relation. Whoever owns order
 * lines publishes that id as stable and public — never deleted, never replaced —
 * and holding it is the entire integration. A foreign key here would make this
 * package uninstallable without that one, and an Eloquent relation would import
 * its table name, its casts and its scopes, every one of which becomes a breaking
 * change the day it moves.
 *
 * `name`, `sku`, `product_id` and `variant_id` are **copied labels**, not
 * lookups. A receiving desk has to know what is in the box without this package
 * being able to join anything, and the labels have to keep meaning something
 * after the product they name has been renamed or deleted.
 *
 * **Five quantities, because requested and received are different facts and will
 * disagree.**
 *
 *     returnable_quantity  — what the caller was told was still returnable
 *     quantity_requested   — what the shopper asked to send back
 *     quantity_approved    — what the merchant authorised
 *     quantity_received    — what physically arrived
 *     quantity_restockable — what inspection said is saleable again
 *     quantity_rejected    — what inspection said is not
 *
 * `returnable_quantity` is **evidence, not a constraint this module can check.**
 * Eligibility is arithmetic over what was delivered and what has already come
 * back, and this package holds neither — it is an input, the same way tax is an
 * input, handed in by whoever asked. Storing it records what the caller was told
 * at the moment the shopper asked, which is what an argument three months later
 * is actually about.
 *
 * The chain is refused rather than clamped at every link, in `Actions`:
 *
 *     requested ≤ returnable, approved ≤ requested, received ≤ approved,
 *     restockable + rejected ≤ received
 *
 * A **short receipt is not an error**: three arriving against five approved is
 * what couriers and shoppers do, and the two that never came are simply never
 * resolvable. Arriving with *more* is refused, because that is what a mistake
 * looks like.
 *
 * `note` is the one free-text field in this package, and it is contained by rule
 * rather than by hope — see `Models\ReturnLine`.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_returns_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained('ecommerce_returns_requests')->cascadeOnDelete();

            $table->unsignedBigInteger('order_line_id')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->string('sku')->nullable();
            $table->string('name');

            // A closed set of slugs. A merchant groups by this to find the batch
            // that is faulty and the description that is wrong, and nobody types
            // the same sentence twice.
            $table->string('reason', 64);
            $table->text('note')->nullable();

            $table->unsignedInteger('returnable_quantity')->default(0);
            $table->unsignedInteger('quantity_requested')->default(1);
            $table->unsignedInteger('quantity_approved')->default(0);
            $table->unsignedInteger('quantity_received')->default(0);
            $table->unsignedInteger('quantity_restockable')->default(0);
            $table->unsignedInteger('quantity_rejected')->default(0);

            $table->timestamps();

            // One return may not list the same order line twice. Two rows for
            // one line would make every quantity on this table ambiguous, and
            // the ambiguity would first show up as a refund.
            $table->unique(['return_request_id', 'order_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_returns_lines');
    }
};
