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

        $orders = $this->all();
        array_unshift($orders, $order);
        $this->save($orders);

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
     * @param array<string, mixed> $changes
     * @return array<string, mixed>|null
     */
    public function updateByReference(string $reference, array $changes): ?array
    {
        $orders = $this->all();
        $updated = null;

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
            $this->save($orders);
        }

        return $updated;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $json = file_get_contents($this->path);

        if ($json === false || trim($json) === '') {
            return [];
        }

        $orders = json_decode($json, true);

        return is_array($orders) ? $orders : [];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     */
    private function save(array $orders): void
    {
        file_put_contents($this->path, json_encode($orders, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
