<?php

declare(strict_types=1);

final class OrderStore
{
    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function create(array $order): array
    {
        $now = date(DATE_ATOM);
        $order = array_merge($order, [
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $handle = $this->openStore();

        try {
            flock($handle, LOCK_EX);
            $orders = $this->readFromHandle($handle);
            array_unshift($orders, $order);
            $this->writeToHandle($handle, $orders);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $order;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 10): array
    {
        return array_slice($this->all(), 0, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByReference(string $reference): ?array
    {
        foreach ($this->all() as $order) {
            if (($order['reference'] ?? '') === $reference) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPendingByReference(string $reference): ?array
    {
        $order = $this->findByReference($reference);

        return $order !== null && ($order['status'] ?? null) === 'pending' ? $order : null;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>|null
     */
    public function updateByReference(string $reference, array $changes): ?array
    {
        $handle = $this->openStore();
        $updated = null;

        try {
            flock($handle, LOCK_EX);
            $orders = $this->readFromHandle($handle);

            foreach ($orders as $index => $order) {
                if (($order['reference'] ?? '') !== $reference) {
                    continue;
                }

                $orders[$index] = array_merge($order, $changes, [
                    'updated_at' => date(DATE_ATOM),
                ]);
                $updated = $orders[$index];
                break;
            }

            if ($updated !== null) {
                $this->writeToHandle($handle, $orders);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $updated;
    }

    /**
     * Atomically transition an order from pending to paid.
     *
     * @param array<string, mixed> $changes
     * @return array{order: array<string, mixed>|null, transitioned: bool}
     */
    public function markPaidIfPending(string $reference, array $changes): array
    {
        $handle = $this->openStore();
        $result = ['order' => null, 'transitioned' => false];

        try {
            flock($handle, LOCK_EX);
            $orders = $this->readFromHandle($handle);

            foreach ($orders as $index => $order) {
                if (($order['reference'] ?? '') !== $reference) {
                    continue;
                }

                $result['order'] = $order;

                if (($order['status'] ?? '') !== 'pending') {
                    break;
                }

                unset($changes['status']);
                $orders[$index] = array_merge($order, $changes, [
                    'status' => 'paid',
                    'paid_at' => date(DATE_ATOM),
                    'updated_at' => date(DATE_ATOM),
                ]);
                $this->writeToHandle($handle, $orders);
                $result = ['order' => $orders[$index], 'transitioned' => true];
                break;
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(): array
    {
        $handle = $this->openStore();

        try {
            flock($handle, LOCK_SH);

            return $this->readFromHandle($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function openStore()
    {
        $handle = fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the demo order store.');
        }

        return $handle;
    }

    /**
     * @param resource $handle
     * @return array<int, array<string, mixed>>
     */
    private function readFromHandle($handle): array
    {
        rewind($handle);
        $json = stream_get_contents($handle);

        if ($json === false || trim($json) === '') {
            return [];
        }

        $orders = json_decode($json, true);

        return is_array($orders) ? $orders : [];
    }

    /**
     * @param resource $handle
     * @param array<int, array<string, mixed>> $orders
     */
    private function writeToHandle($handle, array $orders): void
    {
        $json = json_encode($orders, JSON_PRETTY_PRINT);

        if ($json === false) {
            throw new RuntimeException('Unable to encode the demo order store.');
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
    }
}
