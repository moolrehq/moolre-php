<?php

declare(strict_types=1);

namespace Moolre\Api;

use Generator;
use Moolre\Result;
use Moolre\Webhook;
use Moolre\WebhookEvent;

/** Webhook deliveries, resend and test events. */
final class Webhooks extends Resource
{
    /**
     * Webhooks Moolre has sent (or is retrying), newest first. data = {items, has_more, next_cursor}.
     *
     * @param array{event?:string,state?:string,from?:string,to?:string,limit?:int,startingAfter?:string,includePayload?:bool,accountNumber?:string} $p
     */
    public function deliveries(array $p = []): Result
    {
        return $this->t->call('/open/webhook/deliveries', [
            'accountnumber' => $this->account($p), 'event' => $p['event'] ?? null, 'state' => $p['state'] ?? null,
            'from' => $p['from'] ?? null, 'to' => $p['to'] ?? null, 'limit' => $p['limit'] ?? null,
            'starting_after' => $p['startingAfter'] ?? null, 'include_payload' => !empty($p['includePayload']) ? 1 : null,
        ], ['needs' => ['apiKey'], 'retry' => 'read']);
    }

    /**
     * Walk all deliveries (follows data.next_cursor).
     *
     * @return Generator<int, array>
     */
    public function iterateDeliveries(array $p = [], array $page = []): Generator
    {
        $max = $page['maxItems'] ?? null;
        $after = $p['startingAfter'] ?? null;
        $seen = 0;
        while (true) {
            $r = $this->deliveries(['limit' => min((int) ($page['pageSize'] ?? 25), 100), 'startingAfter' => $after] + $p);
            $d = is_array($r->data) ? $r->data : [];
            foreach ($d['items'] ?? [] as $row) {
                if ($max !== null && $seen >= $max) {
                    return;
                }
                $seen++;
                yield $row;
            }
            if (empty($d['has_more']) || empty($d['next_cursor'])) {
                return;
            }
            $after = (string) $d['next_cursor'];
        }
    }

    /** Put an event back in the delivery queue (same event_id, full retry schedule). */
    public function resend(string $eventId, array $p = []): Result
    {
        return $this->t->call('/open/webhook/resend', ['accountnumber' => $this->account($p), 'event_id' => $eventId], ['needs' => ['apiKey']]);
    }

    /** Send a signed `webhook.test` event to the wallet's callback URL now. data = {event_id, delivered, http, error}. */
    public function test(array $p = []): Result
    {
        return $this->t->call('/open/webhook/test', ['accountnumber' => $this->account($p)], ['needs' => ['apiKey']]);
    }

    public function verify(string $rawBody, ?string $signatureHeader, string $secret, int $toleranceSec = 300): bool
    {
        return Webhook::verify($rawBody, $signatureHeader, $secret, $toleranceSec);
    }

    public function construct(string $rawBody, ?string $signatureHeader, string $secret, int $toleranceSec = 300): WebhookEvent
    {
        return Webhook::construct($rawBody, $signatureHeader, $secret, $toleranceSec);
    }
}
