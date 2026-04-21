<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    public function __construct(
        protected ProductLookupService $productLookupService,
        protected GeminiImageResolverService $geminiImageResolverService
    ) {
    }

    public function handleChat(
        string $message,
        array $history = [],
        ?UploadedFile $image = null,
        ?string $preferredOrigin = null
    ): array {
        try {
            $message = trim($message);
            $history = $this->trimHistory($history);
            $imageContext = null;

            if ($image) {
                try {
                    $imageContext = $this->normalizeImageContext(
                        $this->geminiImageResolverService->extractFromImage($image, $message)
                    );
                } catch (\Throwable $e) {
                    Log::warning('Image extraction failed', ['message' => $e->getMessage()]);
                }
            }

            $intent = $this->detectIntent($message, $history, $imageContext);

            $toolName = $intent['tool_name'] ?? null;
            $arguments = is_array($intent['arguments'] ?? null) ? $intent['arguments'] : [];

            if (!$toolName && !empty($imageContext['barcode'])) {
                $toolName = 'find_product_by_barcode';
                $arguments = ['barcode' => $imageContext['barcode']];
            }

            if (!$toolName && !empty($imageContext['product_name'])) {
                $toolName = 'find_product_by_name';
                $arguments = ['name' => $imageContext['product_name']];
            }

            if (!$toolName) {
                return $this->response(
                    'I could not fully understand your request. Please share a product name, barcode, category, ingredient, or a clearer image.',
                    [
                        'status' => 'not_found',
                        'message' => 'Unable to prepare a valid search intent.',
                        'products' => [],
                        'meta' => ['image_context' => $imageContext],
                    ]
                );
            }

            if (!empty($preferredOrigin) && empty($arguments['origin'])) {
                $arguments['origin_hint'] = $preferredOrigin;
            }

            $lookup = $this->productLookupService->executeTool($toolName, $arguments);

            if ($this->shouldRecoverFromImage($lookup, $imageContext)) {
                $recovered = $this->attemptImageRecovery($message, $imageContext, $preferredOrigin);

                if (($recovered['status'] ?? 'not_found') === 'found' && !empty($recovered['products'])) {
                    $lookup = $recovered;
                    $toolName = $recovered['meta']['tool_name'] ?? $toolName;
                    $arguments = $recovered['meta']['tool_arguments'] ?? $arguments;
                }
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

            return $this->response(
                $this->answerFromToolResult($message, $lookup, $intent, $imageContext),
                $lookup
            );
        } catch (\Throwable $e) {
            Log::error('GeminiService failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->response(
                'Sorry, something went wrong while checking the product database. Please try again.',
                [
                    'status' => 'error',
                    'message' => 'Something went wrong while checking the product database.',
                    'products' => [],
                ]
            );
        }
    }

    protected function detectIntent(string $message, array $history = [], ?array $imageContext = null): array
    {
        $message = trim($message);

        if ($message === '' && !empty($imageContext['barcode'])) {
            return [
                'tool_name' => 'find_product_by_barcode',
                'arguments' => ['barcode' => $imageContext['barcode']],
                'source' => 'image_context',
            ];
        }

        if ($message === '' && !empty($imageContext['product_name'])) {
            return [
                'tool_name' => 'find_product_by_name',
                'arguments' => ['name' => $imageContext['product_name']],
                'source' => 'image_context',
            ];
        }

        $barcode = $this->extractBarcode($message);
        if ($barcode !== null) {
            return [
                'tool_name' => 'find_product_by_barcode',
                'arguments' => ['barcode' => $barcode],
                'source' => 'regex_barcode',
            ];
        }

        $fast = $this->fallbackIntent($message, $imageContext);
        $apiKey = (string) config('services.gemini.api_key');
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash');

        if ($apiKey === '') {
            return $fast;
        }

        $system = <<<TEXT
You are Mustakshif's intent engine.

Your job:
1. Understand messy English, Urdu-English, mixed wording, short text, and incomplete product requests.
2. Choose exactly one tool.
3. Extract clean arguments only.
4. Never answer product facts yourself.

Available tools:

1) find_product_by_barcode
   args:
   - barcode
   Use for exact barcode lookup.

2) find_product_by_name
   args:
   - name
   Use for one specific product or one specific product phrase.
   Examples:
   - "is dairy milk halal"
   - "cadbury dairy milk"
   - "tell me about coca cola"
   - "yeh product halal hai?"

