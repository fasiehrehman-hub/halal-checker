<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class IntentResolverService
{
    public function __construct(
        protected GeminiClient $geminiClient
    ) {
    }

    public function resolve(string $message, array $history = [], ?array $imageContext = null): array
    {
        $message = trim($message);
        $focus = $this->extractQuestionFocus($message);

        if ($message === '' && !empty($imageContext['barcode'])) {
            return $this->pack('find_product_by_barcode', [
                'barcode' => $imageContext['barcode'],
            ], 'image_context', 'details', true, false);
        }

        if ($message === '' && !empty($imageContext['product_name'])) {
            return $this->pack('find_product_by_name', [
                'name' => $this->buildImageProductPhrase($imageContext),
                'image_context' => $imageContext,
            ], 'image_context', 'details', true, false);
        }

        $barcode = $this->extractBarcode($message);
        if ($barcode !== null) {
            return $this->pack('find_product_by_barcode', [
                'barcode' => $barcode,
            ], 'regex_barcode', $focus, true, false);
        }

        $fallback = $this->fallbackIntent($message, $imageContext);

        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            return $fallback;
        }

        $system = <<<TEXT
You are Mustakshif's intent engine.

Your job:
1. Understand messy English, Urdu-English, mixed wording, short text, incomplete product requests, and indirect food-safety questions.
2. Choose exactly one tool.
3. Extract clean arguments only.
4. Never answer product facts yourself.

Available tools:
1) find_product_by_barcode
2) find_product_by_name
3) search_products
4) explain_ingredient

Useful metadata:
- question_focus: details | ingredients | barcode | alcohol_check | animal_derived_check | suspicious_check | halal_status | recommendation | similar_products
- single_product: boolean
- allow_suggestions: boolean

