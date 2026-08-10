<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every move the state machine made, append-only.
 *
 * The status column says where a return is; this says how it got there, when, and
 * who did it. That is the question actually asked — by a shopper wanting to know
 * when their return was authorised, and by whoever is working out why a return
 * was refused after the goods had already been posted.
 *
 * A refused move writes **nothing** here. An attempt the state machine turned
 * down is not a transition that happened, and a history containing refusals
 * answers a different question from the one it is kept for. Self-transitions are
 * refused for the same reason: a no-op would file a row against a move nobody
 * made.
 *
 * `from_status` is null for exactly one row per return — the request itself,
 * which came from nowhere.
 *
 * `actor_id` is a plain nullable column with no foreign key: the actor is the
 * host's user model, or nobody at all when a sweep expired the return. `reason`
 * is a **short slug**, not free text, and the domain event logger copies it — a
 * text box next to an event logger is where a shopper's email address gets typed
 * into a log line. Sixty-four characters, enforced by the column.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_returns_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained('ecommerce_returns_requests')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('reason', 64)->nullable();
            $table->timestamps();

            $table->index(['return_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_returns_status_changes');
    }
};
