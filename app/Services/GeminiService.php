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

            if (!$toolName && (!empty($imageContext['product_name']) || !empty($imageContext['brand']))) {
                $toolName = 'find_product_by_name';
                $arguments = [
                    'name' => $this->bestImageName($imageContext),
                    'image_context' => $imageContext,
                ];
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

            if (!empty($imageContext) && $toolName !== 'explain_ingredient' && empty($arguments['image_context'])) {
                $arguments['image_context'] = $imageContext;
            }

            if ($toolName === 'find_product_by_name' && empty($arguments['name']) && !empty($imageContext)) {
                $arguments['name'] = $this->bestImageName($imageContext);
            }

            $arguments = $this->normalizeArgumentsByIntent($toolName, $arguments, $message);

            $lookup = $this->productLookupService->executeTool($toolName, $arguments);

            if ($this->shouldRecoverFromImage($lookup, $imageContext)) {
                $recovered = $this->attemptImageRecovery($message, $imageContext, $preferredOrigin);

                if (($recovered['status'] ?? 'not_found') === 'found' && !empty($recovered['products'])) {
                    $lookup = $recovered;
                    $toolName = $recovered['meta']['tool_name'] ?? $toolName;
                    $arguments = $recovered['meta']['tool_arguments'] ?? $arguments;
                }
            }

            $lookup = $this->enforceStrictUserExpectation($lookup, $message, $toolName, $arguments);

            $intent['tool_name'] = $toolName;
            $intent['arguments'] = $arguments;

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
                'arguments' => [
                    'barcode' => $imageContext['barcode'],
                    'image_context' => $imageContext,
                ],
                'source' => 'image_context',
            ];
        }

        if ($message === '' && (!empty($imageContext['product_name']) || !empty($imageContext['brand']))) {
            return [
                'tool_name' => 'find_product_by_name',
                'arguments' => [
                    'name' => $this->bestImageName($imageContext),
                    'image_context' => $imageContext,
                ],
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
2) find_product_by_name
3) search_products
4) explain_ingredient

