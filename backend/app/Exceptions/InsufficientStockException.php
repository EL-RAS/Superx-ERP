<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown whenever a stock operation would push a batch's available quantity
 * below zero (sales, FEFO deductions, batch adjustments). Rendered as a 422
 * via bootstrap/app.php.
 */
class InsufficientStockException extends RuntimeException {}
