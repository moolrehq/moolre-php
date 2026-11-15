<?php

declare(strict_types=1);

namespace Moolre\Api;

use Generator;
use Moolre\Result;

/** WhatsApp template messages (needs vasKey). */
final class WhatsApp extends Resource
{
    /** One page of approved templates; $next is the cursor from the previous page ($result->raw['next']). */
    public function templates(?string $next = null): Result
    {
        return $this->t->call('/open/whatsapp/template', [], ['method' => 'GET', 'query' => ['next' => $next], 'needs' => ['vasKey'], 'retry' => 'read']);
    }

    /** @return Generator<int, array> */
    public function iterateTemplates(array $page = []): Generator
    {
        $max = $page['maxItems'] ?? null;
        $next = null;
        $seen = 0;
        do {
            $r = $this->templates($next);
            foreach (is_array($r->data) ? $r->data : [] as $t) {
                if ($max !== null && $seen >= $max) {
                    return;
                }
                $seen++;
                yield $t;
            }
            $n = $r->raw['next'] ?? null;
            $next = is_string($n) && $n !== '' ? $n : null;
        } while ($next !== null);
    }

    /** @param array{templateName:string,language:string,messages:array} $p */
    public function send(array $p): Result
    {
        $this->need($p, ['templateName', 'language', 'messages']);

        return $this->t->call('/open/whatsapp/send', [
            'template_name' => $p['templateName'], 'language' => $p['language'], 'messages' => $p['messages'],
        ], ['needs' => ['vasKey']]);
    }

    public function status(array $refs): Result
    {
        return $this->t->call('/open/whatsapp/status', ['ref' => array_values($refs)], ['needs' => ['vasKey'], 'retry' => 'read']);
    }
}
