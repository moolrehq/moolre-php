<?php

declare(strict_types=1);

namespace Moolre\Exceptions;

/** IDEM01 or HTTP 409: Idempotency-Key reused with a different request. */
class IdempotencyError extends ApiError
{
}
