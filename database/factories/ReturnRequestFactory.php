<?php

namespace Liberu\Ecommerce\Returns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Models\ReturnRequest;

/**
 * @extends Factory<ReturnRequest>
 */
class ReturnRequestFactory extends Factory
{
    protected $model = ReturnRequest::class;

    /**
     * The default `order_id` is a number nothing in this database has heard of.
     * No module that owns orders is installed in this suite and never will be —
     * what crosses the boundary is an identifier, and it has to work with nothing
     * behind it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => ReturnRequest::generateNumber(),
            'team_id' => null,
            'store_id' => null,
            'customer_id' => null,
            'order_id' => 9000100,
            'currency' => 'GBP',
            'currency_exponent' => 2,
            'requested_at' => now(),
        ];
    }

    /**
     * Built with `forceFill`, so a factory state may set a guarded attribute.
     *
     * The status and every quantity after `quantity_requested` are deliberately
     * absent from `$fillable` — they move through the actions, where the guards
     * live. Eloquent's default factory construction goes through `fill()`, which
     * would silently *drop* those attributes rather than complain, and a fixture
     * that quietly ignores the state it was asked for is a green test asserting
     * the wrong thing.
     */
    public function newModel(array $attributes = []): ReturnRequest
    {
        return (new ReturnRequest())->forceFill($attributes);
    }

    public function ownedBy(int $teamId): static
    {
        return $this->state(fn (): array => ['team_id' => $teamId]);
    }

    public function forOrder(int $orderId): static
    {
        return $this->state(fn (): array => ['order_id' => $orderId]);
    }

    /**
     * A status set directly, which no production path may do.
     *
     * Only a factory is allowed this: every real move goes through
     * `Actions\TransitionReturn` and its transition table. A test that needs a
     * return *in* a state, rather than a test of how it got there, uses this and
     * says nothing about legality.
     */
    public function status(ReturnStatus $status): static
    {
        return $this->state(function () use ($status): array {
            $stamp = $status->timestampColumn();

            return $stamp === null ? ['status' => $status] : ['status' => $status, $stamp => now()];
        });
    }

    public function approved(): static
    {
        return $this->status(ReturnStatus::Approved);
    }

    public function received(): static
    {
        return $this->status(ReturnStatus::Received);
    }

    public function inspected(): static
    {
        return $this->status(ReturnStatus::Inspected);
    }
}
