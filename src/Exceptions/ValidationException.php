<?php

declare(strict_types=1);

namespace Moolre\Exceptions;

final class ValidationException extends MoolreException
{
    public static function forField(string $field, string $message): self
    {
        return new self(sprintf('%s: %s', $field, $message));
    }
}
