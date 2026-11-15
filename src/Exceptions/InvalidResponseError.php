<?php

declare(strict_types=1);

namespace Moolre\Exceptions;

/** The HTTP response was not Moolre JSON (for example an HTML 502 page). */
class InvalidResponseError extends MoolreException
{
}
