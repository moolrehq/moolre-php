<?php

declare(strict_types=1);

namespace Moolre\Api;

use Generator;
use Moolre\Result;

final class Accounts extends Resource
{
    public function status(array $p = []): Result
    {
        return $this->t->call('/open/account/status', ['type' => 1, 'accountnumber' => $this->account($p)],
            ['needs' => ['apiKey'], 'retry' => 'read']);
    }

    /** @param array{accountName?:string,currency?:string,api?:bool,callback?:string,settlement?:array,accountNumber?:string} $p */
    public function update(array $p): Result
    {
        return $this->t->call('/open/account/update', [
            'type' => 1, 'accountnumber' => $this->account($p), 'accountname' => $p['accountName'] ?? null,
            'currency' => $p['currency'] ?? 'GHS', 'api' => $p['api'] ?? null, 'callback' => $p['callback'] ?? null,
            'settlement' => $p['settlement'] ?? null,
        ], ['needs' => ['apiKey']]);
    }

    /** Sandbox only: create a test wallet. */
    public function create(array $p): Result
    {
        $this->need($p, ['accountName', 'callback']);
        $cur = $p['currency'] ?? 'GHS';

        return $this->t->call('/open/account/create', [
            'type' => 1, 'accountname' => $p['accountName'], 'currency' => $cur, 'api' => $p['api'] ?? null,
            'callback' => $p['callback'],
            'settlement' => $p['settlement'] ?? ['currency' => $cur, 'frequency' => '', 'channel' => '', 'recipient' => '', 'sublist' => ''],
        ]);
    }

    /**
     * One page of transactions.
     *
     * @param array{startDate?:string,endDate?:string,status?:int,limit?:int,descending?:bool,startId?:mixed,endId?:mixed,accountNumber?:string} $p
     */
    public function transactions(array $p = []): Result
    {
        return $this->t->call('/open/account/status', [
            'type' => 2, 'accountnumber' => $this->account($p), 'startdate' => $p['startDate'] ?? null,
            'enddate' => $p['endDate'] ?? null, 'limit' => $p['limit'] ?? null, 'status' => $p['status'] ?? null,
            'descending' => isset($p['descending']) ? ($p['descending'] ? 1 : 0) : null,
            'startid' => $p['startId'] ?? null, 'endid' => $p['endId'] ?? null,
        ], ['needs' => ['apiKey'], 'retry' => 'read']);
    }

    /**
     * Iterate every matching transaction with the id cursor (startid/endid): pages never overlap.
     * Newest first unless ['descending' => false]. Options: pageSize (default 100, max 1000), maxItems.
     *
     * @return Generator<int, array>
     */
    public function iterateTransactions(array $p = [], array $page = []): Generator
    {
        $size = min(max((int) ($page['pageSize'] ?? 100), 2), 1000);
        $max = $page['maxItems'] ?? null;
        $desc = ($p['descending'] ?? true) !== false;
        $startId = isset($p['startId']) ? (int) $p['startId'] : null;
        $endId = isset($p['endId']) ? (int) $p['endId'] : null;
        $seen = 0;

        while (true) {
            $r = $this->transactions(['descending' => $desc, 'limit' => $size, 'startId' => $startId, 'endId' => $endId] + $p);
            $rows = is_array($r->data) && isset($r->data['transactions']) && is_array($r->data['transactions']) ? $r->data['transactions'] : [];
            foreach ($rows as $row) {
                if ($max !== null && $seen >= $max) {
                    return;
                }
                $seen++;
                yield $row;
            }
            if (count($rows) < $size) {
                return;
            }
            $ids = [];
            foreach ($rows as $row) {
                if (isset($row['transactionid']) && ctype_digit((string) $row['transactionid'])) {
                    $ids[] = (int) $row['transactionid'];
                }
            }
            if ($ids === []) {
                return;
            }
            if ($desc) {
                $endId = min($ids) - 1;
                if ($endId <= 1) { // the API needs endid > 1
                    return;
                }
            } else {
                $startId = max($ids) + 1;
            }
        }
    }
}
