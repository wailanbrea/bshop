<?php

namespace App\Domain\Media;

use RuntimeException;

final class ImageRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
