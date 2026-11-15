<?php

declare(strict_types=1);

namespace Moolre\Api;

use Moolre\Result;

/** SMS (needs vasKey). */
final class Sms extends Resource
{
    /**
     * @param array{senderId:string,messages:array<int,array{recipient:string,message:string,ref?:string}>,network?:string} $p
     */
    public function send(array $p): Result
    {
        $this->need($p, ['senderId', 'messages']);

        return $this->t->call('/open/sms/send', [
            'type' => 1, 'senderid' => $p['senderId'], 'network' => $p['network'] ?? null, 'messages' => $p['messages'],
        ], ['needs' => ['vasKey']]);
    }

    /** Delivery status by message ref: 0 unknown, 1 sent, 2 delivered, 3 failed. */
    public function status(array $refs): Result
    {
        return $this->t->call('/open/sms/status', ['type' => 5, 'ref' => array_values($refs)], ['needs' => ['vasKey'], 'retry' => 'read']);
    }

    public function balance(): Result
    {
        return $this->t->call('/open/sms/status', ['type' => 2], ['needs' => ['vasKey'], 'retry' => 'read']);
    }

    public function senderIdStatus(string $senderId): Result
    {
        return $this->t->call('/open/sms/status', ['type' => 1, 'senderid' => $senderId], ['needs' => ['vasKey'], 'retry' => 'read']);
    }

    public function listSenderIds(): Result
    {
        return $this->t->call('/open/sms/status', ['type' => 7], ['needs' => ['vasKey'], 'retry' => 'read']);
    }

    /** @param array<int,array{senderid:string,approve?:bool}> $senderIds */
    public function createSenderIds(array $senderIds): Result
    {
        return $this->t->call('/open/sms/query', ['type' => 3, 'senderids' => $senderIds], ['needs' => ['vasKey']]);
    }

    /** Reseller permission required. approve: 0 pending, 1 approved, 2 rejected. */
    public function approveSenderIds(array $senderIds): Result
    {
        return $this->t->call('/open/sms/status', ['type' => 6, 'senderids' => $senderIds], ['needs' => ['vasKey']]);
    }
}