3) search_products
   args:
   - query
   - brand
   - category
   - origin
   - ingredients_include
   - ingredients_exclude
   - match_mode
   - halal_only
   - status
   - limit
   Use for lists, discovery, category browsing, brand browsing, or ingredient-based search.

4) explain_ingredient
   args:
   - ingredient
   Use only for ingredient explanation when the user is asking what an ingredient means, not asking about a specific product.

Rules:
- If a barcode exists, prefer find_product_by_barcode.
- If the user asks about one product, prefer find_product_by_name.
- If the user asks for many products or a category/brand list, use search_products.
- If the user says "this", "it", "yeh", "isko", "iss product", and image context has a product/barcode, use the image context.
- If the user says halal chocolates, use search_products with category and halal_only=true.
- Allowed status values: halal, haram, mushbooh.
- Return one function call only.
TEXT;

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $system]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => $this->buildIntentInput($message, $history, $imageContext),
                ]],
            ]],
            'tools' => [[
                'functionDeclarations' => [
                    [
                        'name' => 'find_product_by_barcode',
                        'description' => 'Find a product using a barcode.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'barcode' => ['type' => 'STRING'],
                            ],
                            'required' => ['barcode'],
                        ],
                    ],
                    [
                        'name' => 'find_product_by_name',
                        'description' => 'Find one product by name or product phrase.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'name' => ['type' => 'STRING'],
                            ],
                            'required' => ['name'],
                        ],
                    ],
                    [
                        'name' => 'search_products',
                        'description' => 'Search many products using query, category, brand, ingredient filters, status filters, and origin.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'query' => ['type' => 'STRING'],
                                'brand' => ['type' => 'STRING'],
                                'category' => ['type' => 'STRING'],
                                'origin' => ['type' => 'STRING'],
                                'ingredients_include' => [
                                    'type' => 'ARRAY',
                                    'items' => ['type' => 'STRING'],
                                ],
                                'ingredients_exclude' => [
                                    'type' => 'ARRAY',
                                    'items' => ['type' => 'STRING'],
                                ],
                                'match_mode' => [
                                    'type' => 'STRING',
                                    'enum' => ['all', 'any'],
                                ],
                                'halal_only' => ['type' => 'BOOLEAN'],
                                'status' => [
                                    'type' => 'STRING',
                                    'enum' => ['halal', 'haram', 'mushbooh'],
                                ],
                                'limit' => ['type' => 'INTEGER'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'explain_ingredient',
                        'description' => 'Explain one ingredient in simple words.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'ingredient' => ['type' => 'STRING'],
                            ],
                            'required' => ['ingredient'],
                        ],
                    ],
                ],
            ]],
            'tool_config' => [
                'function_calling_config' => [
                    'mode' => 'ANY',
                    'allowed_function_names' => [
                        'find_product_by_barcode',
                        'find_product_by_name',
                        'search_products',
                        'explain_ingredient',
                    ],
                ],
            ],
        ];

        try {
            $response = Http::timeout((int) config('services.gemini.timeout', 45))
                ->acceptJson()
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                    $payload
                )
                ->throw()
                ->json();

            $call = Arr::get($response, 'candidates.0.content.parts.0.functionCall');
            if (!is_array($call)) {
                return $fast;
            }

            return [
                'tool_name' => (string) ($call['name'] ?? ''),
                'arguments' => is_array($call['args'] ?? null) ? $call['args'] : [],
                'source' => 'gemini_tool',
            ];
        } catch (\Throwable $e) {
            Log::warning('Gemini intent detection failed', ['message' => $e->getMessage()]);
            return $fast;
        }
    }

    protected function fallbackIntent(string $message, ?array $imageContext = null): array
    {
        $lower = strtolower(trim($message));

        if ($lower === '') {
            if (!empty($imageContext['barcode'])) {
                return [
                    'tool_name' => 'find_product_by_barcode',
                    'arguments' => ['barcode' => $imageContext['barcode']],
                    'source' => 'empty_message_image_barcode',
                ];
            }

            if (!empty($imageContext['product_name']) || !empty($imageContext['brand'])) {
                return [
                    'tool_name' => 'find_product_by_name',
                    'arguments' => ['name' => $this->bestImageName($imageContext)],
                    'source' => 'empty_message_image_name',
                ];
            }

            return ['tool_name' => null, 'arguments' => [], 'source' => 'empty_message'];
        }

        if ($this->refersToImageSubject($lower) && !empty($imageContext['barcode'])) {
            return [
                'tool_name' => 'find_product_by_barcode',
                'arguments' => ['barcode' => $imageContext['barcode']],
                'source' => 'image_reference',
            ];
        }

        if ($this->refersToImageSubject($lower) && (!empty($imageContext['product_name']) || !empty($imageContext['brand']))) {
            return [
                'tool_name' => 'find_product_by_name',
                'arguments' => ['name' => $this->bestImageName($imageContext)],
                'source' => 'image_reference',
            ];
        }

        if (preg_match('/\bwhat is\b|\bexplain\b/', $lower) && preg_match('/\be\d{3,4}\b/i', $message, $m)) {
            return ['tool_name' => 'explain_ingredient', 'arguments' => ['ingredient' => $m[0]], 'source' => 'fast_rule'];
        }

        if ($this->looksIngredientExplanation($message)) {
            preg_match('/gelatin|e471|natural flavorings?|alcohol|carmine|lecithin/i', $message, $m);
            return ['tool_name' => 'explain_ingredient', 'arguments' => ['ingredient' => $m[0] ?? $message], 'source' => 'fast_rule'];
        }

        if ($this->looksSearchIntent($message)) {
            return [
                'tool_name' => 'search_products',
                'arguments' => [
                    'query' => $message,
                    'category' => $this->extractCategory($message),
                    'brand' => $this->extractBrandHint($message),
                    'origin' => $this->extractOriginHint($message),
                    'ingredients_include' => $this->extractIncludedIngredients($message),
                    'ingredients_exclude' => $this->extractExcludedIngredients($message),
                    'halal_only' => (bool) preg_match('/\bhalal\b/i', $message),
                    'status' => preg_match('/\bharam\b/i', $message)
                        ? 'haram'
                        : (preg_match('/\bmushbooh|\bmashbooh\b/i', $message) ? 'mushbooh' : null),
                    'limit' => preg_match('/\ball\b|\bevery\b/i', $message) ? 20 : 8,
                    'match_mode' => 'all',
                ],
                'source' => 'fast_rule',
            ];
        }

        return [
            'tool_name' => 'find_product_by_name',
            'arguments' => [
                'name' => $this->fallbackExtractProductPhrase($message, $imageContext),
            ],
            'source' => 'fast_rule',
        ];
    }

    protected function answerFromToolResult(string $message, array $toolResult, array $intent, ?array $imageContext = null): string
    {
        $status = $toolResult['status'] ?? 'not_found';
        $products = $toolResult['products'] ?? [];
        $ingredientExplanation = $toolResult['ingredient_explanation'] ?? null;
        $toolName = $intent['tool_name'] ?? '';

        if ($status === 'error') {
            return 'Sorry, something went wrong while checking that. Please try again.';
        }

        if ($toolName === 'explain_ingredient' && $ingredientExplanation) {
            return ($ingredientExplanation['ingredient'] ?? 'This ingredient') . ': ' . ($ingredientExplanation['summary'] ?? 'No explanation available.');
        }

        if ($status !== 'found' || empty($products)) {
            if (!empty($imageContext)) {
                return 'I could not confidently match this image to a product in your database. Please send a clearer front photo, the barcode, or the exact product name.';
            }

            return (string) ($toolResult['message'] ?? 'I could not find a matching product.');
        }

        if ($toolName === 'find_product_by_barcode') {
            $product = $products[0];
            $name = $product['name'] ?? 'This product';
            $decision = $product['decision'] ?? 'unknown';

            return "I checked that barcode. {$name} is marked as {$decision} in your database.";
        }

        if ($toolName === 'find_product_by_name') {
            $product = $products[0];
            $name = $product['name'] ?? 'This product';
            $decision = $product['decision'] ?? 'unknown';

            $parts = ["{$name} is marked as {$decision} in your database."];

            if (!empty($product['ingredients'])) {
                $parts[] = 'Ingredients are available in the product record.';
            }

            if (!empty($product['origin'])) {
                $parts[] = 'Origin: ' . $product['origin'] . '.';
            }

            return implode(' ', $parts);
        }

        $top = array_slice($products, 0, 5);
        $names = array_map(function ($product) {
            $name = (string) ($product['name'] ?? 'Unnamed product');
            $decision = (string) ($product['decision'] ?? 'unknown');
            return $name . ' (' . $decision . ')';
        }, $top);

        return 'Here are the best matches from your database: ' . implode(', ', $names) . '.';
    }

    protected function shouldRecoverFromImage(array $lookup, ?array $imageContext): bool
    {
        if (empty($imageContext) || !is_array($imageContext)) {
            return false;
        }

        $products = $lookup['products'] ?? [];
        $status = strtolower((string) ($lookup['status'] ?? 'not_found'));

        return $status !== 'found' || empty($products);
    }

    protected function attemptImageRecovery(string $message, array $imageContext, ?string $preferredOrigin = null): array
    {
        $attempts = $this->buildImageRecoveryAttempts($message, $imageContext, $preferredOrigin);

        foreach ($attempts as $attempt) {
            try {
                $result = $this->productLookupService->executeTool($attempt['tool_name'], $attempt['arguments']);
                $result = $this->rerankRecoveredProducts($result, $imageContext);

                if (($result['status'] ?? 'not_found') === 'found' && !empty($result['products'])) {
                    $result['meta'] = array_merge(
                        is_array($result['meta'] ?? null) ? $result['meta'] : [],
                        [
                            'recovered_from_image' => true,
                            'tool_name' => $attempt['tool_name'],
                            'tool_arguments' => $attempt['arguments'],
                        ]
                    );

                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning('Image recovery attempt failed', [
                    'tool_name' => $attempt['tool_name'],
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
                'image_context' => $imageContext,
                'recovered_from_image' => false,
            ],
        ];
    }

    protected function buildImageRecoveryAttempts(string $message, array $imageContext, ?string $preferredOrigin = null): array
    {
        $attempts = [];

        $barcode = trim((string) ($imageContext['barcode'] ?? ''));
        $brand = trim((string) ($imageContext['brand'] ?? ''));
        $productName = trim((string) ($imageContext['product_name'] ?? ''));
        $variant = trim((string) ($imageContext['variant'] ?? ''));
        $category = trim((string) ($imageContext['category'] ?? ''));
        $visibleText = trim((string) ($imageContext['visible_text'] ?? ''));

        if ($barcode !== '') {
            $attempts[] = [
                'tool_name' => 'find_product_by_barcode',
                'arguments' => ['barcode' => $barcode],
            ];
        }

        foreach ($this->buildCandidateNames($imageContext) as $candidate) {
            $attempts[] = [
                'tool_name' => 'find_product_by_name',
                'arguments' => ['name' => $candidate],
            ];
        }

        foreach ($this->buildCandidateNames($imageContext) as $candidate) {
            $attempts[] = [
                'tool_name' => 'search_products',
                'arguments' => array_filter([
                    'query' => $candidate,
                    'brand' => $brand !== '' ? $brand : null,
                    'category' => $category !== '' ? $category : null,
                    'origin' => $preferredOrigin,
                    'limit' => 8,
                    'match_mode' => 'any',
                ], fn ($value) => !($value === null || $value === '')),
            ];
        }

        if ($visibleText !== '') {
            $attempts[] = [
                'tool_name' => 'search_products',
                'arguments' => array_filter([
                    'query' => $visibleText,
                    'brand' => $brand !== '' ? $brand : null,
                    'origin' => $preferredOrigin,
                    'limit' => 8,
                    'match_mode' => 'any',
                ], fn ($value) => !($value === null || $value === '')),
            ];
        }

        if ($category !== '') {
            $attempts[] = [
                'tool_name' => 'search_products',
                'arguments' => array_filter([
                    'query' => $message !== '' ? $message : $category,
                    'brand' => $brand !== '' ? $brand : null,
                    'category' => $category,
                    'origin' => $preferredOrigin,
                    'limit' => 8,
                    'match_mode' => 'any',
                ], fn ($value) => !($value === null || $value === '')),
            ];
        }

        return $this->dedupeAttempts($attempts);
    }

    protected function buildCandidateNames(array $imageContext): array
    {
        $brand = trim((string) ($imageContext['brand'] ?? ''));
        $productName = trim((string) ($imageContext['product_name'] ?? ''));
        $variant = trim((string) ($imageContext['variant'] ?? ''));
        $visibleText = trim((string) ($imageContext['visible_text'] ?? ''));

        $raw = [
            trim(implode(' ', array_filter([$brand, $productName, $variant]))),
            trim(implode(' ', array_filter([$productName, $variant]))),
            trim(implode(' ', array_filter([$brand, $productName]))),
            trim(implode(' ', array_filter([$productName, $brand]))),
            $productName,
            $brand,
            $visibleText,
        ];

        $candidates = [];

        foreach ($raw as $value) {
            $value = $this->cleanCandidatePhrase($value);
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        return array_values(array_unique($candidates));
    }

    protected function cleanCandidatePhrase(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^\pL\pN\s\-]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value;
    }

    protected function rerankRecoveredProducts(array $result, array $imageContext): array
    {
        $products = $result['products'] ?? [];
        if (!is_array($products) || empty($products)) {
            return $result;
        }

        $brand = strtolower(trim((string) ($imageContext['brand'] ?? '')));
        $productName = strtolower(trim((string) ($imageContext['product_name'] ?? '')));
        $visibleText = strtolower(trim((string) ($imageContext['visible_text'] ?? '')));

        usort($products, function (array $a, array $b) use ($brand, $productName, $visibleText) {
            return $this->scoreRecoveredProduct($b, $brand, $productName, $visibleText)
                <=> $this->scoreRecoveredProduct($a, $brand, $productName, $visibleText);
        });

        $topScore = $this->scoreRecoveredProduct($products[0], $brand, $productName, $visibleText);
        if ($topScore < 30) {
            $result['status'] = 'not_found';
            $result['products'] = [];
            return $result;
        }

        $result['status'] = 'found';
        $result['products'] = array_values($products);
        $result['meta'] = array_merge(
            is_array($result['meta'] ?? null) ? $result['meta'] : [],
            ['recovery_top_score' => $topScore]
        );

        return $result;
    }

    protected function scoreRecoveredProduct(array $product, string $brand, string $productName, string $visibleText): int
    {
        $score = 0;
        $name = strtolower(trim((string) ($product['name'] ?? '')));
        $productBrand = strtolower(trim((string) ($product['brand'] ?? '')));

        if ($productName !== '' && $name !== '') {
            if ($name === $productName) {
                $score += 50;
            } elseif (str_contains($name, $productName) || str_contains($productName, $name)) {
                $score += 35;
            } else {
                similar_text($name, $productName, $pct);
                $score += (int) floor($pct * 0.25);
            }
        }

        if ($brand !== '' && $productBrand !== '') {
            if ($productBrand === $brand) {
                $score += 35;
            } elseif (str_contains($productBrand, $brand) || str_contains($brand, $productBrand)) {
                $score += 22;
            }
        }

        if ($visibleText !== '' && $name !== '' && str_contains($visibleText, $name)) {
            $score += 10;
        }

        return $score;
    }

    protected function dedupeAttempts(array $attempts): array
    {
        $seen = [];
        $final = [];

        foreach ($attempts as $attempt) {
            $key = md5(($attempt['tool_name'] ?? '') . '|' . json_encode($attempt['arguments'] ?? []));
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $final[] = $attempt;
        }

        return $final;
    }

    protected function bestImageName(?array $imageContext): string
    {
        if (!$imageContext) {
            return '';
        }

        foreach ($this->buildCandidateNames($imageContext) as $candidate) {
            return $candidate;
        }

        return '';
    }

    protected function response(string $reply, array $data): array
    {
        return [
            'reply' => $reply,
            'data' => $data,
        ];
    }

    protected function trimHistory(array $history): array
    {
        $history = array_values(array_filter($history, fn ($item) => is_array($item) && !empty($item['message'])));
        return array_slice($history, -8);
    }

    protected function normalizeImageContext(?array $context): ?array
    {
        if (!$context || !is_array($context)) {
            return null;
        }

        return [
            'barcode' => trim((string) ($context['barcode'] ?? '')) ?: null,
            'product_name' => trim((string) ($context['product_name'] ?? '')) ?: null,
            'brand' => trim((string) ($context['brand'] ?? '')) ?: null,
            'category' => trim((string) ($context['category'] ?? '')) ?: null,
            'variant' => trim((string) ($context['variant'] ?? '')) ?: null,
            'packaging' => trim((string) ($context['packaging'] ?? '')) ?: null,
            'visible_text' => trim((string) ($context['visible_text'] ?? '')) ?: null,
            'confidence' => trim((string) ($context['confidence'] ?? 'low')) ?: 'low',
            'notes' => trim((string) ($context['notes'] ?? '')) ?: null,
        ];
    }

    protected function buildIntentInput(string $message, array $history, ?array $imageContext): string
    {
        $historyText = collect($history)
            ->map(fn ($item) => strtoupper((string) ($item['role'] ?? 'user')) . ': ' . (string) ($item['message'] ?? ''))
            ->implode("\n");

        $imageText = $imageContext ? json_encode($imageContext, JSON_UNESCAPED_UNICODE) : 'null';

        return "Conversation history:\n{$historyText}\n\nImage context:\n{$imageText}\n\nCurrent user message:\n{$message}";
    }

    protected function extractBarcode(string $message): ?string
    {
        if (preg_match('/(?<!\d)(\d{8,14})(?!\d)/', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function refersToImageSubject(string $lower): bool
    {
        return (bool) preg_match('/\b(this|it|yeh|ye|isko|iss|is product|this product)\b/i', $lower);
    }

    protected function looksIngredientExplanation(string $message): bool
    {
        $lower = strtolower($message);

        return (bool) preg_match('/\bwhat\b|\bexplain\b|\bmeaning\b|\bsource\b/i', $lower)
            && (bool) preg_match('/gelatin|e471|natural flavorings?|alcohol|carmine|lecithin/i', $message)
            && !$this->looksProductSpecific($message);
    }

    protected function looksProductSpecific(string $message): bool
    {
        return $this->extractBarcode($message) !== null
            || (bool) preg_match('/\b(cadbury|dairy milk|coca cola|pepsi|sprite|nestle|kit kat|lays|snickers|tuc|lu)\b/i', $message);
    }

    protected function looksSearchIntent(string $message): bool
    {
        $lower = strtolower($message);

        return (bool) preg_match(
            '/\b(show|list|give|find|search|which|what products|products|all|every|category|brand|halal chocolates|haram drinks|mushbooh biscuits)\b/i',
            $lower
        );
    }

    protected function extractCategory(string $message): ?string
    {
        preg_match('/\b(chocolate|chocolates|biscuits|cookies|drink|drinks|beverages|chips|snacks|juice|sauce|ketchup|noodles|dairy|spices?|crackers?)\b/i', $message, $m);
        return !empty($m[1]) ? strtolower($m[1]) : null;
    }

    protected function extractBrandHint(string $message): ?string
    {
        preg_match('/\b(cadbury|nestle|pepsi|coca cola|coke|sprite|lays|kit kat|snickers|mirinda|lu|tuc)\b/i', $message, $m);
        return !empty($m[1]) ? $m[1] : null;
    }

    protected function extractOriginHint(string $message): ?string
    {
        preg_match('/\b(pakistan|india|usa|uk|turkey|malaysia|saudi|uae)\b/i', $message, $m);
        return !empty($m[1]) ? $m[1] : null;
    }

    protected function extractIncludedIngredients(string $message): array
    {
        $found = [];

        foreach (['gelatin', 'e471', 'alcohol', 'carmine', 'lecithin'] as $term) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $message)) {
                $found[] = $term;
            }
        }

        return $found;
    }

    protected function extractExcludedIngredients(string $message): array
    {
        $found = [];

        if (preg_match('/without\s+([\w\s,]+)/i', $message, $m)) {
            foreach (preg_split('/[,\s]+/', trim($m[1])) ?: [] as $part) {
                $part = strtolower(trim($part));
                if ($part !== '') {
                    $found[] = $part;
                }
            }
        }

        return array_values(array_unique($found));
    }

    protected function fallbackExtractProductPhrase(string $message, ?array $imageContext = null): string
    {
        $value = trim($message);

        $cleanup = [
            '/\bis\b/i',
            '/\bhalal\b/i',
            '/\bharam\b/i',
            '/\bmushbooh\b/i',
            '/\bmashbooh\b/i',
            '/\btell me about\b/i',
            '/\bshow me\b/i',
            '/\bwhat is\b/i',
            '/\bdetails?\b/i',
            '/\bplease\b/i',
        ];

        $value = preg_replace($cleanup, ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        if ($value === '' && !empty($imageContext['product_name'])) {
            return $this->bestImageName($imageContext);
        }

        return $value;
    }
}
