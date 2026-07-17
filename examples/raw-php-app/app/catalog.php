<?php

declare(strict_types=1);

/**
 * @return array<int, array{id: string, name: string, description: string, price: float, image: string}>
 */
function demo_products(): array
{
    return [
        [
            'id' => 'sneakers',
            'name' => 'Premium Sneakers',
            'description' => 'Lightweight everyday pair',
            'price' => 1.00,
            'image' => '/assets/products/sneakers.svg',
        ],
        [
            'id' => 'hoodie',
            'name' => 'Everyday Hoodie',
            'description' => 'Soft cotton fleece',
            'price' => 2.00,
            'image' => '/assets/products/hoodie.svg',
        ],
        [
            'id' => 'headphones',
            'name' => 'Wireless Headphones',
            'description' => 'Noise-isolating audio',
            'price' => 5.00,
            'image' => '/assets/products/headphones.svg',
        ],
        [
            'id' => 'backpack',
            'name' => 'Travel Backpack',
            'description' => 'Compact daily carry',
            'price' => 10.00,
            'image' => '/assets/products/backpack.svg',
        ],
    ];
}

/**
 * @return array<int, array{id: string, quantity: int}>
 */
function demo_default_cart(): array
{
    return [
        ['id' => 'sneakers', 'quantity' => 1],
        ['id' => 'hoodie', 'quantity' => 1],
        ['id' => 'backpack', 'quantity' => 1],
    ];
}

/**
 * @param array<int, array{id?: string, quantity?: int}> $cart
 * @return array<int, array{id: string, name: string, description: string, unit_price: float, quantity: int, total: float, image: string}>
 */
function demo_cart_lines(array $cart): array
{
    $products = [];

    foreach (demo_products() as $product) {
        $products[$product['id']] = $product;
    }

    $lines = [];

    foreach ($cart as $item) {
        $id = (string) ($item['id'] ?? '');

        if (!isset($products[$id])) {
            continue;
        }

        $quantity = max(1, min(9, (int) ($item['quantity'] ?? 1)));
        $product = $products[$id];

        $lines[] = [
            'id' => $id,
            'name' => $product['name'],
            'description' => $product['description'],
            'unit_price' => $product['price'],
            'quantity' => $quantity,
            'total' => round($product['price'] * $quantity, 2),
            'image' => $product['image'],
        ];
    }

    return $lines;
}

/**
 * @return array<int, array{id: string, name: string, description: string, unit_price: float, quantity: int, total: float, image: string}>
 */
function demo_cart_from_payload(string $payload): array
{
    $decoded = json_decode($payload, true);

    if (!is_array($decoded)) {
        return [];
    }

    return demo_cart_lines($decoded);
}

/**
 * @param array<int, array{total: float}> $lines
 */
function demo_cart_total(array $lines): float
{
    $total = 0.0;

    foreach ($lines as $line) {
        $total += (float) $line['total'];
    }

    return round($total, 2);
}
