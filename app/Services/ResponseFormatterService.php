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
                'status' => $this->normalizeDecision($product['status'] ?? ($product['decision'] ?? ($product['type'] ?? null))),
                'ingredients' => $this->nullableString($product['ingredients'] ?? null),
                'ingredient_note' => $this->nullableString($product['ingredient_note'] ?? null),
                'ingredient_preview' => $this->nullableString($product['ingredient_preview'] ?? $this->buildIngredientPreview($product['ingredients'] ?? null)),
                'image' => $this->nullableString($product['image'] ?? null),
                'description' => $this->nullableString($product['description'] ?? null),
            ];
        }

        return $this->deduplicateProducts(
            array_values(array_filter($normalized, fn (array $item): bool => ! empty($item['name']) || ! empty($item['barcode'])))
        );
    }

    private function deduplicateProducts(array $products): array
    {
        $seen = [];
        $unique = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $barcode = preg_replace('/\D+/', '', (string) ($product['barcode'] ?? '')) ?? '';
            $name = strtolower(trim((string) ($product['name'] ?? '')));
            $origin = strtolower(trim((string) ($product['origin'] ?? '')));
            $ingredients = strtolower(trim((string) ($product['ingredients'] ?? '')));

            $key = $barcode !== '' && strlen($barcode) >= 8
                ? 'barcode:' . $barcode
                : 'signature:' . md5($name . '|' . $origin . '|' . $ingredients);

            if ($name !== '' && $ingredients !== '') {
                $signatureKey = 'signature:' . md5($name . '|' . $origin . '|' . $ingredients);
                if (isset($seen[$signatureKey])) {
                    continue;
                }
                $seen[$signatureKey] = true;
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $product;
        }

        return array_values($unique);
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
        $value = str_replace(['_', '-'], ' ', $value);

        return match ($value) {
            'approved', 'approve', 'halal certified', 'halal', 'permissible', 'permitted', 'safe', 'muslim friendly', 'muslim-friendly' => 'halal',
            'haram', 'not halal', 'non halal', 'forbidden', 'prohibited' => 'haram',
            'mashbooh', 'mushbooh', 'doubtful', 'suspect', 'questionable' => 'mushbooh',
            'unknown', 'pending', 'decision pending', 'not found', 'unverified', 'needs review', 'needs verification', '' => null,
            'out of scope', 'non food', 'non-food', 'not food' => 'out_of_scope',
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

    private function buildIngredientPreview(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/,|;|\|/u', $value) ?: [])));
        if (empty($parts)) {
            return mb_substr($value, 0, 120);
        }

        return implode(', ', array_slice($parts, 0, 5));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
