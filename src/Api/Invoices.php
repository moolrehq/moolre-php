<?php

declare(strict_types=1);

namespace Moolre\Api;

use Generator;
use Moolre\Result;
use Moolre\Transport;

/** Invoices: the same invoices the dashboard shows. */
final class Invoices extends Resource
{
    /**
     * Create an invoice; give the customer $result->data['payment_link']. The same reference with the same body
     * replays the original (code INV02, replayed true).
     *
     * @param array{currency:string,invoiceName:string,customer:array{name:string,phone?:string,email?:string},dueDate:string,amount?:mixed,items?:array,tax?:int|float,narration?:string,reference?:string,metadata?:array,serviceId?:string,accountNumber?:string} $p
     * @param array{idempotencyKey?:string} $o
     */
    public function create(array $p, array $o = []): Result
    {
        $this->need($p, ['currency', 'invoiceName', 'customer', 'dueDate']);
        $lines = null;
        if (isset($p['items'])) {
            $lines = [];
            foreach ($p['items'] as $i) {
                $lines[] = Transport::clean([
                    'description' => $i['description'] ?? null,
                    'quantity' => $i['quantity'] ?? null,
                    'amount' => isset($i['amount']) ? Transport::amount($i['amount']) : null,
                ]);
            }
        }

        return $this->t->call('/open/invoice/create', [
            'accountnumber' => $this->account($p), 'currency' => $p['currency'], 'service_id' => $p['serviceId'] ?? null,
            'invoice_name' => $p['invoiceName'], 'customer' => $p['customer'],
            'amount' => isset($p['amount']) ? Transport::amount($p['amount']) : null, 'items' => $lines,
            'tax' => $p['tax'] ?? null, 'due_date' => $p['dueDate'], 'narration' => $p['narration'] ?? null,
            'reference' => $p['reference'] ?? null, 'metadata' => $p['metadata'] ?? null,
        ], ['needs' => ['apiKey'], 'retry' => 'idempotent', 'idempotencyKey' => $this->key($o)]);
    }

    /** By ['invoiceId' => '5-1'] or ['reference' => '...']. */
    public function status(array $p): Result
    {
        return $this->t->call('/open/invoice/status', [
            'accountnumber' => $this->account($p), 'invoiceid' => $p['invoiceId'] ?? null, 'reference' => $p['reference'] ?? null,
        ], ['needs' => ['apiKey'], 'retry' => 'read']);
    }

    /** data = {invoices, has_more, next_cursor}, newest first. */
    public function list(array $p = []): Result
    {
        return $this->t->call('/open/invoice/list', [
            'accountnumber' => $this->account($p), 'limit' => $p['limit'] ?? null,
            'starting_after' => $p['startingAfter'] ?? null, 'status' => $p['status'] ?? null,
            'reference' => $p['reference'] ?? null, 'service_id' => $p['serviceId'] ?? null,
        ], ['needs' => ['apiKey'], 'retry' => 'read']);
    }

    /** @return Generator<int, array> */
    public function iterate(array $p = [], array $page = []): Generator
    {
        $max = $page['maxItems'] ?? null;
        $after = null;
        $seen = 0;
        while (true) {
            $r = $this->list(['limit' => min((int) ($page['pageSize'] ?? 25), 100), 'startingAfter' => $after] + $p);
            $d = is_array($r->data) ? $r->data : [];
            foreach ($d['invoices'] ?? [] as $inv) {
                if ($max !== null && $seen >= $max) {
                    return;
                }
                $seen++;
                yield $inv;
            }
            if (empty($d['has_more']) || empty($d['next_cursor'])) {
                return;
            }
            $after = (string) $d['next_cursor'];
        }
    }

    /** Cancel an unpaid invoice (its hosted link stops working). INV18 if it has payments. */
    public function cancel(string $invoiceId, array $p = []): Result
    {
        return $this->t->call('/open/invoice/cancel', ['accountnumber' => $this->account($p), 'invoiceid' => $invoiceId], ['needs' => ['apiKey']]);
    }
}
