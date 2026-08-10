<?php

namespace Liberu\Ecommerce\Returns\Data;

/**
 * This module's own input shape — what a shopper asked to send back.
 *
 * Published as a plain readonly value so nothing has to import a model, a form
 * request or a framework binding to make a return. A surface builds one of these;
 * so does a queued job; so does a console command replaying a support ticket.
 *
 * Everything here is an **identifier or a value already resolved**: an order id, a
 * list of order line ids, a currency code, quantities. Nothing on it can only be
 * obtained by installing another package.
 *
 * `currency` is the currency the order was agreed in, carried so a refund
 * recorded later can be checked against it. This module converts nothing.
 */
final readonly class ReturnRequestInput
{
    /**
     * @param  list<ReturnLineInput>  $lines
     */
    public function __construct(
        public int $orderId,
        public array $lines,
        public string $currency,
        public int $currencyExponent = 2,
        public ?int $teamId = null,
        public ?int $storeId = null,
        public ?int $customerId = null,
        public ?string $requestedAt = null,
    ) {}
}
