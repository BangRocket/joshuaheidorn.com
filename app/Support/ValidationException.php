<?php

declare(strict_types=1);

namespace App\Support;

/** Thrown when request data fails validation; surfaced to the client as HTTP 400. */
final class ValidationException extends \RuntimeException
{
}
