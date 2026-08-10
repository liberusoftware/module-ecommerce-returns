<?php

namespace Liberu\Ecommerce\Returns\Telemetry;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\Returns\Data\LineDisposition;
use Liberu\Ecommerce\Returns\Data\LineReceipt;
use Liberu\Ecommerce\Returns\Data\ReturnLineData;
use Liberu\Ecommerce\Returns\Enums\ReturnStatus;
use Liberu\Ecommerce\Returns\Events\RefundRecorded;
use Liberu\Ecommerce\Returns\Events\ReturnGoodsReceived;
use Liberu\Ecommerce\Returns\Events\ReturnInspected;
use Liberu\Ecommerce\Returns\Events\ReturnRequested;
use Liberu\Ecommerce\Returns\Events\ReturnTransitioned;

/**
 * This module's own domain events, written as structured records.
 *
 * A listener, not an instrumentation layer: no metrics client, no tracer, no
 * second logging stack. What it adds is the vocabulary a foundation cannot
 * supply — an application's log has no idea that a spike in `not_as_described`
 * against one product is a bad photograph, or that approved returns expiring
 * without goods is a broken returns label.
 *
 * **Off by default.** A busy storefront writes thousands of these a week, and a
 * package that starts filling a deployment's log the moment it installs has
 * decided somebody else's retention bill.
 *
 * **What is never written here:** no shopper name, no address, no email, and
 * above all **no `note`**. That field is the one place in this package a person
 * types a sentence, and a log is the store in an application with the loosest
 * access control and the longest reach. `reason` is a slug from a closed set for
 * exactly the same reason, and a test asserts a note never reaches a log line.
 *
 * Levels carry meaning so an alert needs no message parsing: a refusal, a
 * cancellation and an expiry are `warning`; a rejected disposition is `warning`,
 * because goods arriving unsaleable is the signal a merchant wants; everything
 * else is `info`.
 */
final class DomainEventLogger
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ReturnRequested::class => 'onReturnRequested',
            ReturnTransitioned::class => 'onReturnTransitioned',
            ReturnGoodsReceived::class => 'onReturnGoodsReceived',
            ReturnInspected::class => 'onReturnInspected',
            RefundRecorded::class => 'onRefundRecorded',
        ];
    }

    public function onReturnRequested(ReturnRequested $event): void
    {
        $this->record('return.requested', [
            'return_id' => $event->return->id,
            'number' => $event->return->number,
            'order_id' => $event->return->orderId,
            'team_id' => $event->return->teamId,
            'store_id' => $event->return->storeId,
            'lines' => count($event->return->lines),
            // Slugs and counts. The shopper's own words stay on the model.
            'reasons' => array_values(array_unique(array_map(
                fn (ReturnLineData $line): string => $line->reason->value,
                $event->return->lines,
            ))),
        ]);
    }

    public function onReturnTransitioned(ReturnTransitioned $event): void
    {
        $loud = in_array($event->to, [ReturnStatus::Refused, ReturnStatus::Cancelled, ReturnStatus::Expired], true);

        $this->record('return.transitioned', [
            'return_id' => $event->return->id,
            'number' => $event->return->number,
            'order_id' => $event->return->orderId,
            'team_id' => $event->return->teamId,
            'from' => $event->from->value,
            'to' => $event->to->value,
            'reason' => $event->reason,
            'actor_id' => $event->actorId,
        ], $loud ? 'warning' : 'info');
    }

    public function onReturnGoodsReceived(ReturnGoodsReceived $event): void
    {
        $this->record('return.goods_received', [
            'return_id' => $event->return->id,
            'number' => $event->return->number,
            'order_id' => $event->return->orderId,
            'quantity' => $event->quantity(),
            'order_line_ids' => array_map(fn (LineReceipt $receipt): int => $receipt->orderLineId, $event->receipts),
            'received_total' => $event->return->receivedQuantity(),
        ]);
    }

    public function onReturnInspected(ReturnInspected $event): void
    {
        $this->record('return.inspected', [
            'return_id' => $event->return->id,
            'number' => $event->return->number,
            'order_id' => $event->return->orderId,
            'restockable' => $event->restockableQuantity(),
            'rejected' => $event->rejectedQuantity(),
            'order_line_ids' => array_map(fn (LineDisposition $disposition): int => $disposition->orderLineId, $event->dispositions),
        ], $event->rejectedQuantity() > 0 ? 'warning' : 'info');
    }

    public function onRefundRecorded(RefundRecorded $event): void
    {
        $this->record('return.refund_recorded', [
            'return_id' => $event->return->id,
            'number' => $event->return->number,
            'order_id' => $event->return->orderId,
            'refund_id' => $event->refund->id,
            'kind' => $event->refund->kind->value,
            'amount_minor' => $event->refund->amountMinor,
            'currency' => $event->refund->currency,
            'refunded_total_minor' => $event->return->refundedMinor,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $event, array $context, string $level = 'info'): void
    {
        if (config('returns.telemetry.enabled') !== true) {
            return;
        }

        $channel = config('returns.telemetry.channel');

        $logger = is_string($channel) && $channel !== '' ? Log::channel($channel) : Log::channel();

        $logger->log($level, $event, $context);
    }
}