Rules:
- If a barcode exists, prefer find_product_by_barcode.
- If the user asks about one product, prefer find_product_by_name.
- If the user asks for many products or a category/brand list, use search_products.
- If the user says "this", "it", "yeh", "isko", "iss product", and image context has a product/barcode, use the image context.
- If the user asks for halal snacks / halal chocolates / halal drinks, use search_products with halal_only=true and status=halal.
- If the user asks "Explain ingredient E471 and tell me if product X contains it", use find_product_by_name, not explain_ingredient.
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

            $arguments = is_array($call['args'] ?? null) ? $call['args'] : [];
            $toolName = (string) ($call['name'] ?? '');

            if ($toolName === 'explain_ingredient' && $this->hasProductAndIngredientQuestion($message, $imageContext)) {
                return [
                    'tool_name' => 'find_product_by_name',
                    'arguments' => [
                        'name' => $this->fallbackExtractProductPhrase($message, $imageContext),
                        'image_context' => $imageContext,
                    ],
                    'source' => 'gemini_corrected_product_plus_ingredient',
                ];
            }

            if ($toolName === 'search_products' && $this->isStrictHalalSearch($message)) {
                $arguments['halal_only'] = true;
                $arguments['status'] = 'halal';
            }

            return [
                'tool_name' => $toolName,
                'arguments' => $arguments,
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
                    'arguments' => [
                        'barcode' => $imageContext['barcode'],
                        'image_context' => $imageContext,
                    ],
                    'source' => 'empty_message_image_barcode',
                ];
            }

            if (!empty($imageContext['product_name']) || !empty($imageContext['brand'])) {
                return [
                    'tool_name' => 'find_product_by_name',
                    'arguments' => [
                        'name' => $this->bestImageName($imageContext),
                        'image_context' => $imageContext,
                    ],
                    'source' => 'empty_message_image_name',
                ];
            }

            return ['tool_name' => null, 'arguments' => [], 'source' => 'empty_message'];
        }

        if ($this->refersToImageSubject($lower) && !empty($imageContext['barcode'])) {
            return [
                'tool_name' => 'find_product_by_barcode',
                'arguments' => [
                    'barcode' => $imageContext['barcode'],
                    'image_context' => $imageContext,
                ],
                'source' => 'image_reference',
            ];
        }

        if ($this->refersToImageSubject($lower) && (!empty($imageContext['product_name']) || !empty($imageContext['brand']))) {
            return [
                'tool_name' => 'find_product_by_name',
                'arguments' => [
                    'name' => $this->bestImageName($imageContext),
                    'image_context' => $imageContext,
                ],
                'source' => 'image_reference',
            ];
        }

        if ($this->hasProductAndIngredientQuestion($message, $imageContext)) {
            return [
                'tool_name' => 'find_product_by_name',
                'arguments' => array_filter([
                    'name' => $this->fallbackExtractProductPhrase($message, $imageContext),
                    'image_context' => $imageContext,
                ], fn ($value) => $value !== null && $value !== ''),
                'source' => 'product_plus_ingredient_fast_rule',
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
                'arguments' => $this->buildSearchArguments($message),
                'source' => 'fast_rule',
            ];
        }

        return [
            'tool_name' => 'find_product_by_name',
            'arguments' => array_filter([
                'name' => $this->fallbackExtractProductPhrase($message, $imageContext),
                'image_context' => $imageContext,
            ], fn ($value) => $value !== null && $value !== ''),
            'source' => 'fast_rule',
        ];
    }

    protected function answerFromToolResult(string $message, array $toolResult, array $intent, ?array $imageContext = null): string
    {
        $status = $toolResult['status'] ?? 'not_found';
        $products = $toolResult['products'] ?? [];
        $ingredientExplanation = $toolResult['ingredient_explanation'] ?? null;
        $toolName = $intent['tool_name'] ?? '';
        $meta = is_array($toolResult['meta'] ?? null) ? $toolResult['meta'] : [];

        if ($status === 'error') {
            return 'Sorry, something went wrong while checking that. Please try again.';
        }

        if ($toolName === 'explain_ingredient' && $ingredientExplanation) {
            return ($ingredientExplanation['ingredient'] ?? 'This ingredient') . ': ' . ($ingredientExplanation['summary'] ?? 'No explanation available.');
        }

        if (!empty($meta['strict_halal_requested']) && !empty($meta['strict_halal_not_found'])) {
            return 'I could not find clearly verified halal products in your database for that request. I did not want to label unknown or unverified products as halal.';
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
            $decision = $product['status'] ?? ($product['decision'] ?? 'unknown');

            return "I checked that barcode. {$name} is marked as {$decision} in your database.";
        }

        if ($toolName === 'find_product_by_name') {
            $product = $products[0];
            $name = $product['name'] ?? 'This product';
            $decision = strtolower((string) ($product['status'] ?? ($product['decision'] ?? 'unknown')));
            $ingredients = trim((string) ($product['ingredients'] ?? ''));
            $origin = trim((string) ($product['origin'] ?? ''));

            $ingredientTerm = $this->extractIngredientTerm($message);

            if ($ingredientTerm !== null && $this->hasProductAndIngredientQuestion($message, $imageContext)) {
                $explanation = $this->ingredientSummary($ingredientTerm);
                $contains = $this->ingredientPresenceAnswer($ingredientTerm, $ingredients, $name);

                return strtolower($ingredientTerm) . ': ' . $explanation . ' ' . $contains;
            }

            $parts = ["{$name} is marked as {$decision} in your database."];

            if ($ingredients !== '') {
                $parts[] = 'Ingredients are available in the product record.';
            }

            if ($origin !== '') {
                $parts[] = 'Origin: ' . $origin . '.';
            }

            return implode(' ', $parts);
        }

        if ($toolName === 'search_products' && $this->isRecommendationLike($message)) {
            $top = array_slice($products, 0, 4);

            $lines = array_map(function ($product) {
                $name = (string) ($product['name'] ?? 'Unnamed product');
                $decision = strtolower((string) ($product['status'] ?? ($product['decision'] ?? 'unknown')));
                $ingredients = !empty($product['ingredients']) ? 'ingredients listed' : 'ingredients not listed';
                $origin = !empty($product['origin']) ? 'origin ' . $product['origin'] : null;

                $notes = array_values(array_filter([$origin, $ingredients]));
                $suffix = !empty($notes) ? ' (' . implode(', ', $notes) . ')' : '';

                return "{$name} — {$decision}{$suffix}";
            }, $top);

            if (!empty($meta['strict_halal_requested'])) {
                return 'Here are halal options from your database for your movie night: ' . implode('; ', $lines) . '.';
            }

            return 'Here are a few practical options from your database: ' . implode('; ', $lines) . '.';
        }

        $top = array_slice($products, 0, 5);
        $names = array_map(function ($product) {
            $name = (string) ($product['name'] ?? 'Unnamed product');
            $decision = (string) ($product['status'] ?? ($product['decision'] ?? 'unknown'));
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
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $value = preg_replace('/[^\pL\pN\s\-]+/u', ' ', $value) ?? $value;
            $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        return array_values(array_unique($candidates));
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
            && !$this->looksProductSpecific($message)
            && !$this->looksSearchIntent($message);
    }

    protected function looksProductSpecific(string $message): bool
    {
        return $this->extractBarcode($message) !== null
            || (bool) preg_match('/\b(cadbury|dairy milk|coca cola|pepsi|sprite|nestle|kit kat|lays|snickers|tuc|lu|kinder|carex|marmite)\b/i', $message)
            || (bool) preg_match('/[\'"].+[\'"]/', $message);
    }

    protected function looksSearchIntent(string $message): bool
    {
        $lower = strtolower($message);

        return (bool) preg_match(
            '/\b(show|list|give|find|search|which|what products|products|all|every|category|brand|halal|haram|mushbooh|snacks|chips|drinks|biscuits|cookies|chocolates|recommend|suggest|party|guests|movie night)\b/i',
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
        preg_match('/\b(cadbury|nestle|pepsi|coca cola|coke|sprite|lays|kit kat|snickers|mirinda|lu|tuc|kinder|carex|marmite)\b/i', $message, $m);
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
        $lower = strtolower($message);

        $patterns = [
            'without gelatin' => 'gelatin',
            'no gelatin' => 'gelatin',
            'free from gelatin' => 'gelatin',
            'without alcohol' => 'alcohol',
            'no alcohol' => 'alcohol',
            'free from alcohol' => 'alcohol',
            'without e471' => 'e471',
            'without carmine' => 'carmine',
            'without lecithin' => 'lecithin',
        ];

        foreach ($patterns as $needle => $value) {
            if (str_contains($lower, $needle)) {
                $found[] = $value;
            }
        }

        return array_values(array_unique($found));
    }

    protected function fallbackExtractProductPhrase(string $message, ?array $imageContext = null): string
    {
        if ($this->refersToImageSubject(strtolower(trim($message))) && $imageContext) {
            return $this->bestImageName($imageContext);
        }

        if (preg_match('/[\'"]([^\'"]+)[\'"]/', $message, $m)) {
            return trim((string) $m[1]);
        }

        $value = trim($message);

        $cleanup = [
            '/\bexplain ingredient\b/i',
            '/\btell me if\b/i',
            '/\bdoes product\b/i',
            '/\bcontains it\b/i',
            '/\bcontains\b/i',
            '/\bhas it\b/i',
            '/\bhas\b/i',
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
            '/\bingredient\b/i',
            '/\be\d{3,4}\b/i',
        ];

        $value = preg_replace($cleanup, ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        if ($value === '' && !empty($imageContext['product_name'])) {
            return $this->bestImageName($imageContext);
        }

        return $value;
    }

    protected function normalizeArgumentsByIntent(string $toolName, array $arguments, string $message): array
    {
        if ($toolName !== 'search_products') {
            return $arguments;
        }

        $defaults = $this->buildSearchArguments($message);

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $arguments) || $arguments[$key] === null || $arguments[$key] === '') {
                $arguments[$key] = $value;
            }
        }

        if ($this->isStrictHalalSearch($message)) {
            $arguments['halal_only'] = true;
            $arguments['status'] = 'halal';
        }

        return $arguments;
    }

    protected function buildSearchArguments(string $message): array
    {
        $strictHalal = $this->isStrictHalalSearch($message);

        return [
            'query' => $message,
            'category' => $this->extractCategory($message),
            'brand' => $this->extractBrandHint($message),
            'origin' => $this->extractOriginHint($message),
            'ingredients_include' => $this->extractIncludedIngredients($message),
            'ingredients_exclude' => $this->extractExcludedIngredients($message),
            'halal_only' => $strictHalal,
            'status' => $strictHalal
                ? 'halal'
                : (preg_match('/\bharam\b/i', $message)
                    ? 'haram'
                    : (preg_match('/\bmushbooh|\bmashbooh\b/i', $message) ? 'mushbooh' : null)),
            'limit' => preg_match('/\ball\b|\bevery\b/i', $message) ? 20 : 8,
            'match_mode' => 'all',
        ];
    }

    protected function enforceStrictUserExpectation(array $lookup, string $message, string $toolName, array $arguments): array
    {
        if ($toolName !== 'search_products') {
            return $lookup;
        }

        $strictHalal = $this->isStrictHalalSearch($message) || !empty($arguments['halal_only']) || (($arguments['status'] ?? null) === 'halal');

        if (!$strictHalal) {
            return $lookup;
        }

        $products = is_array($lookup['products'] ?? null) ? $lookup['products'] : [];
        $halalProducts = array_values(array_filter($products, function ($product) {
            $status = strtolower(trim((string) ($product['status'] ?? ($product['decision'] ?? 'unknown'))));
            return $status === 'halal';
        }));

        $lookup['meta'] = array_merge(
            is_array($lookup['meta'] ?? null) ? $lookup['meta'] : [],
            ['strict_halal_requested' => true]
        );

        if (!empty($halalProducts)) {
            $lookup['products'] = $halalProducts;
            $lookup['status'] = 'found';
            $lookup['message'] = 'Confirmed halal products found.';
            return $lookup;
        }

        $lookup['products'] = [];
        $lookup['status'] = 'not_found';
        $lookup['message'] = 'I could not find clearly verified halal products in the database for that request.';
        $lookup['meta']['strict_halal_not_found'] = true;

        return $lookup;
    }

    protected function isStrictHalalSearch(string $message): bool
    {
        $lower = strtolower($message);

        return (bool) preg_match('/\bhalal\b/i', $lower)
            || ((bool) preg_match('/\b(movie night|party|guests|snacks|chips|biscuits|drinks|cookies|chocolates)\b/i', $lower)
                && (bool) preg_match('/\bhalal|safe|safer\b/i', $lower));
    }

    protected function isRecommendationLike(string $message): bool
    {
        return (bool) preg_match('/\brecommend|suggest|party|guests|movie night|options\b/i', strtolower($message));
    }

    protected function hasProductAndIngredientQuestion(string $message, ?array $imageContext = null): bool
    {
        $ingredient = $this->extractIngredientTerm($message);

        if ($ingredient === null) {
            return false;
        }

        if (!preg_match('/\bcontain|contains|has|have|inside|in product|tell me if product|does product\b/i', strtolower($message))) {
            return false;
        }

        return $this->looksProductSpecific($message)
            || $this->refersToImageSubject(strtolower($message))
            || (!empty($imageContext['product_name']) || !empty($imageContext['brand']))
            || (bool) preg_match('/[\'"].+[\'"]/', $message);
    }

    protected function extractIngredientTerm(string $message): ?string
    {
        if (preg_match('/\b(e\d{3,4}|gelatin|natural flavorings?|alcohol|carmine|lecithin)\b/i', $message, $m)) {
            return strtolower(trim((string) $m[1]));
        }

        return null;
    }

    protected function ingredientSummary(string $ingredient): string
    {
        return match (strtolower($ingredient)) {
            'gelatin' => 'Gelatin is usually derived from animal collagen. It can be sensitive for halal, kosher, vegetarian, and vegan users.',
            'e471' => 'E471 refers to mono- and diglycerides of fatty acids. Its source may be plant or animal based, so the source matters.',
            'lecithin' => 'Lecithin is often sourced from soy or sunflower, but source confirmation can still matter for some users.',
            'carmine' => 'Carmine is a red color derived from insects, so it is not vegetarian and may be unsuitable for some users.',
            'alcohol' => 'Alcohol can be used as a flavor carrier or solvent. The product context matters for dietary and religious decisions.',
            'natural flavor', 'natural flavour', 'natural flavoring', 'natural flavorings' => 'Natural flavor is a broad label. It does not automatically mean unsafe, but the exact source is often not visible from the label alone.',
            default => 'No curated explanation is available for this ingredient yet.',
        };
    }

    protected function ingredientPresenceAnswer(string $ingredient, string $ingredients, string $productName): string
    {
        if ($ingredients === '') {
            return "I found {$productName}, but its ingredients are not available in your database, so I cannot verify whether it contains {$ingredient}.";
        }

        $needle = strtolower($ingredient);
        $haystack = strtolower($ingredients);

        if (str_contains($haystack, $needle)) {
            return "Yes, {$productName} ingredients mention {$ingredient}.";
        }

        return "No, the listed ingredients for {$productName} do not mention {$ingredient}.";
    }
}
