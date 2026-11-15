<?php

declare(strict_types=1);

namespace Moolre\Exceptions;

/** Thrown by Webhook::construct(). reason is one of: malformed, mismatch, timestamp. */
class WebhookSignatureError extends MoolreException
{
    /** @var string */
    public $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
}
