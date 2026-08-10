<?php

namespace Liberu\Ecommerce\Returns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Ecommerce\Returns\Enums\ReturnReason;
use Liberu\Ecommerce\Returns\Models\ReturnLine;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * @extends Factory<ReturnLine>
 */
class ReturnLineFactory extends Factory
{
    protected $model = ReturnLine::class;

    /**
     * `order_line_id` and `product_id` are ids **nothing in this database has
     * heard of**, and that is the assertion this fixture carries: no module that
     * owns orders or a catalogue is installed in this suite. `name` and `sku` are
     * copied labels, because a receiving desk has to know what is in the box and
     * this package cannot join anything to find out.
     *
     * The ids sit at 9,000,00x so they cannot collide with anything the test
     * user factory mints — a fixture id that collides makes an authorization test
     * pass for the wrong reason, and that failure mode is invisible in a green
     * suite.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'return_request_id' => ReturnRequest::factory(),
            'order_line_id' => 9000001,
            'product_id' => 9000002,
            'variant_id' => null,
            'sku' => 'GHOST-1',
            'name' => 'Merino Crew',
            'reason' => ReturnReason::Faulty,
            'note' => null,
            'returnable_quantity' => 5,
            'quantity_requested' => 1,
        ];
    }

    /** Built with `forceFill` — see `ReturnRequestFactory::newModel()`. */
    public function newModel(array $attributes = []): ReturnLine
    {
        return (new ReturnLine())->forceFill($attributes);
    }

    public function of(ReturnRequest $return): static
    {
        return $this->state(fn (): array => ['return_request_id' => $return->id]);
    }

    public function forOrderLine(int $orderLineId): static
    {
        return $this->state(fn (): array => ['order_line_id' => $orderLineId]);
    }

    public function requested(int $quantity): static
    {
        return $this->state(fn (): array => ['quantity_requested' => $quantity]);
    }

    /**
     * Quantities set directly, which no production path may do.
     *
     * `quantity_approved` and everything after it are not fillable: they move
     * through the actions, where the chain that keeps them honest lives. A test
     * that needs a line *at* a point in the workflow, rather than a test of how
     * it got there, uses this.
     */
    public function at(int $approved, int $received = 0, int $restockable = 0, int $rejected = 0): static
    {
        return $this->state(fn (): array => [
            'quantity_approved' => $approved,
            'quantity_received' => $received,
            'quantity_restockable' => $restockable,
            'quantity_rejected' => $rejected,
        ]);
    }
}
