<?php

declare(strict_types=1);

namespace Moolre\Api;

use Generator;
use Moolre\Result;
use Moolre\Transport;

/** Bulk payouts: up to 500 payouts per batch, sent by a background worker. */
final class Bulk extends Resource
{
    /**
     * Submit 1 to 500 payouts. Each item: externalRef, receiver, channel (MTN|TELECEL|AT|BANK|GHQR), amount,
     * subListId? (BANK), senderName? (1-40 chars), reference?. The result's data holds batchid, batchref,
     * accepted, rejected[{index,code,message,externalref}], total_amount, status "queued", balance_warning?.
     * The same batchRef with the same body replays the batch (code BLK02, replayed true).
     *
     * @param array{currency:string,batchRef:string,items:array,callback?:string,atomic?:bool,accountNumber?:string} $p
     * @param array{idempotencyKey?:string} $o
     */
    public function create(array $p, array $o = []): Result
    {
        $this->need($p, ['currency', 'batchRef', 'items']);
        $items = [];
        foreach ($p['items'] as $i) {
            $items[] = Transport::clean([
                'externalref' => $i['externalRef'] ?? null, 'receiver' => $i['receiver'] ?? null,
                'channel' => isset($i['channel']) ? Transport::channel($i['channel']) : null,
                'amount' => isset($i['amount']) ? Transport::amount($i['amount']) : null,
                'sublistid' => $i['subListId'] ?? null, 'sendername' => $i['senderName'] ?? null,
                'reference' => $i['reference'] ?? null,
            ]);
        }

        return $this->t->call('/open/transact/bulk', [
            'type' => 1, 'accountnumber' => $this->account($p), 'currency' => $p['currency'],
            'batchref' => $p['batchRef'], 'callback' => $p['callback'] ?? null,
            'atomic' => $p['atomic'] ?? null, 'items' => $items,
        ], ['needs' => ['apiKey'], 'retry' => 'idempotent', 'idempotencyKey' => $this->key($o)]);
    }

    /**
     * Batch summary plus one page of items. Use iterateItems() to walk them all.
     *
     * @param array{batchId?:string,batchRef?:string,status?:string,after?:int,limit?:int,accountNumber?:string} $p
     */
    public function status(array $p): Result
    {
        return $this->t->call('/open/transact/bulk_status', [
            'accountnumber' => $this->account($p), 'batchid' => $p['batchId'] ?? null,
            'batchref' => $p['batchRef'] ?? null, 'status' => $p['status'] ?? null,
            'after' => $p['after'] ?? null, 'limit' => $p['limit'] ?? null,
        ], ['needs' => ['apiKey'], 'retry' => 'read']);
    }

    /**
     * Walk every item of a batch (follows data.next_after).
     *
     * @return Generator<int, array>
     */
    public function iterateItems(array $p, array $page = []): Generator
    {
        $size = min(max((int) ($page['pageSize'] ?? 100), 1), 500);
        $max = $page['maxItems'] ?? null;
        $after = null;
        $seen = 0;
        while (true) {
            $r = $this->status(['after' => $after, 'limit' => $size] + $p);
            $d = is_array($r->data) ? $r->data : [];
            foreach ($d['items'] ?? [] as $item) {
                if ($max !== null && $seen >= $max) {
                    return;
                }
                $seen++;
                yield $item;
            }
            if (!isset($d['next_after'])) {
                return;
            }
            $after = (int) $d['next_after'];
        }
    }

    /**
     * Recent batches, newest first. data = {batches, has_more, next_cursor}.
     *
     * @param array{limit?:int,startingAfter?:string,status?:string,accountNumber?:string} $p
     */
    public function list(array $p = []): Result
    {
        return $this->t->call('/open/transact/bulk_list', [
            'accountnumber' => $this->account($p), 'limit' => $p['limit'] ?? null,
            'starting_after' => $p['startingAfter'] ?? null, 'status' => $p['status'] ?? null,
        ], ['needs' => ['apiKey'], 'retry' => 'read']);
    }

    /** @return Generator<int, array> */
    public function iterateBatches(array $p = [], array $page = []): Generator
    {
        $max = $page['maxItems'] ?? null;
        $after = null;
        $seen = 0;
        while (true) {
            $r = $this->list(['limit' => min((int) ($page['pageSize'] ?? 25), 100), 'startingAfter' => $after] + $p);
            $d = is_array($r->data) ? $r->data : [];
            foreach ($d['batches'] ?? [] as $b) {
                if ($max !== null && $seen >= $max) {
                    return;
                }
                $seen++;
                yield $b;
            }
            if (empty($d['has_more']) || empty($d['next_cursor'])) {
                return;
            }
            $after = (string) $d['next_cursor'];
        }
    }
}
