<?php

declare(strict_types=1);

namespace App\Tacta;

// Center suit. Used only by alternative play modes (deferred); stored for completeness.
enum Suit: string
{
    case Circle = 'circle';
    case Square = 'square';
    case Triangle = 'triangle';
}
