<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A refund is an amount and a reference. It is not a payment.**
 *
 * This is the table where a returns module usually goes wrong, so the shape says
 * what it is not:
 *
 * - **No gateway call and no provider name.** `src/` names none, and a boundary
 *   test greps for five of them. Money movement belongs to whoever owns the
 *   tender; the host makes the call and hands the resulting `reference` here.
 * - **No status.** The host's `refunds` table has `pending / approved / rejected
 *   / processed`, which is a second, diverging copy of a state machine somebody
 *   else's system already owns. A row here means *this went back*. If it did not
 *   go back, there is no row.
 * - **No maintained balance.** No `refund_total`, no `fully_refunded`, no
 *   `partially_refunded`. This module holds no line prices — the money on an
 *   order line is frozen in the module that owns it — so it cannot know what a
 *   full refund would be, and a column claiming to know would be wrong the first
 *   time shipping was refunded separately. A sum over these rows is the answer,
 *   and it is computed where it is asked for.
 * - **No `restock_items` flag.** A returned unit is not automatically saleable.
 *   Inspection says what is restockable and the event says so; writing stock is
 *   Inventory Ledger's.
 *
 * Append-only, and several rows per return are normal: goods refunded on receipt
 * and the shipping refunded after a conversation is two decisions, not one
 * mutable number.
 *
 * **Tax is an input.** It arrives as `tax_rate_bp` — the rate the order was
 * priced at — or as an already-computed `tax_minor`, and nothing here looks a
 * rate up, knows a jurisdiction or compounds. Both columns are nullable because
 * a store credit frequently carries neither.
 *
 * Money is an integer count of minor units, named `*_minor` so a `decimal`
 * slipping back in is visible in a diff. The host's `refunds.amount` is
 * `decimal(10,2)`; `SchemaTest` asserts no column anywhere in this module is
 * decimal, float or numeric.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_returns_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained('ecommerce_returns_requests')->cascadeOnDelete();

            // `tender`, `store_credit`, `exchange` or `manual` — what it was,
            // never who moved it. A store credit is a liability the merchant
            // still owes and a tender refund is cash that has left; a report that
            // cannot tell them apart is useless.
            $table->string('kind')->default('tender');

            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedTinyInteger('currency_exponent')->default(2);
            // Basis points, so 20% VAT is 2000 and 8.375% is 838 — representable
            // without a float. Null means an amount was handed in instead, which
            // is equally allowed.
            $table->unsignedInteger('tax_rate_bp')->nullable();
            $table->bigInteger('tax_minor')->nullable();

            // Whatever the host calls this money movement: a credit note number,
            // a ledger entry, a gateway's own id. **An opaque string.** This
            // package never parses it, never validates it and never calls
            // anything with it — it is here so the row can be reconciled against
            // the system that actually moved the money.
            $table->string('reference')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['return_request_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_returns_refunds');
    }
};
