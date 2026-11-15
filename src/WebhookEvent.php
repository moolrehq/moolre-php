<?php

declare(strict_types=1);

namespace Moolre;

/** A parsed webhook payload. */
final class WebhookEvent
{
    /** @var string|null "evt_" + 24 hex, stable across retries: dedupe on this (null on legacy payloads) */
    public $eventId;
    /** @var string|null payment.succeeded | payment.failed | payout.succeeded | payout.failed | payment_link.expired | bulk_payout.completed | invoice.paid | invoice.expired | webhook.test */
    public $event;
    /** @var string|null ISO 8601 UTC with Z */
    public $created;
    /** @var int|string|null */
    public $status;
    /** @var string|null */
    public $code;
    /** @var mixed */
    public $message;
    /** @var mixed */
    public $data;
    /** @var array[] */
    public $notices;
    /** @var array */
    public $raw;

    public function __construct(array $raw)
    {
        $this->raw = $raw;
        $this->eventId = isset($raw['event_id']) ? (string) $raw['event_id'] : null;
        $this->event = isset($raw['event']) ? (string) $raw['event'] : null;
        $this->created = isset($raw['created']) ? (string) $raw['created'] : null;
        $this->status = $raw['status'] ?? null;
        $this->code = isset($raw['code']) ? (string) $raw['code'] : null;
        $this->message = $raw['message'] ?? null;
        $this->data = $raw['data'] ?? null;
        $this->notices = isset($raw['notices']) && is_array($raw['notices']) ? $raw['notices'] : [];
    }
}
