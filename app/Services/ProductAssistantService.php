<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class ProductAssistantService
{
    public function __construct(
        protected GeminiImageResolverService $imageResolver,
        protected IntentResolverService $intentResolver,
        protected ProductLookupService $productLookup,
        protected ProductReasoningService $reasoning,
        protected ResponseFormatterService $formatter
    ) {
    }

    public function handle(
        string $message,
        array $history = [],
        ?UploadedFile $image = null,
        ?string $preferredOrigin = null
    ): array {
        try {
            $message = trim($message);
            $imageContext = null;

            if ($image) {
                try {
                    $imageContext = $this->normalizeImageContext(
                        $this->imageResolver->extractFromImage($image, $message)
                    );
                } catch (\Throwable $e) {
                    Log::warning('Image extraction failed', ['message' => $e->getMessage()]);
                }
            }

            $intent = $this->intentResolver->resolve($message, $history, $imageContext);
            $toolName = $intent['tool_name'] ?? null;
            $arguments = is_array($intent['arguments'] ?? null) ? $intent['arguments'] : [];

            if (!$toolName) {
                return $this->formatter->format([
                    'status' => 'not_found',
                    'message' => 'Unable to understand request.',
                    'products' => [],
                    'meta' => [
                        'image_context' => $imageContext,
                        'intent' => $intent,
                    ],
                ], 'I could not fully understand your request. Please share a product name, barcode, category, ingredient, or a clearer image.');
            }

            if (!empty($preferredOrigin) && empty($arguments['origin'])) {
                $arguments['origin'] = $preferredOrigin;
            }

            if (!empty($imageContext) && !isset($arguments['image_context'])) {
                $arguments['image_context'] = $imageContext;
            }

            $lookup = $this->productLookup->executeTool($toolName, $arguments);

            if ($this->shouldRunImageRecovery($lookup, $imageContext)) {
                $recovered = $this->attemptImageRecovery($message, $intent, $imageContext, $preferredOrigin);

                if (($recovered['status'] ?? 'not_found') === 'found' && !empty($recovered['products'])) {
                    $lookup = $recovered;
                    $toolName = $recovered['meta']['tool'] ?? $toolName;
                    $lookup['meta']['recovered_from_image'] = true;
                }
            }

            if (($intent['single_product'] ?? false) === true && !empty($lookup['products'])) {
                $lookup['products'] = [$lookup['products'][0]];
                $lookup['meta']['trimmed_to_single'] = true;
            }

            if (($intent['allow_suggestions'] ?? false) === false) {
                $lookup['meta']['show_suggestions'] = false;
            }

            $lookup['meta'] = array_merge(
                is_array($lookup['meta'] ?? null) ? $lookup['meta'] : [],
                [
                    'image_context' => $imageContext,
                    'intent' => $intent,
                    'tool_name' => $toolName,
                    'tool_arguments' => $arguments,
                ]
            );

            $reply = $this->reasoning->buildReply($message, $lookup, $intent, $imageContext);

            return $this->formatter->format($lookup, $reply);
        } catch (\Throwable $e) {
            Log::error('ProductAssistantService failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->formatter->format([
                'status' => 'error',
                'message' => 'Something went wrong while checking the product database.',
                'products' => [],
                'meta' => [],
            ], 'Sorry, something went wrong while checking the product database. Please try again.');
        }
    }

    protected function shouldRunImageRecovery(array $lookup, ?array $imageContext): bool
    {
        if (empty($imageContext) || !is_array($imageContext)) {
            return false;
        }

        $status = (string) ($lookup['status'] ?? 'not_found');
        $products = is_array($lookup['products'] ?? null) ? $lookup['products'] : [];

        if ($status !== 'found' || empty($products)) {
            return true;
        }

        // Even when a product is found, re-check image confidence against top result.
        // This prevents weak or unrelated first matches from being accepted too early.
        $top = is_array($products[0] ?? null) ? $products[0] : [];
        return $this->scoreCandidateAgainstImageContext($top, $imageContext) < 45;
    }

    protected function attemptImageRecovery(
        string $message,
        array $intent,
        array $imageContext,
        ?string $preferredOrigin = null
    ): array {
        $attempts = [];

        $barcode = trim((string) ($imageContext['barcode'] ?? ''));
        $brand = trim((string) ($imageContext['brand'] ?? ''));
        $productName = trim((string) ($imageContext['product_name'] ?? ''));
        $variant = trim((string) ($imageContext['variant'] ?? ''));
        $category = trim((string) ($imageContext['category'] ?? ''));
        $visibleText = trim((string) ($imageContext['visible_text'] ?? ''));

        if ($barcode !== '') {
            $attempts[] = [
                'tool' => 'find_product_by_barcode',
                'arguments' => [
                    'barcode' => $barcode,
                    'image_context' => $imageContext,
                ],
                'mode' => 'direct',
            ];
        }

        foreach ($this->buildCandidatePhrases($imageContext) as $phrase) {
            $attempts[] = [
                'tool' => 'find_product_by_name',
                'arguments' => [
                    'name' => $phrase,
                    'image_context' => $imageContext,
                ],
                'mode' => 'direct',
            ];

            $attempts[] = [
                'tool' => 'search_products',
                'arguments' => array_filter([
                    'query' => $phrase,
                    'brand' => $brand !== '' ? $brand : null,
                    'category' => $category !== '' ? $category : null,
                    'origin' => $preferredOrigin,
                    'limit' => 10,
                    'image_context' => $imageContext,
                ], fn ($value) => $value !== null && $value !== ''),
                'mode' => 'scored_search',
            ];
        }

        if ($visibleText !== '') {
            $attempts[] = [
                'tool' => 'search_products',
                'arguments' => array_filter([
                    'query' => $visibleText,
                    'brand' => $brand !== '' ? $brand : null,
                    'category' => $category !== '' ? $category : null,
                    'origin' => $preferredOrigin,
                    'limit' => 10,
                    'image_context' => $imageContext,
                ], fn ($value) => $value !== null && $value !== ''),
                'mode' => 'scored_search',
            ];
        }

        if ($brand !== '' || $category !== '') {
            $attempts[] = [
                'tool' => 'search_products',
                'arguments' => array_filter([
                    'query' => trim(implode(' ', array_filter([$brand, $productName, $variant, $visibleText, $message]))),
                    'brand' => $brand !== '' ? $brand : null,
                    'category' => $category !== '' ? $category : null,
                    'origin' => $preferredOrigin,
                    'limit' => 12,
                    'image_context' => $imageContext,
                ], fn ($value) => $value !== null && $value !== ''),
                'mode' => 'scored_search',
            ];
        }

        $attempts = $this->deduplicateAttempts($attempts);

        foreach ($attempts as $attempt) {
            try {
                $result = $this->productLookup->executeTool($attempt['tool'], $attempt['arguments']);
                $result = $this->rankRecoveryResult($result, $imageContext, $attempt);

                if (($result['status'] ?? 'not_found') === 'found' && !empty($result['products'])) {
                    $top = $result['products'][0] ?? [];
                    $score = (int) ($top['_image_match_score'] ?? 0);

                    if ($score >= 45 || $attempt['tool'] === 'find_product_by_barcode') {
                        $result['meta'] = array_merge(
                            is_array($result['meta'] ?? null) ? $result['meta'] : [],
                            [
                                'tool' => $attempt['tool'],
                                'recovery_mode' => $attempt['mode'] ?? 'direct',
                                'recovery_attempt_arguments' => $attempt['arguments'],
                            ]
                        );

                        return $result;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Image recovery attempt failed', [
                    'tool' => $attempt['tool'],
                    'arguments' => $attempt['arguments'],
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'status' => 'not_found',
            'message' => 'Image recovery did not find a confident database match.',
            'products' => [],
            'meta' => [
                'tool' => 'image_recovery',
                'image_context' => $imageContext,
            ],
        ];
    }

    protected function buildCandidatePhrases(array $imageContext): array
    {
        $brand = $this->cleanImageToken((string) ($imageContext['brand'] ?? ''));
        $productName = $this->cleanImageToken((string) ($imageContext['product_name'] ?? ''));
        $variant = $this->cleanImageToken((string) ($imageContext['variant'] ?? ''));
        $visibleText = $this->cleanImageToken((string) ($imageContext['visible_text'] ?? ''));

        $phrases = [];

        if ($brand !== '' && $productName !== '') {
            $phrases[] = trim($brand . ' ' . $productName . ' ' . $variant);
            $phrases[] = trim($productName . ' ' . $brand . ' ' . $variant);
            $phrases[] = trim($brand . ' ' . $productName);
            $phrases[] = trim($productName . ' ' . $brand);
        }

        if ($productName !== '') {
            $phrases[] = trim($productName . ' ' . $variant);
            $phrases[] = $productName;
        }

        if ($brand !== '') {
            $phrases[] = $brand;
        }

        if ($visibleText !== '') {
            $phrases[] = $visibleText;
        }

        $explodedText = preg_split('/\s+/', $visibleText) ?: [];
        foreach ([$brand, $productName] as $needle) {
            if ($needle === '') {
                continue;
            }

            foreach ($explodedText as $chunk) {
                $chunk = $this->cleanImageToken($chunk);
                if ($chunk !== '' && mb_strlen($chunk) >= 3) {
                    $phrases[] = trim($needle . ' ' . $chunk);
                }
            }
        }

        $phrases = array_values(array_unique(array_filter(array_map(function ($phrase) {
            $phrase = preg_replace('/\s+/u', ' ', trim((string) $phrase)) ?? trim((string) $phrase);
            return $phrase;
        }, $phrases), fn ($phrase) => $phrase !== '')));

        usort($phrases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($phrases, 0, 10);
    }

    protected function deduplicateAttempts(array $attempts): array
    {
        $seen = [];
        $unique = [];

        foreach ($attempts as $attempt) {
            $key = ($attempt['tool'] ?? '') . '|' . md5(json_encode($attempt['arguments'] ?? []));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $attempt;
        }

        return $unique;
    }

    protected function rankRecoveryResult(array $result, array $imageContext, array $attempt): array
    {
        $products = is_array($result['products'] ?? null) ? $result['products'] : [];

        if (empty($products)) {
            return $result;
        }

        foreach ($products as $index => $product) {
            if (!is_array($product)) {
                continue;
            }

            $products[$index]['_image_match_score'] = $this->scoreCandidateAgainstImageContext($product, $imageContext);
        }

        usort($products, function (array $a, array $b) {
            return ((int) ($b['_image_match_score'] ?? 0)) <=> ((int) ($a['_image_match_score'] ?? 0));
        });

        $result['products'] = $products;
        $result['status'] = !empty($products) ? 'found' : ($result['status'] ?? 'not_found');
        $result['meta'] = array_merge(
            is_array($result['meta'] ?? null) ? $result['meta'] : [],
            [
                'image_recovery_scores' => array_map(fn ($product) => [
                    'name' => $product['name'] ?? null,
                    'barcode' => $product['barcode'] ?? null,
                    'score' => $product['_image_match_score'] ?? 0,
                ], array_slice($products, 0, 5)),
                'recovery_mode' => $attempt['mode'] ?? 'direct',
            ]
        );

        return $result;
    }

    protected function scoreCandidateAgainstImageContext(array $product, array $imageContext): int
    {
        $score = 0;

        $productName = $this->normalizeForCompare((string) ($product['name'] ?? ''));
        $productBrand = $this->normalizeForCompare((string) ($product['brand'] ?? ''));
        $imageName = $this->normalizeForCompare((string) ($imageContext['product_name'] ?? ''));
        $imageBrand = $this->normalizeForCompare((string) ($imageContext['brand'] ?? ''));
        $imageVisibleText = $this->normalizeForCompare((string) ($imageContext['visible_text'] ?? ''));
        $imageVariant = $this->normalizeForCompare((string) ($imageContext['variant'] ?? ''));
        $imageBarcode = preg_replace('/\D+/', '', (string) ($imageContext['barcode'] ?? '')) ?? '';
        $productBarcode = preg_replace('/\D+/', '', (string) ($product['barcode'] ?? '')) ?? '';

        if ($imageBarcode !== '' && $productBarcode !== '' && $imageBarcode === $productBarcode) {
            $score += 100;
        }

        if ($imageBrand !== '' && $productBrand !== '') {
            if ($imageBrand === $productBrand) {
                $score += 30;
            } elseif (str_contains($productName, $imageBrand)) {
                $score += 18;
            }
        }

        if ($imageName !== '' && $productName !== '') {
            if ($imageName === $productName) {
                $score += 35;
            } elseif (str_contains($productName, $imageName) || str_contains($imageName, $productName)) {
                $score += 28;
            } else {
                $score += $this->sharedTokenScore($imageName, $productName, 6);
            }
        }

        if ($imageVariant !== '' && str_contains($productName, $imageVariant)) {
            $score += 10;
        }

        if ($imageVisibleText !== '') {
            $score += $this->sharedTokenScore($imageVisibleText, trim($productName . ' ' . $productBrand), 4);
        }

        return min(100, $score);
    }

    protected function sharedTokenScore(string $source, string $target, int $pointsPerToken): int
    {
        $sourceTokens = $this->tokenizeForCompare($source);
        $targetTokens = $this->tokenizeForCompare($target);

        if (empty($sourceTokens) || empty($targetTokens)) {
            return 0;
        }

        $shared = array_intersect($sourceTokens, $targetTokens);

        return count($shared) * $pointsPerToken;
    }

    protected function tokenizeForCompare(string $value): array
    {
        $value = $this->normalizeForCompare($value);
        $parts = preg_split('/\s+/', $value) ?: [];

        return array_values(array_unique(array_filter($parts, fn ($part) => mb_strlen($part) >= 2)));
    }

    protected function normalizeForCompare(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['-', '_', '/', '|'], ' ', $value);
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value;
    }

    protected function cleanImageToken(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\b(product|brand|detected|name|barcode)\b[:\-]*/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return $value;
    }

    protected function normalizeImageContext(?array $imageContext): ?array
    {
        if (!$imageContext || !is_array($imageContext)) {
            return $imageContext;
        }

        $normalized = $imageContext;

        foreach (['barcode', 'product_name', 'brand', 'category', 'variant', 'packaging', 'visible_text', 'confidence', 'notes'] as $key) {
            if (array_key_exists($key, $normalized)) {
                $normalized[$key] = is_string($normalized[$key])
                    ? trim($normalized[$key])
                    : $normalized[$key];
            }
        }

        // Common OCR cleanup: TUC is often the product name while LU is the brand.
        // Keep both, but remove duplicated noise and separators.
        foreach (['product_name', 'brand', 'visible_text'] as $key) {
            $value = (string) ($normalized[$key] ?? '');
            $value = str_replace(['|', '•'], ' ', $value);
            $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
            $normalized[$key] = $value !== '' ? $value : null;
        }

        $barcode = preg_replace('/\D+/', '', (string) ($normalized['barcode'] ?? '')) ?? '';
        $normalized['barcode'] = $barcode !== '' ? $barcode : null;

        return $normalized;
    }
}
