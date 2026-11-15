<?php

declare(strict_types=1);

namespace Moolre\Exceptions;

/** No usable HTTP response (DNS, TLS, reset, timeout). */
class NetworkError extends MoolreException
{
}
