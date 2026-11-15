<?php

declare(strict_types=1);

namespace Moolre\Api;

use Moolre\Result;

final class Reference extends Resource
{
    /** Supported banks and their codes (use as subListId). */
    public function banks(string $country = 'gha'): Result
    {
        return $this->t->call('/open/transact/data', [], [
            'method' => 'GET', 'query' => ['country' => $country, 'data' => 'banks'],
            'needs' => ['apiKey', 'pubKey'], 'retry' => 'read',
        ]);
    }
}
