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
        $imageContext = is_array($imageContext) ? $imageContext : [];

        if ($message === '' && ! empty($imageContext['barcode'])) {
            return $this->pack(
                toolName: 'find_product_by_barcode',
                arguments: ['barcode' => (string) $imageContext['barcode']],
                source: 'image_context',
                questionFocuses: ['details'],
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.99,
            );
        }

        if ($message === '' && ! empty($imageContext['product_name'])) {
            return $this->pack(
                toolName: 'find_product_by_name',
                arguments: ['name' => (string) $imageContext['product_name'], 'image_context' => $imageContext ?? []],
                source: 'image_context',
                questionFocuses: ['details'],
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.90,
            );
        }

        try {
            $geminiResolved = $this->resolveWithGemini($message, $history, $imageContext);

            if ($this->isValidResolution($geminiResolved)) {
                return $this->normalizeGeminiResolution($geminiResolved, $message, $imageContext);
            }
        } catch (\Throwable $e) {
            Log::warning('IntentResolver fallback used.', [
                'message' => $e->getMessage(),
            ]);
        }

        return $this->resolveHeuristically($message, $history, $imageContext);
    }

    // ─────────────────────────────────────────────────────────────
    // GEMINI
    // ─────────────────────────────────────────────────────────────

    protected function resolveWithGemini(string $message, array $history = [], ?array $imageContext = null): array
    {
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => $this->buildPrompt($message, $history, $imageContext ?? []),
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ];

        $response = $this->geminiClient->generate($payload);

        $text = (string) data_get($response, 'candidates.0.content.parts.0.text', '');
        if ($text === '') {
            throw new \RuntimeException('Gemini intent response text was empty.');
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Gemini intent response was not valid JSON.');
        }

        return $decoded;
    }

    protected function buildPrompt(string $message, array $history = [], array $imageContext = []): string
    {
        $historyText = $this->historyToText($history);
        $imageContextJson = json_encode($imageContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return <<<PROMPT
You are an intent resolver for a halal product checker assistant connected to a product database.

## Your Task
1. Understand the user's REAL intent from natural language (English, Urdu, mixed).
2. Decide: is the user asking about ONE specific product, or MULTIPLE products?
3. Choose the most suitable tool.
4. Extract ALL relevant filters from the message.
5. Return STRICT JSON only — no prose, no markdown.

## Tool Selection Rules
- `find_product_by_barcode` → user mentions or scans a barcode/number
- `find_product_by_name`   → user asks about ONE specific named product
- `search_products`        → user wants a LIST, category, brand, origin, ingredient filter, status filter, recommendation, or suggestion
- `answer_without_db`      → general question not needing a product lookup

## When to use search_products
Use search_products when user asks for:
- All/any products in a category (chocolates, drinks, snacks, bakery, dairy…)
- Products from a country/origin (from Italy, made in USA, from Turkey…)
- Products with a halal/haram/unknown/mushbooh status
- Products with or without specific ingredients
- Recommendations or suggestions
- Similar products
- Browsing ("show me", "list", "any", "give me")

## Dimension Extraction
Extract these dimensions from the message:

**status_include** — halal statuses user WANTS:
- "halal" → ["halal"]
- "haram" → ["haram"]
- "safe" or "safer" → ["halal"]
- "mushbooh" or "doubtful" → ["mushbooh"]
- "unknown" → ["unknown"]

**status_exclude** — statuses to EXCLUDE:
- "not haram", "avoid haram" → ["haram"]
- "not unknown" → ["unknown"]

**origin** — country/region filter:
- Extract country names: Italy, USA, UK, Pakistan, Turkey, France, Switzerland, Belgium, Saudi Arabia, Malaysia, Australia, India, UAE…
- "from Italy" → origin: "italy"
- "Italian chocolates" → origin: "italy"

**category** — product type:
- chocolates, drinks, beverages, snacks, biscuits, cookies, bakery, dairy, sauces, condiments, candies, chips, cakes, noodles
- IMPORTANT: do NOT treat ingredient phrases as categories. Example: "products that contain lemon juice" means ingredients_include=["lemon juice"], category=null. Only use category drinks/beverages when user asks for drinks/beverages/something to drink.

**ingredients_include** — ingredients the product MUST have:
- "with sugar and salt" → ["sugar", "salt"]
- "rich in vitamins" → ["vitamin"]
- "high in protein" → ["protein"]
- "has fiber" → ["fiber"]
- Extract EVERY ingredient/nutrient the user says must be present.

**ingredients_exclude** — ingredients to AVOID:
- "without gelatin" → ["gelatin"]
- "no alcohol" → ["alcohol"]
- "dairy-free" → ["milk", "dairy", "whey"]
- "no pork" → ["pork", "lard"]

**query** — clean search text after extracting structured filters.
For "show me halal chocolates from Italy" → query: "chocolates", category: "chocolates", origin: "italy", status_include: ["halal"]

## Output Schema
Return EXACTLY this JSON shape (all fields required):
{
  "tool_name": "search_products",
  "input_arguments": {
    "name": null,
    "barcode": null,
    "query": null,
    "category": null,
    "brand": null,
    "origin": null,
    "ingredients_include": [],
    "ingredients_exclude": [],
    "status_include": [],
    "status_exclude": [],
    "limit": 12
  },
  "question_focuses": ["recommendation"],
  "requires_catalog_response": true,
  "is_multi_product": true,
  "confidence": 0.95
}

## Examples

"show me halal chocolates from Italy"
→ tool_name: search_products, category: "chocolates", origin: "italy", status_include: ["halal"]

"any haram chocolates from USA?"
→ tool_name: search_products, category: "chocolates", origin: "usa", status_include: ["haram"]

"beverages from Pakistan"
→ tool_name: search_products, category: "beverages", origin: "pakistan"

"snacks from Turkey that are safe for Muslims"
→ tool_name: search_products, category: "snacks", origin: "turkey", status_include: ["halal"]

"dairy-free products from UK"
→ tool_name: search_products, origin: "uk", ingredients_exclude: ["milk","dairy","whey"]

"chocolates from Switzerland"
→ tool_name: search_products, category: "chocolates", origin: "switzerland"

"haram products from France"
→ tool_name: search_products, origin: "france", status_include: ["haram"]

"halal bakery items from Saudi Arabia"
→ tool_name: search_products, category: "bakery", origin: "saudi arabia", status_include: ["halal"]

"show items with sugar and salt"
→ tool_name: search_products, ingredients_include: ["sugar", "salt"]

"items rich in vitamins"
→ tool_name: search_products, ingredients_include: ["vitamin"]

"give me list of haram items"
→ tool_name: search_products, status_include: ["haram"]

"is Dairy Milk halal?"
→ tool_name: find_product_by_name, name: "Dairy Milk", question_focuses: ["halal_status"]

"does this sports drink have animal-derived ingredients?"
→ tool_name: find_product_by_name or find_product_by_barcode (from image), question_focuses: ["animal_derived_check"]

User message:
{$message}

Image context:
{$imageContextJson}

Recent history:
{$historyText}
PROMPT;
    }

    // ─────────────────────────────────────────────────────────────
    // HEURISTIC FALLBACK
    // ─────────────────────────────────────────────────────────────

    protected function resolveHeuristically(string $message, array $history = [], ?array $imageContext = null): array
    {
        $message = trim($message);
        $questionFocuses = $this->extractQuestionFocuses($message);
        $resolvedProductName = $this->extractLikelyProductName($message, $history, $imageContext ?? []);
        $barcode = $this->extractBarcode($message, $imageContext ?? []);
        $category = $this->preferReliableCategory($message, $this->extractCategory($message));
        $brand = $this->extractBrandHint($message, $history, $imageContext ?? []);
        $origins = $this->extractOrigins($message);
        $origin = $origins[0] ?? null;                        // supports single + multi origin
        $includeIngredients = $this->extractIncludedIngredients($message);
        $excludeIngredients = $this->extractExcludedIngredients($message);
        $statusInclude = $this->extractIncludedStatuses($message);
        $statusExclude = $this->extractExcludedStatuses($message);
        $includeIngredients = array_values(array_diff($includeIngredients, $excludeIngredients));
        $productNames = $this->extractProductNames($message);

        $isBrowse = $this->isBrowseIntent($message);
        $isRecommendation = $this->isRecommendationIntent($message);
        $isFilterSearch = $this->hasIngredientFilter($message)
            || $statusInclude !== []
            || $statusExclude !== []
            || $origin !== null;                                          // FIX: origin alone = search
        $isImageAnchored = $this->isQuestionAboutCurrentImageProduct($message, $imageContext ?? []);
        $isSingleProductQuestion = ! $isBrowse && ! $isRecommendation && ! $isFilterSearch
            && ($resolvedProductName !== null || $barcode !== null || $isImageAnchored);

        if (count($productNames) > 1) {
            return $this->pack(
                toolName: 'search_products',
                arguments: [
                    'query' => '',
                    'product_names' => $productNames,
                    'ingredients_include' => [],
                    'ingredients_exclude' => [],
                    'status_include' => $statusInclude,
                    'status_exclude' => $statusExclude,
                    'limit' => max($this->resolveLimit($message), count($productNames)),
                ],
                source: 'heuristic_multi_product_names',
                questionFocuses: $questionFocuses === [] ? ['details'] : $questionFocuses,
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: 0.94,
            );
        }

        if ($barcode !== null && $isSingleProductQuestion) {
            return $this->pack(
                toolName: 'find_product_by_barcode',
                arguments: ['barcode' => $barcode, 'image_context' => $imageContext ?? []],
                source: 'heuristic_barcode',
                questionFocuses: $questionFocuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.98,
            );
        }

        if ($isBrowse || $isRecommendation || $isFilterSearch || $this->isPluralSearch($message) || $category !== null || $origin !== null) {
            return $this->pack(
                toolName: 'search_products',
                arguments: [
                    'query' => $message,
                    'category' => $category,
                    'brand' => $brand,
                    'origin' => $origin,
                    'origins' => $origins,
                    'product_names' => $productNames,
                    'ingredients_include' => $includeIngredients,
                    'ingredients_exclude' => $excludeIngredients,
                    'status_include' => $statusInclude,
                    'status_exclude' => $statusExclude,
                    'limit' => $this->resolveLimit($message),
                ],
                source: 'heuristic_search',
                questionFocuses: $questionFocuses,
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: 0.92,
            );
        }

        if ($resolvedProductName !== null) {
            return $this->pack(
                toolName: 'find_product_by_name',
                arguments: ['name' => $resolvedProductName, 'image_context' => $imageContext ?? []],
                source: 'heuristic_name',
                questionFocuses: $questionFocuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.90,
            );
        }

        if (! empty($imageContext['product_name']) && $this->looksLikeFollowUpQuestion($message)) {
            return $this->pack(
                toolName: 'find_product_by_name',
                arguments: ['name' => (string) $imageContext['product_name']],
                source: 'heuristic_followup_image_name',
                questionFocuses: $questionFocuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.88,
            );
        }

        return $this->pack(
            toolName: 'answer_without_db',
            arguments: ['query' => $message],
            source: 'heuristic_fallback',
            questionFocuses: $questionFocuses === [] ? ['details'] : $questionFocuses,
            requiresCatalogResponse: false,
            isMultiProduct: false,
            confidence: 0.55,
        );
    }

    // ─────────────────────────────────────────────────────────────
    // NORMALIZATION
    // ─────────────────────────────────────────────────────────────

    protected function normalizeGeminiResolution(array $resolved, string $message, array $imageContext = []): array
    {
        $toolName = (string) Arr::get($resolved, 'tool_name', 'answer_without_db');
        $inputArguments = Arr::get($resolved, 'input_arguments', []);
        $inputArguments = is_array($inputArguments) ? $inputArguments : [];

        $questionFocuses = Arr::get($resolved, 'question_focuses', []);
        $questionFocuses = is_array($questionFocuses)
            ? array_values(array_unique(array_filter(array_map('strval', $questionFocuses))))
            : [];

        if ($questionFocuses === []) {
            $questionFocuses = $this->extractQuestionFocuses($message);
        }

        // Gemini can misread comma-separated product questions as one product.
        // Force multi-product search for prompts like: "Are these healthy: A, B, C" or "Compare A and B".
        $messageProductNames = $this->extractProductNames($message);
        if (count($messageProductNames) > 1) {
            $toolName = 'search_products';
            $inputArguments = [
                'query' => '',
                'category' => null,
                'brand' => null,
                'origin' => null,
                'origins' => $this->extractOrigins($message),
                'product_names' => $messageProductNames,
                'ingredients_include' => $this->extractIncludedIngredients($message),
                'ingredients_exclude' => $this->extractExcludedIngredients($message),
                'status_include' => $this->extractIncludedStatuses($message),
                'status_exclude' => $this->extractExcludedStatuses($message),
                'limit' => max($this->resolveLimit($message), count($messageProductNames)),
            ];
        }

        if ($toolName === 'find_product_by_barcode') {
            $barcode = (string) ($inputArguments['barcode'] ?? $this->extractBarcode($message, $imageContext) ?? '');
            $inputArguments = ['barcode' => $barcode, 'image_context' => $imageContext];
        } elseif ($toolName === 'find_product_by_name') {
            $isImageFollowUp = $this->isQuestionAboutCurrentImageProduct($message, $imageContext);
            $imageName = (string) ($imageContext['product_name'] ?? '');
            $name = $isImageFollowUp && trim($imageName) !== ''
                ? $imageName
                : (string) ($inputArguments['name'] ?? $this->extractLikelyProductName($message, [], $imageContext) ?? '');
            $inputArguments = ['name' => $name, 'image_context' => $imageContext];
        } elseif ($toolName === 'search_products') {
            // FIX: include origin and both status arrays
            $inputArguments = [
                'query'               => (string) ($inputArguments['query'] ?? $message),
                'category'            => $this->nullableString($this->preferReliableCategory($message, $inputArguments['category'] ?? null)),
                'brand'               => $this->nullableString($inputArguments['brand'] ?? $this->extractBrandHint($message, [], $imageContext)),
                'origin'              => is_array($inputArguments['origin'] ?? null) ? null : $this->nullableString($inputArguments['origin'] ?? $this->extractOrigin($message)),
                'origins'             => array_values(array_unique(array_merge(
                    $this->normalizeStringArray($inputArguments['origins'] ?? (is_array($inputArguments['origin'] ?? null) ? $inputArguments['origin'] : [])),
                    $this->extractOrigins($message)
                ))),
                'product_names'       => $this->normalizeStringArray($inputArguments['product_names'] ?? $this->extractProductNames($message)),
                'image_context'       => $imageContext,
                'ingredients_include' => array_values(array_unique(array_merge(
                    $this->normalizeStringArray($inputArguments['ingredients_include'] ?? []),
                    $this->extractIncludedIngredients($message)
                ))),
                'ingredients_exclude' => array_values(array_unique(array_merge(
                    $this->normalizeStringArray($inputArguments['ingredients_exclude'] ?? []),
                    $this->extractExcludedIngredients($message)
                ))),
                'status_include'      => $this->normalizeStringArray($inputArguments['status_include'] ?? $this->extractIncludedStatuses($message)),
                'status_exclude'      => $this->normalizeStringArray($inputArguments['status_exclude'] ?? $this->extractExcludedStatuses($message)),
                'limit'               => $this->normalizeLimit($inputArguments['limit'] ?? $this->resolveLimit($message)),
            ];
        } else {
            $toolName = 'answer_without_db';
            $inputArguments = ['query' => $message];
        }

        return $this->pack(
            toolName: $toolName,
            arguments: $inputArguments,
            source: 'gemini',
            questionFocuses: $questionFocuses,
            requiresCatalogResponse: (bool) Arr::get($resolved, 'requires_catalog_response', $toolName === 'search_products'),
            isMultiProduct: (bool) Arr::get($resolved, 'is_multi_product', $toolName === 'search_products'),
            confidence: (float) Arr::get($resolved, 'confidence', 0.85),
        );
    }

    protected function isValidResolution(array $resolved): bool
    {
        $toolName = (string) Arr::get($resolved, 'tool_name', '');

        return in_array($toolName, [
            'find_product_by_barcode',
            'find_product_by_name',
            'search_products',
            'answer_without_db',
        ], true);
    }

    protected function pack(
        string $toolName,
        array $arguments,
        string $source,
        array $questionFocuses,
        bool $requiresCatalogResponse,
        bool $isMultiProduct,
        float $confidence = 0.80
    ): array {
        $primaryFocus = $questionFocuses[0] ?? 'details';

        return [
            'tool_name'               => $toolName,
            'arguments'               => $arguments,
            'input_arguments'         => $arguments,
            'resolution_source'       => $source,
            'intent'                  => $toolName,
            'question_focus'          => $primaryFocus,
            'question_focuses'        => array_values(array_unique(array_filter($questionFocuses))),
            'requires_catalog_response' => $requiresCatalogResponse,
            'is_multi_product'        => $isMultiProduct,
            'needs_db_lookup'         => in_array($toolName, [
                'find_product_by_barcode',
                'find_product_by_name',
                'search_products',
            ], true),
            'confidence'              => max(0.0, min(1.0, $confidence)),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // EXTRACTION HELPERS
    // ─────────────────────────────────────────────────────────────

    protected function extractQuestionFocuses(string $message): array
    {
        $message = mb_strtolower($message);
        $focuses = [];

        if ($this->containsAny($message, ['ingredient', 'ingredients', 'fiber', 'fibers', 'fibre', 'contains', 'contain', 'made of', 'what is in', "what's in"])) {
            $focuses[] = 'ingredients';
            $focuses[] = 'filters';
        }

        if ($this->containsAny($message, ['health', 'healthy', 'good for health', 'harmful', 'unhealthy', 'bad for health'])) {
            $focuses[] = 'health_check';
            $focuses[] = 'suspicious_check';
        }

        if ($this->containsAny($message, ['halal', 'haram', 'permissible', 'mushbooh', 'doubtful'])) {
            $focuses[] = 'halal_status';
        }

        if ($this->containsAny($message, ['safe', 'consume', 'okay to drink', 'okay to eat', 'can i eat', 'can i drink', 'is it safe'])) {
            $focuses[] = 'safety';
        }

        if ($this->containsAny($message, ['animal', 'gelatin', 'vegan', 'vegetarian', 'carmine', 'pork'])) {
            $focuses[] = 'animal_derived_check';
        }

        if ($this->containsAny($message, ['alcohol', 'ethanol', 'wine', 'beer', 'spirit', 'rum'])) {
            $focuses[] = 'alcohol_check';
        }

        if ($this->containsAny($message, ['barcode', 'scan', 'bar code'])) {
            $focuses[] = 'barcode';
        }

        if ($this->containsAny($message, ['brand', 'company', 'maker', 'manufacturer'])) {
            $focuses[] = 'brand';
        }

        if ($this->containsAny($message, ['category', 'type'])) {
            $focuses[] = 'category';
        }

        if ($this->containsAny($message, ['recommend', 'suggest', 'best', 'good for', 'party', 'options', 'alternatives', 'similar'])) {
            $focuses[] = 'recommendation';
        }

        if ($focuses === []) {
            $focuses[] = 'details';
        }

        return array_values(array_unique($focuses));
    }

    protected function isBrowseIntent(string $message): bool
    {
        $message = mb_strtolower($message);

        if ($this->containsAny($message, [
            'show me all', 'show all', 'list all', 'give me all',
            'what products do you have', 'what do you have',
            'all drinks', 'all products', 'all items',
            'available drinks', 'available products',
            'give me list', 'list of',
        ])) {
            return true;
        }

        if ($this->containsAny($message, ['products', 'drinks', 'snacks', 'items', 'chocolates', 'biscuits', 'beverages'])
            && $this->containsAny($message, ['show', 'list', 'available', 'have', 'give'])) {
            return true;
        }

        return false;
    }

    protected function isRecommendationIntent(string $message): bool
    {
        $message = mb_strtolower($message);

        return $this->containsAny($message, [
            'suggest', 'recommend', 'recommend me', 'give me options',
            'party at my home', 'for party', 'best drinks', 'good drinks',
            'safer products', 'halal products you have', 'any options',
            'what can i', 'something to eat', 'something to drink',
        ]);
    }

    protected function hasIngredientFilter(string $message): bool
    {
        $message = mb_strtolower($message);

        return $this->containsAny($message, [
            'contain', 'contains', 'with ', 'without ', 'free from',
            'exclude', 'excluding', 'avoid', 'no ',
            'fiber', 'fibers', 'fibre',
            'vitamin', 'protein', 'sugar', 'salt', 'water', 'palm oil',
            'gelatin', 'alcohol', 'pork', 'dairy',
        ]);
    }

    protected function isPluralSearch(string $message): bool
    {
        $message = mb_strtolower($message);

        return $this->containsAny($message, [
            'products', 'drinks', 'items', 'options',
            'snacks', 'beverages', 'alternatives', 'chocolates',
            'biscuits', 'cookies', 'cakes', 'candies',
        ]);
    }

    protected function looksLikeFollowUpQuestion(string $message): bool
    {
        $message = mb_strtolower(trim($message));

        return $message !== '' && $this->containsAny($message, [
            'is it', 'does it', 'what about this',
            'ingredients', 'halal', 'haram', 'safe',
            'consume', 'can i', 'this product', 'this one',
        ]);
    }

    protected function isQuestionAboutCurrentImageProduct(string $message, array $imageContext): bool
    {
        if (empty($imageContext['product_name']) && empty($imageContext['barcode'])) {
            return false;
        }

        return $this->looksLikeFollowUpQuestion($message);
    }

    protected function extractBarcode(string $message, array $imageContext = []): ?string
    {
        if (! empty($imageContext['barcode'])) {
            $barcode = preg_replace('/\D+/', '', (string) $imageContext['barcode']);

            if ($barcode !== '') {
                return $barcode;
            }
        }

        if (preg_match('/\b\d{8,14}\b/', $message, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    protected function extractCategory(string $message): ?string
    {
        $messageLower = mb_strtolower($message);

        // Ingredient phrases like "contain lemon juice" must NOT become category=drink.
        $ingredientPhraseMentionsJuice = (bool) preg_match('/\b(?:contain|contains|containing|with|include|includes|having|has|have)\b[^.?!,;]*\bjuice\b/iu', $messageLower);
        $explicitDrinkIntent = (bool) preg_match('/\b(drinks?|beverages?|something\s+to\s+drink|to\s+drink|drinkable|soda|soft\s+drink|energy\s+drink)\b/iu', $messageLower);

        $map = [
            'drink'      => ['drink', 'drinks', 'beverage', 'beverages', 'soda', 'soft drink', 'energy drink'],
            'snack'      => ['snack', 'snacks', 'chips', 'crisps', 'biscuits', 'cookies'],
            'chocolate'  => ['chocolate', 'chocolates', 'candy', 'candies', 'cocoa', 'confectionery'],
            'dairy'      => ['milk', 'cheese', 'yogurt', 'yoghurt', 'dairy', 'cream'],
            'bakery'     => ['bakery', 'bread', 'cake', 'cakes', 'pastry', 'baked'],
            'sauce'      => ['sauce', 'sauces', 'ketchup', 'mayonnaise', 'condiment', 'condiments', 'dressing'],
            'noodle'     => ['noodles', 'noodle', 'pasta', 'instant noodles'],
            'cereal'     => ['cereal', 'oats', 'granola'],
        ];

        if (! $ingredientPhraseMentionsJuice && preg_match('/\bjuice\s+(products?|items?|drinks?|beverages?)\b|\b(apple|orange|mango|fruit)\s+juice\s+(products?|items?|drinks?|beverages?)\b/iu', $messageLower)) {
            return 'drink';
        }

        foreach ($map as $category => $keywords) {
            if ($this->containsAny($messageLower, $keywords)) {
                return $category;
            }
        }

        if ($explicitDrinkIntent || (! $ingredientPhraseMentionsJuice && preg_match('/\bjuices?\b/iu', $messageLower))) {
            return 'drink';
        }

        return null;
    }

    protected function preferReliableCategory(string $message, mixed $candidate): ?string
    {
        $candidate = is_string($candidate) ? trim($candidate) : null;
        $messageLower = mb_strtolower($message);

        if ($candidate !== null && $candidate !== '') {
            $normalized = mb_strtolower($candidate);
            if (in_array($normalized, ['juice', 'juices', 'drink', 'drinks', 'beverage', 'beverages'], true)
                && preg_match('/\b(?:contain|contains|containing|with|include|includes|having|has|have)\b[^.?!,;]*\bjuice\b/iu', $messageLower) === 1
                && preg_match('/\b(drinks?|beverages?|something\s+to\s+drink|to\s+drink|drinkable|soda|soft\s+drink|energy\s+drink)\b/iu', $messageLower) !== 1) {
                return null;
            }

            return $candidate;
        }

        return $this->extractCategory($message);
    }

    /**
     * FIX: Extract country/origin from message — was completely missing in heuristic path.
     */
    protected function extractOrigin(string $message): ?string
    {
        $message = mb_strtolower($message);

        // Pattern: "from Italy", "made in UK", "Italian chocolates" etc.
        $countryMap = [
            'italy'        => ['italy', 'italian', 'from italy', 'made in italy'],
            'usa'          => ['usa', 'us', 'united states', 'america', 'american', 'from usa', 'made in usa', 'from america'],
            'uk'           => ['uk', 'united kingdom', 'britain', 'british', 'england', 'english', 'from uk', 'from britain'],
            'pakistan'     => ['pakistan', 'pakistani', 'from pakistan'],
            'turkey'       => ['turkey', 'turkish', 'from turkey'],
            'france'       => ['france', 'french', 'from france'],
            'switzerland'  => ['switzerland', 'swiss', 'from switzerland'],
            'belgium'      => ['belgium', 'belgian', 'from belgium'],
            'saudi arabia' => ['saudi', 'saudi arabia', 'ksa', 'from saudi'],
            'malaysia'     => ['malaysia', 'malaysian', 'from malaysia'],
            'australia'    => ['australia', 'australian', 'from australia'],
            'india'        => ['india', 'indian', 'from india'],
            'uae'          => ['uae', 'emirates', 'dubai', 'abu dhabi', 'from uae'],
            'germany'      => ['germany', 'german', 'from germany'],
            'spain'        => ['spain', 'spanish', 'from spain'],
            'netherlands'  => ['netherlands', 'dutch', 'from netherlands', 'holland'],
            'indonesia'    => ['indonesia', 'indonesian', 'from indonesia'],
            'thailand'     => ['thailand', 'thai', 'from thailand'],
            'china'        => ['china', 'chinese', 'from china'],
            'japan'        => ['japan', 'japanese', 'from japan'],
        ];

        foreach ($countryMap as $normalized => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($message, $alias)) {
                    return $normalized;
                }
            }
        }

        return null;
    }


    protected function extractOrigins(string $message): array
    {
        $message = mb_strtolower($message);
        $countries = [];

        $countryMap = [
            'italy' => ['italy', 'italian', 'itley'],
            'usa' => ['usa', 'u.s.', 'united states', 'america', 'american'],
            'uk' => ['uk', 'u.k.', 'united kingdom', 'britain', 'british', 'england', 'english'],
            'pakistan' => ['pakistan', 'pakistani'],
            'turkey' => ['turkey', 'turkish'],
            'france' => ['france', 'french'],
            'switzerland' => ['switzerland', 'swiss'],
            'belgium' => ['belgium', 'belgian'],
            'saudi arabia' => ['saudi arabia', 'saudi', 'ksa'],
            'malaysia' => ['malaysia', 'malaysian'],
            'australia' => ['australia', 'australian', 'asutrailia', 'austrailia'],
            'india' => ['india', 'indian'],
            'uae' => ['uae', 'emirates', 'dubai', 'abu dhabi'],
            'germany' => ['germany', 'german'],
            'spain' => ['spain', 'spanish'],
            'netherlands' => ['netherlands', 'dutch', 'holland'],
            'indonesia' => ['indonesia', 'indonesian'],
            'thailand' => ['thailand', 'thai'],
            'china' => ['china', 'chinese'],
            'japan' => ['japan', 'japanese'],
        ];

        foreach ($countryMap as $normalized => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/(?<![a-z])' . preg_quote($alias, '/') . '(?![a-z])/iu', $message) === 1) {
                    $countries[] = $normalized;
                    break;
                }
            }
        }

        return array_values(array_unique($countries));
    }


    protected function extractBrandHint(string $message, array $history = [], array $imageContext = []): ?string
    {
        if (! empty($imageContext['brand_name'])) {
            return (string) $imageContext['brand_name'];
        }

        if (preg_match('/\b(?:brand|company|maker)\s+([a-z0-9][a-z0-9\s&\-]{1,40})/iu', $message, $matches) === 1) {
            return trim($matches[1]);
        }

        foreach ($history as $entry) {
            $text = trim((string) data_get($entry, 'content', data_get($entry, 'message', '')));
            if ($text !== '' && preg_match('/\b(pepsi|coca cola|cola|haribo|nestle|cadbury|lays|coles|kinder)\b/i', $text, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * FIX: Properly extract ALL included ingredients including nutrition keywords and multi-ingredient patterns.
     */
    protected function extractIncludedIngredients(string $message): array
    {
        $messageLower = mb_strtolower($message);
        $ingredients = [];

        // Known specific ingredients/substances
        $knownIngredients = [
            'fiber', 'fibers', 'fibre', 'soluble corn fiber', 'gelatin', 'pork', 'lard',
            'alcohol', 'ethanol', 'caffeine', 'sugar', 'salt', 'water', 'milk', 'whey', 'soy', 'palm oil',
            'vegetable oil', 'vegitable oil', 'wheat', 'wheat flour', 'flour', 'cocoa butter',
            'lecithin', 'cocoa', 'glucose', 'fructose', 'corn syrup', 'carmine',
            'natural flavoring', 'natural flavour', 'additive', 'additives', 'preservative', 'preservatives', 'e471', 'vanilla', 'lemon juice', 'lime juice', 'carbohydrate', 'carbohydrates', 'carbs',
        ];

        // Nutrition/health keywords
        $nutritionKeywords = [
            'vitamin'  => 'vitamin',
            'vitamins' => 'vitamin',
            'protein'  => 'protein',
            'proteins' => 'protein',
            'fiber'    => 'fiber',
            'fibre'    => 'fiber',
            'calcium'  => 'calcium',
            'iron'     => 'iron',
            'zinc'     => 'zinc',
            'omega'    => 'omega',
            'collagen' => 'collagen',
            'probiot'  => 'probiotic',
            'prebiotic'=> 'prebiotic',
        ];

        foreach ($nutritionKeywords as $keyword => $normalized) {
            if (str_contains($messageLower, $keyword)) {
                $ingredients[] = $normalized;
            }
        }

        foreach ($knownIngredients as $item) {
            if (str_contains($messageLower, $item) && ! in_array($item, $ingredients, true)) {
                $ingredients[] = $item;
            }
        }

        // Multi-ingredient capture — handles generic ingredient phrases:
        // "contain lemon juice in it", "with wheat flour, sugar and vegetable oil",
        // "containing carbohydrates". The capture stops before location/category/extra wording.
        if (preg_match_all(
            '/(?:contain|contains|containing|with|having|include|includes|must have|rich in|high in)\s+(.+?)(?=\s+(?:from|made in|inside|in it|in them|for me|please|that are|which are|category|products?$)|[.?!;]|$)/iu',
            $message,
            $allMatches
        )) {
            foreach ($allMatches[1] as $group) {
                $group = $this->cleanupIngredientPhrase($group);
                $parts = $this->splitFilterTerms($group);
                foreach ($parts as $part) {
                    $part = $this->cleanupIngredientPhrase($part);
                    if ($part !== '' && mb_strlen($part) >= 3) {
                        $ingredients[] = mb_strtolower($part);
                    }
                }
            }
        }

        return $this->normalizeIngredientAliases($ingredients);
    }

    protected function extractExcludedIngredients(string $message): array
    {
        $ingredients = [];

        // FIX: broader pattern for exclusion phrases
        if (preg_match_all(
            '/(?:without|excluding|exclude|avoid|free from|no)\s+((?:[a-z][a-z\s\-]*?)(?:\s*(?:and|,|&)\s*(?:[a-z][a-z\s\-]*?))*)/iu',
            $message,
            $allMatches
        )) {
            foreach ($allMatches[1] as $group) {
                $parts = $this->splitFilterTerms($group);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part !== '' && mb_strlen($part) >= 3) {
                        $ingredients[] = mb_strtolower($part);
                    }
                }
            }
        }

        // Shorthand patterns
        $shorthand = [
            'dairy-free'     => ['milk', 'dairy', 'whey', 'casein'],
            'gluten-free'    => ['gluten', 'wheat'],
            'pork-free'      => ['pork', 'lard', 'gelatin'],
            'alcohol-free'   => ['alcohol', 'ethanol', 'wine', 'beer'],
            'vegan'          => ['milk', 'egg', 'honey', 'gelatin', 'whey'],
        ];

        $messageLower = mb_strtolower($message);
        foreach ($shorthand as $phrase => $expanded) {
            if (str_contains($messageLower, $phrase)) {
                $ingredients = array_merge($ingredients, $expanded);
            }
        }

        $knownExclusions = ['sugar', 'salt', 'water', 'milk', 'palm oil', 'gelatin', 'alcohol', 'pork', 'lard', 'whey', 'soy', 'caffeine', 'glucose', 'fructose', 'corn syrup', 'carmine', 'e471', 'vegetable oil', 'vegitable oil', 'wheat', 'flour', 'additive', 'additives', 'preservative', 'preservatives'];
        foreach ($knownExclusions as $term) {
            if (preg_match('/\b(?:no|without|exclude|excluding|avoid|free from)\s+' . preg_quote($term, '/') . '\b/iu', $messageLower) === 1) {
                $ingredients[] = $term;
            }
        }

        return $this->normalizeIngredientAliases($ingredients);
    }

    protected function cleanupIngredientPhrase(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[“”"\'`]+/u', '', $value) ?? $value;
        $value = preg_replace('/\b(in it|in them|inside|products?|items?|things?|please|for me|that contain|that contains|which contain|which contains)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\b(from|made in|origin|country|category)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value, " \t\n\r\0\x0B,.;:!?&");
    }

    protected function normalizeIngredientAliases(array $ingredients): array
    {
        $normalized = [];

        foreach ($ingredients as $ingredient) {
            $item = mb_strtolower(trim((string) $ingredient));
            if ($item === '') {
                continue;
            }

            // Normalize aliases
            if (in_array($item, ['fiber', 'fibers', 'fibre'], true)) {
                $item = 'fiber';
            } elseif (in_array($item, ['vitamins', 'vitamin'], true)) {
                $item = 'vitamin';
            } elseif (in_array($item, ['proteins', 'protein'], true)) {
                $item = 'protein';
            } elseif (in_array($item, ['vegitable oil', 'vegitable oils', 'vegetable oils'], true)) {
                $item = 'vegetable oil';
            } elseif (in_array($item, ['wheatflour'], true)) {
                $item = 'wheat flour';
            } elseif (in_array($item, ['carbs', 'carbohydrates'], true)) {
                $item = 'carbohydrate';
            } elseif (in_array($item, ['additives'], true)) {
                $item = 'additive';
            } elseif (in_array($item, ['preservatives'], true)) {
                $item = 'preservative';
            }

            // If a greedy phrase slipped through, split it into known ingredient terms.
            $compound = $this->extractKnownIngredientsFromPhrase($item);
            if (! empty($compound)) {
                foreach ($compound as $part) {
                    $normalized[] = $part;
                }
                continue;
            }

            $normalized[] = $item;
        }

        return array_values(array_unique($normalized));
    }


    protected function extractKnownIngredientsFromPhrase(string $phrase): array
    {
        $phrase = mb_strtolower(trim($phrase));
        $found = [];

        $known = [
            'vegetable oil' => ['vegetable oil', 'vegitable oil'],
            'palm oil'      => ['palm oil', 'palmolein', 'palm olein'],
            'wheat flour'   => ['wheat flour'],
            'wheat'         => ['wheat'],
            'flour'         => ['flour'],
            'sugar'         => ['sugar', 'suger'],
            'salt'          => ['salt'],
            'water'         => ['water'],
            'milk'          => ['milk'],
            'soy'           => ['soy'],
            'cocoa'         => ['cocoa'],
            'additive'      => ['additive', 'additives'],
            'preservative'  => ['preservative', 'preservatives'],
            'lemon juice'   => ['lemon juice'],
            'carbohydrate'  => ['carbohydrate', 'carbohydrates', 'carbs'],
        ];

        foreach ($known as $normalized => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($phrase, $alias)) {
                    $found[] = $normalized;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    protected function extractIncludedStatuses(string $message): array
    {
        $message = mb_strtolower($message);
        $statuses = [];

        if (str_contains($message, 'halal')) {
            $statuses[] = 'halal';
        }

        // "give me list of haram items" — FIX: check haram as included (not excluded)
        if (preg_match('/\bharam\b/i', $message) && ! preg_match('/\b(not|avoid|no)\s+haram\b/i', $message)) {
            $statuses[] = 'haram';
        }

        if ($this->containsAny($message, ['mushbooh', 'mashbooh', 'doubtful'])) {
            $statuses[] = 'mushbooh';
        }

        if ($this->containsAny($message, ['safe', 'safer'])) {
            if (! in_array('halal', $statuses, true)) {
                $statuses[] = 'halal';
            }
        }

        if (str_contains($message, 'unknown')) {
            $statuses[] = 'unknown';
        }

        return array_values(array_unique($statuses));
    }

    protected function extractExcludedStatuses(string $message): array
    {
        $message = mb_strtolower($message);
        $statuses = [];

        if (preg_match('/(?:not|exclude|excluding|without|avoid)\s+haram/iu', $message) === 1) {
            $statuses[] = 'haram';
        }

        if (preg_match('/(?:not|exclude|excluding|without|avoid)\s+unknown/iu', $message) === 1) {
            $statuses[] = 'unknown';
        }

        return array_values(array_unique($statuses));
    }

    protected function extractLikelyProductName(string $message, array $history = [], array $imageContext = []): ?string
    {
        if (! empty($imageContext['product_name']) && $this->looksLikeFollowUpQuestion($message)) {
            return (string) $imageContext['product_name'];
        }

        if (preg_match('/\b\d{8,14}\b/', $message) === 1) {
            return null;
        }

        if ($this->isBrowseIntent($message) || $this->isRecommendationIntent($message) || $this->isPluralSearch($message)) {
            return null;
        }

        // Don't extract product name if origin/status filters are present (it's a search)
        if ($this->extractOrigin($message) !== null) {
            return null;
        }

        $statusInclude = $this->extractIncludedStatuses($message);
        if ($statusInclude !== [] && $this->isPluralSearch($message)) {
            return null;
        }

        $clean = trim($message);

        $patterns = [
            '/(?:ingredients of|ingredient of|is|about|check|lookup|find|search|tell me about)\s+(.+)/iu',
            '/(?:what is|what are)\s+(.+)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $clean, $matches) === 1) {
                $candidate = $this->cleanupProductCandidate($matches[1]);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        $direct = $this->cleanupProductCandidate($clean);

        return $direct;
    }

    protected function extractProductNames(string $message): array
    {
        $clean = trim($message);
        $clean = preg_replace('/[“”"?]+/u', '', $clean) ?? $clean;

        if (preg_match('/:\s*(.+)$/u', $clean, $m) === 1) {
            $clean = trim($m[1]);
        } elseif (preg_match('/\b(?:compare|healthy|health|check)\b\s+(.+)$/iu', $clean, $m) === 1) {
            $clean = trim($m[1]);
        } elseif (preg_match('/\b(?:are these|these products|tell me)\b.*?\b(?:healthy|health)\b[:\s]+(.+)$/iu', $clean, $m) === 1) {
            $clean = trim($m[1]);
        } else {
            return [];
        }

        $parts = preg_split('/\s*,\s*|\s+\band\b\s+/iu', $clean) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $candidate = $this->cleanupProductCandidate($part);
            if ($candidate !== null && ! $this->isMostlyQuestionWords($candidate)) {
                $names[] = $candidate;
            }
        }

        return array_values(array_unique($names));
    }

    protected function cleanupProductCandidate(string $value): ?string
    {
        $value = trim($value);
        $value = preg_replace('/\?+$/', '', $value ?? '');
        $value = preg_replace('/\b(halal|haram|ingredients|ingredient|safe|consume|drink|eat|contain|contains|products|product|show|list|suggest|recommend|party|home|from|italy|usa|uk|pakistan)\b/iu', ' ', $value ?? '');
        $value = preg_replace('/\s+/', ' ', trim((string) $value));

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) < 3) {
            return null;
        }

        if ($this->isMostlyQuestionWords($value)) {
            return null;
        }

        return $value;
    }

    protected function isMostlyQuestionWords(string $value): bool
    {
        $tokens = preg_split('/\s+/u', mb_strtolower($value)) ?: [];
        $questionWords = ['what', 'which', 'show', 'list', 'suggest', 'recommend', 'have', 'there', 'any', 'all', 'products', 'drinks', 'items'];

        $meaningful = 0;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (! in_array($token, $questionWords, true)) {
                $meaningful++;
            }
        }

        return $meaningful === 0;
    }

    protected function resolveLimit(string $message): int
    {
        if (preg_match('/\b(\d{1,2})\b/', $message, $matches) === 1) {
            $limit = (int) $matches[1];

            return max(1, min(50, $limit));
        }

        if ($this->containsAny(mb_strtolower($message), ['all', 'show me all', 'list all'])) {
            return 50;
        }

        return 12;
    }

    protected function splitFilterTerms(string $value): array
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace([' and ', '&', '/'], ',', $value);
        $parts = array_map('trim', explode(',', $value));
        $parts = array_values(array_filter($parts, fn ($item) => $item !== ''));

        return $parts;
    }

    protected function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    protected function normalizeLimit(mixed $value): int
    {
        $limit = (int) $value;

        if ($limit <= 0) {
            $limit = 12;
        }

        return max(1, min(50, $limit));
    }

    protected function historyToText(array $history): string
    {
        $lines = [];

        foreach (array_slice($history, -8) as $entry) {
            $role    = (string) data_get($entry, 'role', 'user');
            $content = trim((string) data_get($entry, 'content', data_get($entry, 'message', '')));

            if ($content !== '') {
                $lines[] = $role . ': ' . $content;
            }
        }

        return implode("\n", $lines);
    }

    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