Rules:
- One product => find_product_by_name
- Lists / browse / recommend / suggest => search_products
- “products like this” with image context => search_products
- “anything bad in it / weird in it / should I avoid it / safe to eat” for one product => suspicious_check
- If image context has product_name/brand and user says this/it/yeh/isko/iss product => use image context
- Country aliases are normal: america = usa = united states, britain = uk, uae = united arab emirates
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
                                'question_focus' => ['type' => 'STRING'],
                                'single_product' => ['type' => 'BOOLEAN'],
                                'allow_suggestions' => ['type' => 'BOOLEAN'],
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
                                'question_focus' => ['type' => 'STRING'],
                                'single_product' => ['type' => 'BOOLEAN'],
                                'allow_suggestions' => ['type' => 'BOOLEAN'],
                            ],
                            'required' => ['name'],
                        ],
                    ],
                    [
                        'name' => 'search_products',
                        'description' => 'Search many products using query, category, brand, origin, ingredient filters and status filters.',
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
                                'status' => [
                                    'type' => 'STRING',
                                    'enum' => ['halal', 'haram', 'mushbooh'],
                                ],
                                'limit' => ['type' => 'NUMBER'],
                                'question_focus' => ['type' => 'STRING'],
                                'single_product' => ['type' => 'BOOLEAN'],
                                'allow_suggestions' => ['type' => 'BOOLEAN'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'explain_ingredient',
                        'description' => 'Explain an ingredient or additive term.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'ingredient' => ['type' => 'STRING'],
                                'question_focus' => ['type' => 'STRING'],
                            ],
                            'required' => ['ingredient'],
                        ],
                    ],
                ],
            ]],
            'toolConfig' => [
                'functionCallingConfig' => [
                    'mode' => 'ANY',
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'topP' => 0.2,
                'maxOutputTokens' => 300,
            ],
        ];

        try {
            $response = $this->geminiClient->generate($payload);
            $parts = Arr::get($response, 'candidates.0.content.parts', []);

            foreach ($parts as $part) {
                $call = $part['functionCall'] ?? null;

                if (is_array($call) && !empty($call['name'])) {
                    $args = is_array($call['args'] ?? null) ? $call['args'] : [];

                    return $this->pack(
                        $call['name'],
                        $args,
                        'gemini_function_call',
                        $args['question_focus'] ?? $focus,
                        (bool) ($args['single_product'] ?? $this->inferSingleProduct($message, $imageContext)),
                        (bool) ($args['allow_suggestions'] ?? $this->inferAllowSuggestions($message))
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('IntentResolver fallback used', ['message' => $e->getMessage()]);
        }

        return $fallback;
    }

    protected function fallbackIntent(string $message, ?array $imageContext = null): array
    {
        $lower = strtolower(trim($message));
        $focus = $this->extractQuestionFocus($message);

        if ($lower === '') {
            return $this->pack(null, [], 'empty_message', $focus, false, false);
        }

        if ($this->refersToImageSubject($lower) && !empty($imageContext['barcode'])) {
            return $this->pack('find_product_by_barcode', [
                'barcode' => $imageContext['barcode'],
            ], 'image_reference', $focus, true, false);
        }

        if ($this->isSimilarProductsRequest($message) && $imageContext) {
            return $this->pack('search_products', [
                'query' => trim(implode(' ', array_filter([
                    $imageContext['brand'] ?? '',
                    $imageContext['product_name'] ?? '',
                    $imageContext['category'] ?? '',
                    $imageContext['visible_text'] ?? '',
                ]))),
                'brand' => $imageContext['brand'] ?? null,
                'category' => $imageContext['category'] ?? null,
                'limit' => 8,
            ], 'image_similar_search', 'similar_products', false, true);
        }

        if ($this->refersToImageSubject($lower) && !empty($imageContext['product_name'])) {
            return $this->pack('find_product_by_name', [
                'name' => $this->buildImageProductPhrase($imageContext),
                'image_context' => $imageContext,
            ], 'image_reference', $focus, true, false);
        }

        if ($this->looksIngredientExplanation($message)) {
            preg_match('/gelatin|e471|natural flavorings?|alcohol|carmine|lecithin/i', $message, $m);
            return $this->pack('explain_ingredient', [
                'ingredient' => $m[0] ?? $message,
            ], 'fast_rule', 'ingredient_explainer', false, false);
        }

        if ($this->looksSearchIntent($message)) {
            return $this->pack('search_products', [
                'query' => $message,
                'category' => $this->extractCategory($message),
                'brand' => $this->extractBrandHint($message),
                'origin' => $this->extractOriginHint($message),
                'ingredients_include' => $this->extractIncludedIngredients($message),
                'ingredients_exclude' => $this->extractExcludedIngredients($message),
                'status' => preg_match('/\bharam\b/i', $message)
                    ? 'haram'
                    : (preg_match('/\bmushbooh|\bmashbooh\b/i', $message) ? 'mushbooh' : (preg_match('/\bhalal\b/i', $message) ? 'halal' : null)),
                'limit' => preg_match('/\ball\b|\bevery\b/i', $message) ? 20 : 8,
                'match_mode' => 'all',
            ], 'fast_rule', $focus, false, true);
        }

        return $this->pack('find_product_by_name', [
            'name' => $this->extractProductSubject($message, $imageContext),
            'image_context' => $imageContext,
        ], 'fast_rule', $focus, true, false);
    }

    protected function pack(?string $toolName, array $arguments, string $source, string $questionFocus, bool $singleProduct, bool $allowSuggestions): array
    {
        return [
            'tool_name' => $toolName,
            'arguments' => $arguments,
            'source' => $source,
            'question_focus' => $questionFocus,
            'single_product' => $singleProduct,
            'allow_suggestions' => $allowSuggestions,
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

    protected function extractQuestionFocus(string $message): string
    {
        $lower = strtolower($message);

        if (preg_match('/\bbarcode\b|\bcode\b/i', $lower)) return 'barcode';
        if (preg_match('/\banimal[- ]derived\b|\banimal source\b|\bfrom animals?\b|\bvegan\b|\bvegetarian\b/i', $lower)) return 'animal_derived_check';
        if (preg_match('/\balcohol(ic)?\b|\bethanol\b|\bwine\b|\bbeer\b|\brum\b|\bspirit\b/i', $lower)) return 'alcohol_check';
        if (preg_match("/\bingredients?\b|\bwhat is in\b|\bwhat'?s in\b|\bcontains?\b|\bmade of\b|\binside\b/i", $lower)) return 'ingredients';
        if (preg_match('/\bbad in it\b|\bweird\b|\bsuspicious\b|\bshould i avoid\b|\bsafe to eat\b|\bokay to eat\b/i', $lower)) return 'suspicious_check';
        if (preg_match('/\bsimilar\b|\blike this\b|\bproducts like\b/i', $lower)) return 'similar_products';
        if (preg_match('/\bsuggest\b|\brecommend\b|\bhungry\b|\bwhat should i eat\b/i', $lower)) return 'recommendation';
        if (preg_match('/\bhalal\b|\bharam\b|\bmushbooh\b|\bmashbooh\b/i', $lower)) return 'halal_status';

        return 'details';
    }

    protected function inferSingleProduct(string $message, ?array $imageContext): bool
    {
        return !$this->looksSearchIntent($message) && !$this->isSimilarProductsRequest($message);
    }

    protected function inferAllowSuggestions(string $message): bool
    {
        return $this->looksSearchIntent($message) || $this->isSimilarProductsRequest($message);
    }

    protected function refersToImageSubject(string $lower): bool
    {
        return (bool) preg_match('/\b(this|it|yeh|ye|isko|iss|is product|this product)\b/i', $lower);
    }

    protected function isSimilarProductsRequest(string $message): bool
    {
        return (bool) preg_match('/\bsimilar\b|\blike this\b|\bproducts like this\b/i', strtolower($message));
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
            || (bool) preg_match('/\b(cadbury|dairy milk|coca cola|coca-cola|coke|pepsi|sprite|nestle|kit kat|lays|snickers|kinder|amora|peek freans)\b/i', $message);
    }

    protected function looksSearchIntent(string $message): bool
    {
        return (bool) preg_match('/\b(show|list|give|find|search|which|what products|products|all|every|category|brand|suggest|recommend|hungry|snacks|biscuits|chocolates|drinks|items|from)\b/i', strtolower($message));
    }

    protected function extractCategory(string $message): ?string
    {
        preg_match('/\b(chocolate|chocolates|biscuits|biscuit|cookies|drinks|drink|beverages|chips|snacks|snack|juice|orange juice|soda|soft drink|sauce|ketchup|noodles|dairy|spices?|mayonnaise|cake)\b/i', $message, $m);
        return !empty($m[1]) ? strtolower($m[1]) : null;
    }

    protected function extractBrandHint(string $message): ?string
    {
        preg_match('/\b(cadbury|nestle|pepsi|coca cola|coca-cola|coke|sprite|lays|kit kat|snickers|mirinda|amora|kinder|peek freans)\b/i', $message, $m);
        return !empty($m[1]) ? str_replace('-', ' ', strtolower($m[1])) : null;
    }

    protected function extractOriginHint(string $message): ?string
    {
        $lower = strtolower($message);
        $map = [
            'america' => 'usa',
            'american' => 'usa',
            'united states' => 'usa',
            'us' => 'usa',
            'u.s.' => 'usa',
            'britain' => 'uk',
            'british' => 'uk',
            'england' => 'uk',
            'uk' => 'uk',
            'uae' => 'uae',
            'emirates' => 'uae',
            'australia' => 'australia',
            'australian' => 'australia',
            'pakistan' => 'pakistan',
            'france' => 'france',
            'italy' => 'italy',
            'belgium' => 'belgium',
        ];

        foreach ($map as $needle => $normalized) {
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $lower)) {
                return $normalized;
            }
        }

        return null;
    }

    protected function extractIncludedIngredients(string $message): array
    {
        $found = [];

        foreach (['gelatin', 'e471', 'alcohol', 'ethanol', 'wine', 'beer', 'rum', 'carmine', 'lecithin'] as $term) {
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

    protected function extractProductSubject(string $message, ?array $imageContext = null): string
    {
        if ($this->refersToImageSubject(strtolower(trim($message))) && $imageContext) {
            return $this->buildImageProductPhrase($imageContext);
        }

        $subject = $message;
        $patterns = [
            '/^hey\s+/i',
            "/^i'?m not sure about\s+/i",
            '/^this drink looks fine but just want to be sure,\s*/i',
            '/^does this\s+/i',
            '/^is this\s+/i',
            '/^does\s+/i',
            '/^is\s+/i',
            '/^what does\s+/i',
            '/^what is in\s+/i',
            "/^what'?s in\s+/i",
            '/^tell me ingredients of\s+/i',
            '/^give me ingredients of\s+/i',
            '/^give me barcode of\s+/i',
        ];

        foreach ($patterns as $pattern) {
            $subject = preg_replace($pattern, '', $subject) ?? $subject;
        }

        $subject = preg_replace('/\banything bad in it\b/i', '', $subject) ?? $subject;
        $subject = preg_replace('/\bshould i avoid it\b/i', '', $subject) ?? $subject;
        $subject = preg_replace('/\bis it okay to eat\b/i', '', $subject) ?? $subject;
        $subject = preg_replace('/\bingredients?\b.*$/i', '', $subject) ?? $subject;
        $subject = preg_replace('/\bbarcode\b.*$/i', '', $subject) ?? $subject;
        $subject = preg_replace('/\s+/u', ' ', trim($subject)) ?? trim($subject);

        if ($subject !== '') {
            return $subject;
        }

        return $imageContext ? $this->buildImageProductPhrase($imageContext) : trim($message);
    }

    protected function buildImageProductPhrase(?array $imageContext): string
    {
        if (!$imageContext) return '';

        return trim(implode(' ', array_filter([
            $imageContext['brand'] ?? '',
            $imageContext['product_name'] ?? '',
            $imageContext['variant'] ?? '',
        ])));
    }
}
