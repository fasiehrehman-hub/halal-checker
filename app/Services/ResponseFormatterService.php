<?php

namespace App\Services;

class ResponseFormatterService
{
    public function format(array $lookup, string $reply): array
    {
        $products = $this->normalizeProducts($lookup['products'] ?? []);
        $status = $this->normalizeStatus($lookup['status'] ?? null, $products);
        $message = trim((string) ($lookup['message'] ?? ''));
        $reply = trim($reply);

        return [
            'reply' => $reply !== '' ? $reply : $this->buildFallbackReply($status, $products, $message),
            'data' => [
                'status' => $status,
                'message' => $message,
                'products' => $products,
                'ingredient_explanation' => $this->nullableString($lookup['ingredient_explanation'] ?? null),
                'meta' => $this->buildMeta($lookup, $products),
            ],
        ];
    }

    private function normalizeProducts(mixed $products): array
    {
        if (! is_array($products)) {
            return [];
        }

        $normalized = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $normalized[] = [
                'id' => $product['id'] ?? null,
                'name' => $this->nullableString($product['name'] ?? null),
                'brand' => $this->nullableString($product['brand'] ?? null),
                'barcode' => $this->nullableString($product['barcode'] ?? null),
                'origin' => $this->nullableString($product['origin'] ?? null),
                'category' => $this->nullableString($product['category'] ?? ($product['main_category'] ?? null)),
                'status' => $this->normalizeDecision($product['status'] ?? ($product['decision'] ?? null)),
                'ingredients' => $this->nullableString($product['ingredients'] ?? null),
                'image' => $this->nullableString($product['image'] ?? null),
                'description' => $this->nullableString($product['description'] ?? null),
            ];
        }

        return array_values(array_filter($normalized, fn (array $item): bool => ! empty($item['name']) || ! empty($item['barcode'])));
    }

    private function normalizeStatus(mixed $status, array $products): string
    {
        $status = strtolower(trim((string) $status));

        if ($status !== '') {
            return $status;
        }

        return empty($products) ? 'not_found' : 'found';
    }

    private function normalizeDecision(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'halal' => 'halal',
            'haram' => 'haram',
            'mashbooh', 'mushbooh' => 'mushbooh',
            'unknown', '' => null,
            default => $value,
        };
    }

    private function buildMeta(array $lookup, array $products): array
    {
        $meta = $lookup['meta'] ?? [];

        if (! is_array($meta)) {
            $meta = [];
        }

        $meta['product_count'] = count($products);
        $meta['has_ingredients'] = collect($products)->contains(fn (array $product): bool => ! empty($product['ingredients']));
        $meta['has_barcodes'] = collect($products)->contains(fn (array $product): bool => ! empty($product['barcode']));

        return $meta;
    }

    private function buildFallbackReply(string $status, array $products, string $message): string
    {
        if ($message !== '') {
            return $message;
        }

        if ($status === 'not_found' || $products === []) {
            return 'I could not find a matching product in the database.';
        }

        if (count($products) === 1) {
            return 'I found 1 matching product.';
        }

        return 'I found ' . count($products) . ' matching products.';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
