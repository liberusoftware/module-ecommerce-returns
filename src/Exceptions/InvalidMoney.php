<?php

namespace Liberu\Ecommerce\Returns\Exceptions;

use InvalidArgumentException;

/**
 * An amount this module will not guess at — a malformed decimal string, a
 * negative exponent, or a value more precise than its currency can hold.
 *
 * Refusing rather than rounding is the point. `"19.995"` in GBP needs a rounding
 * rule — half up, half even, towards the merchant — and picking one silently on
 * somebody's refund is not this module's decision to make.
 */
final class InvalidMoney extends InvalidArgumentException {}
