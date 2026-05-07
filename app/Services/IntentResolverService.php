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
        $message = $this->normalizeIntentText(trim($message));
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

        // Fast path: general ingredient explanation questions do not need product lookup.
        // Example: "What is gelatin and why is it halal sensitive?"
        $ingredientToExplain = $this->extractIngredientExplanationTerm($message);
        if ($ingredientToExplain !== null) {
            return $this->pack(
                toolName: 'explain_ingredient',
                arguments: ['ingredient' => $ingredientToExplain],
                source: 'fast_ingredient_explanation',
                questionFocuses: ['ingredient_explanation'],
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.98,
            );
        }


        $includedIngredients = $this->extractIncludedIngredients($message);
        $excludedIngredients = $this->extractExcludedIngredients($message);
        $catalogCategory = $this->preferReliableCategory($message, $this->extractCategory($message));
        $catalogOrigins = $this->extractOrigins($message);
        if ((! empty($includedIngredients) || ! empty($excludedIngredients)) && $this->isIngredientCatalogIntent($message)) {
            return $this->pack(
                toolName: 'search_products',
                arguments: [
                    'query' => $message,
                    'category' => $catalogCategory,
                    'brand' => null,
                    'origin' => ($catalogOrigins[0] ?? $this->extractOrigin($message)),
                    'origins' => $catalogOrigins,
                    'product_names' => [],
                    'ingredients_include' => $includedIngredients,
                    'ingredients_exclude' => $excludedIngredients,
                    'status_include' => [],
                    'status_exclude' => [],
                    'match_mode' => $this->resolveIngredientMatchMode($includedIngredients, $message),
                    'limit' => $this->resolveLimit($message),
                ],
                source: 'fast_explicit_ingredient_catalog_query',
                questionFocuses: ['ingredients', 'filters'],
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: 0.995,
            );
        }

        $nutritionIngredients = $this->extractNutritionIngredientIntent($message);
        if (! empty($nutritionIngredients) && $this->isNutritionCatalogIntent($message)) {
            return $this->pack(
                toolName: 'search_products',
                arguments: [
                    'query' => $message,
                    'category' => $catalogCategory,
                    'brand' => null,
                    'origin' => ($catalogOrigins[0] ?? $this->extractOrigin($message)),
                    'origins' => $catalogOrigins,
                    'product_names' => [],
                    'ingredients_include' => $nutritionIngredients,
                    'ingredients_exclude' => [],
                    'status_include' => [],
                    'status_exclude' => [],
                    'match_mode' => $this->resolveIngredientMatchMode($nutritionIngredients, $message, 'any'),
                    'limit' => $this->resolveLimit($message),
                ],
                source: 'fast_nutrition_catalog_query',
                questionFocuses: ['ingredients', 'filters'],
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: 0.99,
            );
        }
        // Fast path: direct single-product ingredient/safety questions must not become
        // ingredient catalog searches. Example: "does Sprite contain alcohol?" means
        // find Sprite, then let ProductReasoningService check Sprite's ingredients.
        $directProductName = $this->extractDirectProductNameForIngredientOrSafetyCheck($message, $imageContext);
        if ($directProductName !== null) {
            return $this->pack(
                toolName: 'find_product_by_name',
                arguments: [
                    'name' => $directProductName,
                    'image_context' => $imageContext,
                ],
                source: 'fast_direct_product_check',
                questionFocuses: $this->extractQuestionFocuses($message),
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.99,
            );
        }

        // Fast path: clear status-list queries must not go to Gemini as a loose
        // recommendation. They are strict database filters by product type/status.
        if ($this->isStrictStatusCatalogQuery($message)) {
            $statusInclude = $this->extractIncludedStatuses($message);
            $statusExclude = $this->extractExcludedStatuses($message);
            $category      = $this->preferReliableCategory($message, $this->extractCategory($message));
            $origins       = $this->extractOrigins($message);

            return $this->pack(
                toolName: 'search_products',
                arguments: [
                    'query' => $message,
                    'category' => $category,
                    'brand' => $this->extractBrandHint($message, $history, $imageContext ?? []),
                    'origin' => $origins[0] ?? $this->extractOrigin($message),
                    'origins' => $origins,
                    'product_names' => [],
                    'ingredients_include' => $this->extractIncludedIngredients($message),
                    'ingredients_exclude' => $this->extractExcludedIngredients($message),
                    'status_include' => $statusInclude,
                    'status_exclude' => $statusExclude,
                    'limit' => $this->resolveLimit($message),
                ],
                source: 'fast_status_catalog_query',
                questionFocuses: ['details'],
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: 0.99,
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

    protected function isNutritionCatalogIntent(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return false;
        }

        return preg_match('/\b(?:nutrients?|nutrition|nutritious|vitamins?|minerals?|folic\s+acid|folate|vitamin\s*b|protein|fiber|fibre|iron|calcium|zinc|magnesium|energy|healthy|rich\s+in|high\s+in|gym|workout|after\s+gym|after\s+workout)\b/iu', $lower) === 1
            && preg_match('/\b(?:show|list|suggest|recommend|find|search|give|need|want|items?|products?|options?|grocery|food|hungry|gym|workout|after\s+gym|after\s+workout)\b/iu', $lower) === 1;
    }

    protected function extractNutritionIngredientIntent(string $message): array
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return [];
        }

        $include = [];
        if (preg_match('/\b(?:vitamin\s*b|b\s*vitamin|vit\s*b)\b/iu', $lower) === 1) {
            $include[] = 'vitamin b';
        }
        if (preg_match('/\b(?:folic\s+acid|folate)\b/iu', $lower) === 1) {
            $include[] = 'folic acid';
        }
        if (preg_match('/\b(?:vitamins?|rich\s+in\s+vitamins?|high\s+in\s+vitamins?)\b/iu', $lower) === 1) {
            $include[] = 'vitamin';
        }
        foreach (['protein', 'iron', 'calcium', 'zinc', 'magnesium'] as $term) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($term, '/') . '(?![a-z0-9])/iu', $lower) === 1) {
                $include[] = $term;
            }
        }
        if (preg_match('/\b(?:fiber|fibre)\b/iu', $lower) === 1) {
            $include[] = 'fiber';
        }
        if ($include === [] && preg_match('/\b(?:nutrients?|nutrition|nutritious|healthy|energy|gym|workout)\b/iu', $lower) === 1) {
            $include[] = 'vitamin';
        }
        return array_values(array_unique(array_filter($include)));
    }

    protected function isIngredientCatalogIntent(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return false;
        }
        return preg_match('/\b(?:show|list|suggest|recommend|find|search|give|need|want|fetch|bring|items?|products?|options?|grocery|food)\b/iu', $lower) === 1
            && preg_match('/\b(?:contain|contains|containing|with|without|having|has|have|include|includes|including|must\s+have|must\s+be\s+having|rich\s+in|high\s+in|free\s+from|avoid|exclude|no\s+)\b/iu', $lower) === 1;
    }

    /**
     * Strict ingredient connector resolver.
     * AND / plus / & / + means every requested ingredient must exist in the same product.
     * OR / either / any of means at least one requested ingredient may exist.
     * Lifestyle words like nutrients, gym, healthy, minerals, vitamins must never turn
     * an explicit phrase such as "sugar and salt" into optional OR matching.
     */
    protected function resolveIngredientMatchMode(array $ingredients, string $message, ?string $fallback = null): string
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        $ingredients = array_values(array_unique(array_filter(array_map(fn ($item) => mb_strtolower(trim((string) $item)), $ingredients))));

        if (count($ingredients) <= 1) {
            return 'all';
        }

        if ($this->hasIngredientConnector($lower, $ingredients, 'and')) {
            return 'all';
        }

        if ($this->hasIngredientConnector($lower, $ingredients, 'or')) {
            return 'any';
        }

        if (preg_match('/\b(?:must\s+have|must\s+be\s+having|required|required\s+with|all\s+of|both|together|same\s+product)\b/iu', $lower) === 1) {
            return 'all';
        }

        return in_array($fallback, ['all', 'any'], true) ? $fallback : 'all';
    }

    protected function shouldUseAnyIngredientMode(array $ingredients, string $message): bool
    {
        return $this->resolveIngredientMatchMode($ingredients, $message) === 'any';
    }

    protected function hasIngredientConnector(string $message, array $ingredients, string $connector): bool
    {
        $message = ' ' . mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $message))) . ' ';
        $ingredients = array_values(array_unique(array_filter(array_map(fn ($item) => mb_strtolower(trim((string) $item)), $ingredients))));

        if (count($ingredients) < 2 || ! in_array($connector, ['and', 'or'], true)) {
            return false;
        }

        $connectorPattern = $connector === 'or' ? '(?:or|either|any\s+of)' : '(?:and|plus|&|\+)';

        for ($i = 0; $i < count($ingredients); $i++) {
            for ($j = $i + 1; $j < count($ingredients); $j++) {
                $leftForms = $this->ingredientConnectorForms($ingredients[$i]);
                $rightForms = $this->ingredientConnectorForms($ingredients[$j]);

                foreach ($leftForms as $leftForm) {
                    foreach ($rightForms as $rightForm) {
                        $left = preg_quote($leftForm, '/');
                        $right = preg_quote($rightForm, '/');

                        if ($left === '' || $right === '') {
                            continue;
                        }

                        if (preg_match('/(?<![\pL\pN])' . $left . '(?![\pL\pN])\s+' . $connectorPattern . '\s+(?<![\pL\pN])' . $right . '(?![\pL\pN])/iu', $message) === 1
                            || preg_match('/(?<![\pL\pN])' . $right . '(?![\pL\pN])\s+' . $connectorPattern . '\s+(?<![\pL\pN])' . $left . '(?![\pL\pN])/iu', $message) === 1) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    protected function ingredientConnectorForms(string $ingredient): array
    {
        $ingredient = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $ingredient)));
        if ($ingredient === '') {
            return [];
        }

        $aliases = match ($ingredient) {
            'sugar' => ['sugar', 'suger', 'glucose syrup', 'corn syrup', 'sucrose'],
            'salt' => ['salt', 'sodium', 'sodium chloride'],
            'milk' => ['milk', 'milk powder', 'milk solids'],
            'milk powder' => ['milk powder', 'milk solids', 'skimmed milk powder', 'whole milk powder'],
            'whey powder' => ['whey powder', 'whey'],
            'cocoa' => ['cocoa', 'cocoa powder', 'cocoa mass'],
            'cocoa butter' => ['cocoa butter'],
            'cocoa mass' => ['cocoa mass', 'cocoa'],
            'almond' => ['almond', 'almonds', 'almond paste', 'almond powder', 'almond oil'],
            'almonds' => ['almond', 'almonds', 'almond paste', 'almond powder', 'almond oil'],
            'hazelnut' => ['hazelnut', 'hazelnuts', 'hazel nut', 'hazel nuts'],
            'hazelnuts' => ['hazelnut', 'hazelnuts', 'hazel nut', 'hazel nuts'],
            'rice powder' => ['rice powder'],
            'rice flour' => ['rice flour'],
            'wheat flour' => ['wheat flour', 'flour'],
            'glucose syrup' => ['glucose syrup'],
            default => [],
        };

        return array_values(array_unique(array_filter(array_merge([$ingredient], $aliases))));
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
- `explain_ingredient`     → user asks what an ingredient/additive is or why it is halal-sensitive, without naming a product
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

## Complex mixed intent rule
If the user asks for a CATEGORY LIST and also says "specially/especially/specifically tell me about X", keep the main request as `search_products`.
- Put the list/category in `category`.
- Put X in `focus_product_name`.
- Do NOT turn the whole query into `find_product_by_name`.
- Do NOT use `product_names` to narrow the category list for this pattern.

Examples:
- "show me chocolates and specially tell me about Dairy Milk barcode and ingredients"
  → tool_name: search_products, category: "chocolates", focus_product_name: "Dairy Milk", question_focuses: ["barcode", "ingredients"]
- "give me list of biscuits and especially tell me about candy biscuit ingredients"
  → tool_name: search_products, category: "biscuits", focus_product_name: "candy biscuit", question_focuses: ["ingredients"]

## Dimension Extraction

### Status Filter (CRITICAL FIX)
IMPORTANT: When user explicitly asks for products with a STATUS, you MUST detect and include it.

**status_include** — statuses user WANTS to see:
- "show me halal products" → status_include: ["halal"]
- "give me haram products" → status_include: ["haram"]
- "mushbooh items" → status_include: ["mushbooh"]
- "unknown status products" → status_include: ["unknown"]
- "show me halal products" → status_include: ["halal"]
- "safe products", "safe for Muslims", "not haram", "not marked haram", "Muslim-friendly" → status_exclude: ["haram"]

**status_exclude** — statuses to EXCLUDE:
- "not haram", "avoid haram", "safe for Muslims", "not marked haram", "Muslim-friendly" → status_exclude: ["haram"]
- "not unknown" → status_exclude: ["unknown"]

**EXAMPLES THAT NEED status_include:**
- "show me halal products" ✓ status_include: ["halal"]
- "show me haram products" ✓ status_include: ["haram"]
- "show me mushbooh products" ✓ status_include: ["mushbooh"]
- "list all unknown products" ✓ status_include: ["unknown"]
- "halal chocolates from Italy" ✓ status_include: ["halal"], category: "chocolates", origin: "italy"

**Brand/Category/Origin Extraction:**
- "chocolates from Italy" → category: "chocolate", origin: "italy"
- "Pepsi products" → brand: "pepsi"
- "Pakistani spices" → origin: "pakistan", category: "spices"

**Ingredient Extraction:**
- "products with sugar and salt" → ingredients_include: ["sugar", "salt"]
- "products without gelatin" → ingredients_exclude: ["gelatin"]
- "dairy-free items" → ingredients_exclude: ["milk", "dairy"]



## Critical Routing Guards
- Explicit category words in the user message must win over ingredient guesses.
  Example: "show drinks with cocoa and sugar" → search_products, category: "drinks", ingredients_include: ["cocoa", "sugar"], match_mode: "all". Do NOT turn it into chocolates.
- Origin wording must be origin, not brand.
  Example: "products from UK" or "UK products" → search_products, origin: "uk", brand: null, query: null/empty.
- Ingredient OR must stay optional.
  Example: "products with almond or hazelnut" → ingredients_include: ["almond", "hazelnut"], match_mode: "any".
- Ingredient AND must stay strict.
  Example: "products with cocoa and sugar" → ingredients_include: ["cocoa", "sugar"], match_mode: "all".

## Output Schema
Return EXACTLY this JSON shape (all fields required):
{
  "tool_name": "search_products",
  "input_arguments": {
    "name": null,
    "barcode": null,
    "query": null,
    "focus_product_name": null,
    "category": null,
    "brand": null,
    "origin": null,
    "ingredients_include": [],
    "ingredients_exclude": [],
    "match_mode": "all",
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

"show me halal products"
→ tool_name: search_products, status_include: ["halal"], limit: 12

"show me haram products"
→ tool_name: search_products, status_include: ["haram"], limit: 12

"show me mushbooh products"
→ tool_name: search_products, status_include: ["mushbooh"], limit: 12

"give me unknown status items"
→ tool_name: search_products, status_include: ["unknown"], limit: 12

"show me halal chocolates from Italy"
→ tool_name: search_products, category: "chocolate", origin: "italy", status_include: ["halal"]

"haram products from USA"
→ tool_name: search_products, origin: "usa", status_include: ["haram"]

"beverages from Pakistan"
→ tool_name: search_products, category: "beverages", origin: "pakistan"

"any halal snacks from Turkey"
→ tool_name: search_products, category: "snacks", origin: "turkey", status_include: ["halal"]

"show items with sugar and salt"
→ tool_name: search_products, ingredients_include: ["sugar", "salt"], match_mode: "all"

"show items with sugar or salt"
→ tool_name: search_products, ingredients_include: ["sugar", "salt"], match_mode: "any"

"dairy-free products"
→ tool_name: search_products, ingredients_exclude: ["milk", "dairy", "whey"]

"is Dairy Milk halal?"
→ tool_name: find_product_by_name, name: "Dairy Milk"

"does Sprite contain alcohol?"
→ tool_name: find_product_by_name, name: "Sprite", question_focuses: ["ingredients", "filters", "alcohol_check"]

"Does Dairy Milk contain gelatin?"
→ tool_name: find_product_by_name, name: "Dairy Milk", question_focuses: ["ingredients", "filters", "animal_derived_check"]

"is Sprite safe for Muslims?"
→ tool_name: find_product_by_name, name: "Sprite", question_focuses: ["safety", "halal_status", "ingredients"]

User message:
{$message}

Image context:
{$imageContextJson}

Recent history:
{$historyText}
PROMPT;
    }

    protected function isStrictStatusCatalogQuery(string $message): bool
    {
        $messageLower = mb_strtolower(trim($message));

        if ($messageLower === '' || $this->extractIncludedStatuses($messageLower) === []) {
            return false;
        }

        // Single-product questions like "is Dairy Milk halal?" must keep their
        // single product lookup behavior. This fast path is only for catalog/list
        // style requests such as "show me halal products".
        if (preg_match('/\b(is|does|do|check|tell me about|about)\b.+\b(halal|haram|mushbooh|mashbooh)\b/iu', $messageLower) === 1
            && preg_match('/\b(products?|items?|foods?|list|show|give|some|all|any)\b/iu', $messageLower) !== 1) {
            return false;
        }

        return preg_match('/\b(products?|items?|foods?|list|show|give|some|all|any|available|have|fetch|bring)\b/iu', $messageLower) === 1
            || $this->isBrowseIntent($messageLower)
            || $this->isPluralSearch($messageLower);
    }

    // ─────────────────────────────────────────────────────────────
    // HEURISTIC FALLBACK
    // ─────────────────────────────────────────────────────────────

    protected function resolveHeuristically(string $message, array $history = [], ?array $imageContext = null): array
    {
        $message = trim($message);
        $questionFocuses = $this->extractQuestionFocuses($message);

        $ingredientToExplain = $this->extractIngredientExplanationTerm($message);
        if ($ingredientToExplain !== null) {
            return $this->pack(
                toolName: 'explain_ingredient',
                arguments: ['ingredient' => $ingredientToExplain],
                source: 'heuristic_ingredient_explanation',
                questionFocuses: ['ingredient_explanation'],
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.94,
            );
        }

        $resolvedProductName = $this->extractLikelyProductName($message, $history, $imageContext ?? []);
        $barcode = $this->extractBarcode($message, $imageContext ?? []);
        $category = $this->preferReliableCategory($message, $this->extractCategory($message));
        $brand = $this->extractBrandHint($message, $history, $imageContext ?? []);
        $origins = $this->extractOrigins($message);
        $origin = $origins[0] ?? null;
        $includeIngredients = $this->extractIncludedIngredients($message);
        $excludeIngredients = $this->extractExcludedIngredients($message);

        // FIX: Properly extract status filters for ALL cases
        $statusInclude = $this->extractIncludedStatuses($message);
        $statusExclude = $this->extractExcludedStatuses($message);

        $includeIngredients = array_values(array_diff($includeIngredients, $excludeIngredients));
        $productNames = $this->extractProductNames($message);
        $focusProductName = $this->extractFocusedProductName($message);
        $directProductName = $this->extractDirectProductNameForIngredientOrSafetyCheck($message, $imageContext ?? []);

        if ($directProductName !== null) {
            return $this->pack(
                toolName: 'find_product_by_name',
                arguments: [
                    'name' => $directProductName,
                    'image_context' => $imageContext ?? [],
                ],
                source: 'heuristic_direct_product_check',
                questionFocuses: $questionFocuses === [] ? ['details'] : $questionFocuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.99,
            );
        }

        $isBrowse = $this->isBrowseIntent($message);
        $isRecommendation = $this->isRecommendationIntent($message);
        // FIX: Include statusInclude/statusExclude as filter indicators
        $isFilterSearch = $this->hasIngredientFilter($message)
            || ! empty($statusInclude)
            || ! empty($statusExclude)
            || $origin !== null
            || $category !== null;
        $isImageAnchored = $this->isQuestionAboutCurrentImageProduct($message, $imageContext ?? []);
        $isSingleProductQuestion = ! $isBrowse && ! $isRecommendation && ! $isFilterSearch
            && ($resolvedProductName !== null || $barcode !== null || $isImageAnchored);

        if ($focusProductName !== null && ($category !== null || $this->isBrowseIntent($message) || $this->isPluralSearch($message))) {
            $productNames = [];
            $includeIngredients = $this->removeFocusedProductNameIngredients($includeIngredients, $focusProductName);
        }

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

        // FIX: CRITICAL - Trigger search when status filters are present or it's a recommendation/browse intent
        if ($isBrowse || $isRecommendation || $isFilterSearch || $this->isPluralSearch($message) || $category !== null || $origin !== null || ! empty($statusInclude) || ! empty($statusExclude)) {
            // FIX: For recommendations with category, empty the query to avoid conflict
            $searchQuery = ($isRecommendation && $category !== null) ? '' : $message;

            return $this->pack(
                toolName: 'search_products',
                arguments: [
                    'query' => $searchQuery,
                    'focus_product_name' => $focusProductName,
                    'category' => $category,
                    'brand' => $brand,
                    'origin' => $origin,
                    'origins' => $origins,
                    'product_names' => $productNames,
                    'ingredients_include' => $includeIngredients,
                    'ingredients_exclude' => $excludeIngredients,
                    'match_mode' => $this->resolveIngredientMatchMode($includeIngredients, $message),
                    'status_include' => $statusInclude,
                    'status_exclude' => $statusExclude,
                    'limit' => $this->resolveLimit($message),
                ],
                source: 'heuristic_search',
                questionFocuses: $questionFocuses,
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: $isRecommendation ? 0.95 : 0.92,
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
        $messageFocusProductName = $this->extractFocusedProductName($message);
        $messageDirectProductName = $this->extractDirectProductNameForIngredientOrSafetyCheck($message, $imageContext);

        // Safety correction over Gemini: direct questions like "does Sprite contain alcohol?"
        // are single-product lookups, not catalog ingredient searches.
        if ($messageDirectProductName !== null && count($messageProductNames) <= 1) {
            $toolName = 'find_product_by_name';
            $inputArguments = [
                'name' => $messageDirectProductName,
                'image_context' => $imageContext,
            ];
            $questionFocuses = $questionFocuses === [] ? $this->extractQuestionFocuses($message) : $questionFocuses;
        }

        if ($messageFocusProductName !== null && ($this->isBrowseIntent($message) || $this->isPluralSearch($message) || $this->extractCategory($message) !== null)) {
            $toolName = 'search_products';
            $inputArguments = array_merge($inputArguments, [
                'query' => $message,
                'focus_product_name' => $messageFocusProductName,
                'category' => $this->preferReliableCategory($message, $inputArguments['category'] ?? $this->extractCategory($message)),
                'brand' => $inputArguments['brand'] ?? null,
                'origin' => $inputArguments['origin'] ?? $this->extractOrigin($message),
                'origins' => $inputArguments['origins'] ?? $this->extractOrigins($message),
                'product_names' => [],
                'ingredients_include' => $inputArguments['ingredients_include'] ?? $this->extractIncludedIngredients($message),
                'ingredients_exclude' => $inputArguments['ingredients_exclude'] ?? $this->extractExcludedIngredients($message),
                'status_include' => $inputArguments['status_include'] ?? $this->extractIncludedStatuses($message),
                'status_exclude' => $inputArguments['status_exclude'] ?? $this->extractExcludedStatuses($message),
                'limit' => $inputArguments['limit'] ?? $this->resolveLimit($message),
            ]);
        }

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
            $inputArguments = [
                'query'               => (string) ($inputArguments['query'] ?? $message),
                'focus_product_name'  => $this->nullableString($inputArguments['focus_product_name'] ?? $messageFocusProductName),
                'category'            => $this->nullableString($this->preferReliableCategory($message, $inputArguments['category'] ?? null)),
                'brand'               => $this->nullableString($inputArguments['brand'] ?? $this->extractBrandHint($message, [], $imageContext)),
                'origin'              => is_array($inputArguments['origin'] ?? null) ? null : $this->nullableString($inputArguments['origin'] ?? $this->extractOrigin($message)),
                'origins'             => array_values(array_unique(array_merge(
                    $this->normalizeStringArray($inputArguments['origins'] ?? (is_array($inputArguments['origin'] ?? null) ? $inputArguments['origin'] : [])),
                    $this->extractOrigins($message)
                ))),
                'product_names'       => ($this->nullableString($inputArguments['focus_product_name'] ?? $messageFocusProductName) !== null)
                    ? []
                    : $this->normalizeStringArray($inputArguments['product_names'] ?? $this->extractProductNames($message)),
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

            $inputArguments['match_mode'] = $this->resolveIngredientMatchMode(
                $inputArguments['ingredients_include'],
                $message,
                strtolower((string) ($inputArguments['match_mode'] ?? ''))
            );

            if (! empty($inputArguments['focus_product_name'])) {
                $inputArguments['ingredients_include'] = $this->removeFocusedProductNameIngredients(
                    $inputArguments['ingredients_include'],
                    (string) $inputArguments['focus_product_name']
                );
            }

            // Negative ingredient wording wins. Example:
            // "products that do not contain alcohol or gelatin" must not also include alcohol/gelatin.
            $inputArguments['ingredients_include'] = array_values(array_diff(
                $inputArguments['ingredients_include'],
                $inputArguments['ingredients_exclude']
            ));
        } elseif ($toolName === 'explain_ingredient') {
            $ingredient = (string) ($inputArguments['ingredient'] ?? $this->extractIngredientExplanationTerm($message) ?? '');
            $inputArguments = ['ingredient' => $ingredient];
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
            'explain_ingredient',
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
                'explain_ingredient',
            ], true),
            'confidence'              => max(0.0, min(1.0, $confidence)),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // EXTRACTION HELPERS
    // ─────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────
    // DIRECT SINGLE-PRODUCT CHECK HELPERS
    // ─────────────────────────────────────────────────────────────

    protected function extractDirectProductNameForIngredientOrSafetyCheck(string $message, array $imageContext = []): ?string
    {
        $raw = trim((string) preg_replace('/\s+/u', ' ', $message));
        if ($raw === '') {
            return null;
        }

        if (! empty($imageContext['product_name']) && $this->looksLikeFollowUpQuestion($raw)) {
            return (string) $imageContext['product_name'];
        }

        if (preg_match('/\b\d{8,14}\b/u', $raw) === 1) {
            return null;
        }

        // Open-ended ingredient/catalog requests must never become a fake product name.
        // Example: "I want something that has cocoa inside but should not contain alcohol"
        // is a database ingredient search, not product-name lookup for "something".
        if ($this->looksLikeOpenEndedIngredientCatalogRequest($raw)) {
            return null;
        }

        // Catalog/list/recommendation prompts must stay catalog prompts.
        // Examples: "show drinks that contain sugar", "find products without gelatin".
        if ($this->isBrowseIntent($raw) || $this->isRecommendationIntent($raw) || $this->isPluralSearch($raw)) {
            return null;
        }

        $sensitiveIngredient = '(?:alcohol|alcoholic|ethanol|gelatin|gelatine|animal[-\s]*derived|animal\s*driven|pork|lard|carmine|rennet|enzymes?|palm\s*oil|suspicious\s+ingredient|suspicious\s+ingredients?)';

        $patterns = [
            // does Sprite contain alcohol?
            '/^(?:please\s+)?(?:does|do|did|is|are)\s+(.+?)\s+(?:contain|contains|containing|has|have|with|include|includes)\s+(?:any\s+)?' . $sensitiveIngredient . '\b/iu',

            // can you check Dairymilk and tell me if it has gelatin?
            '/^(?:please\s+)?(?:can\s+you\s+)?(?:check|tell\s+me\s+about|tell\s+me|about)\s+(.+?)\s+(?:and\s+)?(?:tell\s+me\s+)?(?:if|whether)\s+(?:it|this|that|the\s+product)?\s*(?:contains?|has|have|with|includes?)\s+(?:any\s+)?' . $sensitiveIngredient . '\b/iu',

            // I scanned Sprite and now tell me if it contains alcohol
            '/\b(?:i\s+scanned|scanned|scan)\s+(.+?)\s+(?:and\s+now|now|and|,|\s+please)\s+(?:tell|check|give|show)\b/iu',

            // is Sprite safe for Muslims? / is Dairy Milk halal?
            '/^(?:please\s+)?(?:is|are)\s+(.+?)\s+(?:halal|haram|safe|safe\s+for\s+muslims?|ok(?:ay)?\s+for\s+muslims?|muslim[-\s]*friendly|permissible)\b/iu',

            // can I buy Sprite / can I drink Sprite / can I eat this product
            '/^(?:can\s+i\s+(?:buy|eat|drink|consume))\s+(.+?)(?:\s+(?:for|before|or|please)\b|[.?!;]|$)/iu',

            // tell me about Sprite ingredients / Sprite barcode and halal status
            '/^(?:please\s+)?(?:tell\s+me\s+about|check|lookup|find|search)\s+(.+?)\s+(?:barcode|bar\s*code|ingredients?|halal\s+status|status|details?|origin)\b/iu',
            '/^(.+?)\s+(?:barcode|bar\s*code|ingredients?|halal\s+status|status|details?|origin)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $raw, $matches) !== 1) {
                continue;
            }

            $candidate = $this->cleanupDirectProductCheckCandidate((string) ($matches[1] ?? ''), $imageContext);
            if ($candidate !== null) {
                return $this->normalizeCommonProductNameTypos($candidate);
            }
        }

        return null;
    }

    protected function cleanupDirectProductCheckCandidate(string $candidate, array $imageContext = []): ?string
    {
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));
        $candidate = trim($candidate, " \t\n\r\0\x0B,.;:!?&");

        if ($candidate === '') {
            return null;
        }

        $lower = mb_strtolower($candidate);

        if (in_array($lower, ['it', 'this', 'that', 'this product', 'that product', 'product', 'item', 'this item', 'that item'], true)) {
            return ! empty($imageContext['product_name']) ? (string) $imageContext['product_name'] : null;
        }

        // Do not allow a multi-product phrase to be treated as one product.
        if (preg_match('/\s*,\s*|\s+and\s+/iu', $candidate) === 1) {
            return null;
        }

        // Strip conversational wrappers that users naturally add before the product name.
        $candidate = preg_replace('/^(?:please\s+)?(?:can|could|would)\s+you\s+(?:please\s+)?(?:check|tell\s+me\s+about|tell\s+me|look\s+up|lookup|find|search)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^(?:please\s+)?(?:check|tell\s+me\s+about|tell\s+me|look\s+up|lookup|find|search)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^(?:the\s+|a\s+|an\s+)/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:product|item)\b$/iu', '', $candidate) ?? $candidate;
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));

        if ($candidate === '' || mb_strlen($candidate) < 2) {
            return null;
        }

        if (method_exists($this, 'cleanupProductCandidate')) {
            $cleaned = $this->cleanupProductCandidate($candidate);
            if (is_string($cleaned) && trim($cleaned) !== '') {
                return trim($this->normalizeCommonProductNameTypos($cleaned));
            }
        }

        return $this->normalizeCommonProductNameTypos($candidate);
    }

    protected function normalizeCommonProductNameTypos(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $name));
        $lower = mb_strtolower($clean);

        return match ($lower) {
            'sprit' => 'Sprite',
            'sprite' => 'Sprite',
            'dairymilk', 'dairy milk' => 'Dairy Milk',
            'detol' => 'Dettol',
            'dettol' => 'Dettol',
            'penut butter', 'peanut butter' => 'Peanut Butter',
            default => $clean,
        };
    }

    protected function extractIngredientExplanationTerm(string $message): ?string
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return null;
        }

        // Product/list requests should not be converted into ingredient explanations.
        if (preg_match('/\b(?:product|products|item|items|barcode|brand|origin|from|made\s+in|show|list|find|search|suggest|recommend)\b/iu', $lower) === 1) {
            return null;
        }

        $known = '(gelatin|gelatine|e471|carmine|e120|lecithin|rennet|whey|casein|alcohol|ethanol|natural\s+flavou?r(?:ing)?s?|enzymes?|animal\s+derived|pork|lard)';

        if (preg_match('/^(?:what\s+is|what\s+are|explain|tell\s+me\s+about|is)\s+' . $known . '\b/iu', $lower, $matches) === 1) {
            return $this->normalizeIngredientExplanationTerm((string) $matches[1]);
        }

        if (preg_match('/\b' . $known . '\b[^.?!]{0,80}\b(?:halal|haram|sensitive|doubtful|mushbooh|source|safe)\b/iu', $lower, $matches) === 1) {
            return $this->normalizeIngredientExplanationTerm((string) $matches[1]);
        }

        return null;
    }

    protected function normalizeIngredientExplanationTerm(string $term): string
    {
        $term = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($term))));

        return match ($term) {
            'gelatine' => 'gelatin',
            'e120' => 'carmine',
            'natural flavour', 'natural flavouring', 'natural flavourings', 'natural flavor', 'natural flavoring' => 'natural flavorings',
            default => $term,
        };
    }

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

        if ($this->containsAny($message, ['safe', 'safety', 'muslim friendly', 'muslim-friendly', 'safe for muslims', 'consume', 'okay to drink', 'okay to eat', 'can i eat', 'can i drink', 'is it safe'])) {
            $focuses[] = 'safety';
            $focuses[] = 'halal_status';
            $focuses[] = 'ingredients';
        }

        if ($this->containsAny($message, ['animal', 'gelatin', 'vegan', 'vegetarian', 'carmine', 'pork'])) {
            $focuses[] = 'animal_derived_check';
        }

        if ($this->containsAny($message, ['alcohol', 'alcohal', 'alcahol', 'alchol', 'ethanol', 'wine', 'beer', 'spirit', 'rum'])) {
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
            'all drinks', 'all products', 'all items', 'all dairy', 'all chocolates',
            'available drinks', 'available products', 'available dairy',
            'give me list', 'list of', 'list down', 'looking for', 'look for', 'searching for',
        ])) {
            return true;
        }

        if ($this->containsAny($message, ['products', 'drinks', 'juices', 'snacks', 'items', 'chocolates', 'biscuits', 'beverages', 'candies', 'dairy', 'cheese', 'butter', 'meat', 'meats', 'beef', 'chicken', 'sauces', 'spices', 'bread'])
            && $this->containsAny($message, ['show', 'list', 'available', 'have', 'give', 'need', 'want', 'find', 'search', 'fetch', 'bring', 'looking for', 'look for', 'searching for'])) {
            return true;
        }

        return false;
    }

    protected function isRecommendationIntent(string $message): bool
    {
        $message = mb_strtolower($message);

        // FIX: CRITICAL - Detect "suggest me [category]" patterns like "suggest me biscuits", "suggest me cookies"
        if (preg_match('/\b(suggest|recommend)\s+(?:me|some|any)\s+(?:biscuits?|cookies?|chocolates?|drinks?|juices?|snacks?|cakes?|candies?|sweets?|beverages?|dairy|cheese|butter|sauces?|spices?|breads?|meat|meats|beef|chicken|poultry|items?|products?)\b/iu', $message)) {
            return true;
        }

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
            'contain', 'contains', 'containing', 'with ', 'without ', 'free from',
            'do not contain', 'does not contain', 'not contain', "don't contain", 'dont contain',
            'exclude', 'excluding', 'avoid', 'no ',
            'fiber', 'fibers', 'fibre',
            'vitamin', 'protein', 'sugar', 'salt', 'sodium', 'water', 'palm oil',
            'spices', 'spice', 'spicy', 'masala', 'seasoning', 'seasonings', 'chili', 'chilli', 'paprika', 'pepper',
            'gelatin', 'gelatine', 'alcohol', 'alcoholic', 'pork', 'dairy', 'animal derived', 'animal driven',
        ]);
    }

    protected function isPluralSearch(string $message): bool
    {
        $message = mb_strtolower($message);

        return $this->containsAny($message, [
            'products', 'drinks', 'juices', 'items', 'options',
            'snacks', 'beverages', 'alternatives', 'chocolates',
            'biscuits', 'cookies', 'cakes', 'candies', 'sweets',
            'dairy', 'cheese', 'butter', 'sauces', 'spices', 'spicy', 'masala', 'seasoning', 'bread',
            'meat', 'meats', 'beef', 'chicken', 'poultry',
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
            $barcode = $this->cleanBarcodeCandidate((string) $imageContext['barcode']);
            if ($barcode !== null) {
                return $barcode;
            }
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if (preg_match('/\bbar\s*code\b[^a-z0-9]{0,25}([a-z0-9][a-z0-9\-_.\s]{1,60})/iu', $text, $matches) === 1) {
            $barcode = $this->cleanBarcodeCandidate((string) $matches[1]);
            if ($barcode !== null) {
                return $barcode;
            }
        }
        if (preg_match('/([a-z0-9][a-z0-9\-_.]{2,60})[^.?!;]{0,30}\bbar\s*code\b/iu', $text, $matches) === 1) {
            $barcode = $this->cleanBarcodeCandidate((string) $matches[1]);
            if ($barcode !== null) {
                return $barcode;
            }
        }
        if (preg_match('/\b\d{8,40}\b/u', $text, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    protected function cleanBarcodeCandidate(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $value = preg_replace('/\b(?:tell|show|give|check|find|search|me|its|ingredient|ingredients|does|dose|contain|contains|any|harmful|substance|substances|halal|haram|status|details|please|product|item|items|from|database|db)\b.*$/iu', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B,.;:!?&");
        $compact = strtoupper((string) preg_replace('/[^A-Z0-9]+/iu', '', $value));
        if ($compact === '' || mb_strlen($compact) < 3 || mb_strlen($compact) > 60) {
            return null;
        }
        return $compact;
    }

    protected function extractCategory(string $message): ?string
    {
        $messageLower = mb_strtolower($message);

        $ingredientPhraseMentionsJuice = (bool) preg_match('/\b(?:contain|contains|containing|with|include|includes|having|has|have)\b[^.?!,;]*\bjuice\b/iu', $messageLower);
        $explicitDrinkIntent = (bool) preg_match('/\b(drinks?|beverages?|something\s+to\s+drink|to\s+drink|drinkable|soda|soft\s+drink|energy\s+drink)\b/iu', $messageLower);
        $palmOilIngredientQuery = preg_match('/\b(?:with|contain|contains|containing|has|have|having|include|includes|including)\b[^.?!;]{0,100}\bpalm\s+oil\b|\bpalm\s+oil\b[^.?!;]{0,100}\b(?:inside|in\s+it|as\s+ingredients?|ingredients?)\b/iu', $messageLower) === 1;
        $spiceIngredientQuery = preg_match('/\b(?:with|contain|contains|containing|has|have|having|include|includes|including)\b[^.?!;]{0,140}\b(?:spicy|spices?|masala|seasonings?|curry|chilli?|chili|paprika|pepper)\b|\b(?:spicy|spices?|masala|seasonings?|curry|chilli?|chili|paprika|pepper)\b[^.?!;]{0,140}\b(?:inside|inside\s+ingredients?|in\s+ingredients?|as\s+ingredients?|ingredients?)\b/iu', $messageLower) === 1;

        $map = [
            'household'  => ['household', 'cleaning', 'cleaner', 'washroom', 'bathroom', 'kitchen', 'hand wash', 'handwash', 'hand washes', 'handwashes', 'hand soap', 'soap', 'antiseptic', 'disinfectant', 'detergent', 'bleach', 'dettol', 'detol', 'carex'],
            'drink'      => ['drink', 'drinks', 'beverage', 'beverages', 'soda', 'soft drink', 'energy drink', 'cola'],
            'dairy_alternatives' => ['dairy alternative', 'dairy alternatives', 'plant based milk', 'non dairy', 'non-dairy', 'dairy free', 'almond milk', 'soy milk', 'soya milk', 'oat milk', 'rice milk', 'coconut milk'],
            'cheese'     => ['cheese', 'cheeses', 'cheddar', 'mozzarella', 'parmesan'],
            // Keep narrow food types separate so "biscuits" does not become generic snacks
            // and "cakes" does not become generic bakery.
            'biscuits'   => ['biscuit', 'biscuits', 'cookie', 'cookies', 'cracker', 'crackers', 'wafer', 'wafers'],
            'cakes'      => ['cake', 'cakes', 'cupcake', 'cupcakes'],
            'bread'      => ['bread', 'breads', 'loaf', 'loaves', 'toast'],
            'chocolates' => ['chocolate', 'chocolates', 'cocoa', 'confectionery', 'ferrero', 'rocher', 'dairy milk', 'kinder'],
            'candies'    => ['candy', 'candies', 'gummy', 'gummies', 'sweet', 'sweets'],
            'beef'       => ['beef'],
            'chicken'    => ['chicken', 'poultry'],
            'meats'      => ['meat', 'meats', 'mutton', 'lamb', 'sausage', 'sausages'],
            'snack'      => ['snack', 'snacks', 'chips', 'crisps'],
            'dairy'      => ['butter', 'yogurt', 'yoghurt', 'dairy', 'cream'],
            'bakery'     => ['bakery', 'pastry', 'baked'],
            'sauce'      => ['sauce', 'sauces', 'ketchup', 'mayonnaise', 'mayonese', 'mayounese', 'mayo', 'condiment', 'condiments', 'dressing', 'soy sauce'],
            'oils'       => ['oil', 'oils', 'cooking oil', 'edible oil', 'olive oil', 'sunflower oil', 'canola oil', 'palm oil', 'coconut oil'],
            'sweeteners' => ['sweetener', 'sweeteners', 'sweetner', 'sweetners', 'sugar products', 'honey', 'syrup', 'stevia', 'aspartame', 'sucralose'],
            'spices'     => ['spice', 'spices', 'masala', 'seasoning', 'seasonings', 'mix'],
            'noodle'     => ['noodles', 'noodle', 'pasta', 'instant noodles'],
            'cereal'     => ['cereal', 'oats', 'granola'],
            'electronics'=> ['electronic', 'electronics', 'electrical', 'electricals', 'battery', 'batteries', 'charger', 'chargers', 'cable', 'cables'],
        ];

        if (! $ingredientPhraseMentionsJuice && preg_match('/\bjuice\s+(products?|items?|drinks?|beverages?)\b|\b(apple|orange|mango|fruit)\s+juice\s+(products?|items?|drinks?|beverages?)\b/iu', $messageLower)) {
            return 'drink';
        }

        foreach ($map as $category => $keywords) {
            if ($category === 'oils' && $palmOilIngredientQuery && ! preg_match('/\b(?:show|list|find|need|want|give)\b[^.?!;]{0,60}\b(?:oils|cooking\s+oil|edible\s+oil|olive\s+oil|sunflower\s+oil|canola\s+oil|coconut\s+oil)\b/iu', $messageLower)) {
                continue;
            }

            if ($category === 'spices' && $spiceIngredientQuery) {
                continue;
            }

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

        if (preg_match('/\bdairy[-\s]*free\b/iu', $messageLower) === 1
            && preg_match('/\bdairy\s+alternatives?\b/iu', $messageLower) !== 1) {
            return null;
        }

        if (preg_match('/\bbeef\b/iu', $messageLower) === 1) {
            return 'beef';
        }

        if (preg_match('/\b(chicken|poultry)\b/iu', $messageLower) === 1) {
            return 'chicken';
        }

        if (preg_match('/\b(dairy\s+alternatives?|plant\s*based\s+milk|non\s*dairy|dairy\s*free|almond\s+milk|soy\s+milk|soya\s+milk|oat\s+milk|rice\s+milk|coconut\s+milk)\b/iu', $messageLower) === 1) {
            return 'dairy_alternatives';
        }

        if (preg_match('/\b(cheese|cheeses|cheddar|mozzarella|parmesan)\b/iu', $messageLower) === 1) {
            return 'cheese';
        }

        if (preg_match('/\b(dairy\s+products?|dairy\s+items?|butter|yogurts?|yoghurts?)\b/iu', $messageLower) === 1) {
            return 'dairy';
        }

        if (preg_match('/\b(household|clean(?:ing|er)?|washroom|bathroom|kitchen|hand\s*washes?|handwash(?:es)?|hand\s*soap|soap|antiseptic|disinfectant|detergent|bleach|dettol|detol|carex)\b/iu', $messageLower) === 1) {
            return 'household';
        }

        $palmOilIngredientQuery = preg_match('/\b(?:with|contain|contains|containing|has|have|having|include|includes|including)\b[^.?!;]{0,100}\bpalm\s+oil\b|\bpalm\s+oil\b[^.?!;]{0,100}\b(?:inside|in\s+it|as\s+ingredients?|ingredients?)\b/iu', $messageLower) === 1;
        if (preg_match('/\b(oils?|cooking\s+oil|edible\s+oil|olive\s+oil|sunflower\s+oil|canola\s+oil|palm\s+oil|coconut\s+oil)\b/iu', $messageLower) === 1
            && preg_match('/\b(show|list|give|find|need|want|products?|items?)\b/iu', $messageLower) === 1
            && (! $palmOilIngredientQuery || preg_match('/\b(?:show|list|find|need|want|give)\b[^.?!;]{0,60}\b(?:oils|cooking\s+oil|edible\s+oil|olive\s+oil|sunflower\s+oil|canola\s+oil|coconut\s+oil)\b/iu', $messageLower))) {
            return 'oils';
        }

        if (preg_match('/\b(sweeteners?|sweetner|sweetners|sugar\s+products?|honey|syrup|stevia|aspartame|sucralose)\b/iu', $messageLower) === 1) {
            return 'sweeteners';
        }

        if (preg_match('/\b(condiments?|sauces?|mayonnaise|mayonese|mayounese|mayo|ketchup|soy\s+sauce)\b/iu', $messageLower) === 1) {
            return 'sauce';
        }

        if (preg_match('/\b(electronics?|electricals?|batter(?:y|ies)|charger|chargers|cable|cables)\b/iu', $messageLower) === 1) {
            return 'electronics';
        }

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

    protected function extractOrigin(string $message): ?string
    {
        $origins = $this->extractOrigins($message);
        return $origins[0] ?? null;
    }

    protected function extractOrigins(string $message): array
    {
        $message = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($message === '') {
            return [];
        }

        $found = [];
        foreach ($this->extractLooseOriginPhrases($message) as $candidate) {
            $candidate = $this->cleanLooseOriginPhrase($candidate);
            if ($candidate !== '' && ! $this->isBlockedLooseOriginPhrase($candidate)) {
                $found[] = $candidate;
            }
        }

        return array_values(array_unique(array_filter($found)));
    }

    /**
     * Origin extraction must not depend on a hardcoded country map.
     * Capture only the user's explicit origin phrase and let ProductLookupService
     * match it against the DB origin column with punctuation-insensitive logic.
     */
    protected function extractLooseOriginPhrases(string $message): array
    {
        $message = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($message === '') {
            return [];
        }

        $phrases = [];
        $patterns = [
            '/\bfrom\s+([\pL\pN][\pL\pN\s._\-\'’]{1,90}?)(?=\s*(?:$|[,.?!;؟]|\b(?:only|with|without|that|which|where|and\s+(?:show|list|find|check|tell|give|also|from)|but|like|for\s+(?:halal|haram|ingredients?|barcode|alcohol|gelatin|gelatine))\b))/iu',
            '/\b(?:made\s+in|origin(?:\s+is|\s+from)?|country(?:\s+is|\s+from)?)\s+([\pL\pN][\pL\pN\s._\-\'’]{1,90}?)(?=\s*(?:$|[,.?!;؟]|\b(?:only|with|without|that|which|where|and\s+(?:show|list|find|check|tell|give|also|from)|but|like|for\s+(?:halal|haram|ingredients?|barcode|alcohol|gelatin|gelatine))\b))/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $message, $matches)) {
                foreach ($matches[1] ?? [] as $match) {
                    $candidate = $this->cleanLooseOriginPhrase((string) $match);
                    if ($candidate !== '') {
                        $phrases[] = $candidate;
                    }
                }
            }
        }

        return array_values(array_unique($phrases));
    }

    protected function cleanLooseOriginPhrase(string $value): string
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($value))));
        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\b(?:only|products?|items?|foods?|options?|available|origin|country|made|database|records)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value, " \t\n\r\0\x0B,.;:!?؟");
    }

    protected function isBlockedLooseOriginPhrase(string $value): bool
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($value))));
        if ($value === '' || mb_strlen($value) < 2) {
            return true;
        }

        return preg_match('/^(?:me|my|our|your|the|a|an|some|any|all|product|products|item|items|food|foods|options?|halal|haram|mushbooh|unknown|safe|not\s+haram|database|records)$/iu', $value) === 1;
    }

    protected function extractBrandHint(string $message, array $history = [], array $imageContext = []): ?string
    {
        if (! empty($imageContext['brand_name'])) {
            return (string) $imageContext['brand_name'];
        }

        $message = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        $lower = mb_strtolower($message);

        $patterns = [
            '/\b(?:fan\s+of|huge\s+fan\s+of|love|like|prefer|interested\s+in|looking\s+for)\s+([a-z0-9][a-z0-9\s&\-\'’]{1,60})\s+(?:brand\s+)?(?:products?|items?)\b/iu',
            '/\b([a-z0-9][a-z0-9\s&\-\'’]{1,60})\s+(?:brand\s+)?(?:products?|items?)\b/iu',
            '/\b(?:show|list|give|find|search|fetch|bring)\s+(?:me\s+)?(?:all\s+|some\s+|available\s+)?(?:the\s+)?(?:halal\s+|haram\s+|mushbooh\s+|unknown\s+|safe\s+|not[-\s]*haram\s+)?(?:products?|items?)\s*(?:of|by|from)\s*([a-z0-9][a-z0-9\s&\-\'’]{1,60})(?:\b|$)/iu',
            '/\b(?:show|list|give|find|search|fetch|bring)\s+(?:me\s+)?(?:all\s+|some\s+|available\s+)?(?:the\s+)?(?:halal\s+|haram\s+|mushbooh\s+|unknown\s+|safe\s+|not[-\s]*haram\s+)?(?:products?|items?)\s+of([a-z0-9][a-z0-9\s&\-\'’]{1,60})(?:\b|$)/iu',
            '/\b(?:products?|items?)\s+(?:of|by|from)\s*([a-z0-9][a-z0-9\s&\-\'’]{1,60})(?:\b|$)/iu',
            '/^(?:all\s+|some\s+|available\s+)?([a-z0-9][a-z0-9\s&\-\'’]{1,60})\s+(?:brand\s+)?(?:products?|items?)$/iu',
            '/^([a-z0-9][a-z0-9\s&\-\'’]{1,60})\s+brand$/iu',
            '/\b(?:brand|company|maker|manufacturer)\s+([a-z0-9][a-z0-9\s&\-\'’]{1,40})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower, $matches) === 1) {
                $brand = $this->cleanupBrandHintCandidate((string) ($matches[1] ?? ''));
                if ($brand !== null) {
                    return $brand;
                }
            }
        }

        // Safety net for long brand-list wording and future DB brands.
        // If a known brand token appears in an explicit brand/catalog request, return it
        // even when the generic regex failed because of typos or wrapped spaces.
        if ($this->looksLikeBrandCatalogQueryText($lower)) {
            $brandMap = [
                'woolworths' => '/(?<![a-z0-9])wool\s*worths?(?![a-z0-9])/iu',
                'coles' => '/(?<![a-z0-9])coles(?![a-z0-9])/iu',
                'mcvitie\'s' => '/(?<![a-z0-9])mcvitie\'?s(?![a-z0-9])/iu',
                'haribo' => '/(?<![a-z0-9])haribo(?![a-z0-9])/iu',
                'nestle' => '/(?<![a-z0-9])nestle(?![a-z0-9])/iu',
                'cocol' => '/(?<![a-z0-9])cocol(?![a-z0-9])/iu',
                'pepsi' => '/(?<![a-z0-9])pepsi(?![a-z0-9])/iu',
                'buttermilk' => '/(?<![a-z0-9])buttermilk(?![a-z0-9])/iu',
                'barilla' => '/(?<![a-z0-9])barilla(?![a-z0-9])/iu',
                'alpro' => '/(?<![a-z0-9])alpro(?![a-z0-9])/iu',
                'amora' => '/(?<![a-z0-9])amora(?![a-z0-9])/iu',
            ];

            foreach ($brandMap as $brand => $pattern) {
                if (preg_match($pattern, $lower) === 1) {
                    return $brand;
                }
            }
        }

        // History brand fallback disabled: current explicit brand prompts must not inherit a previous brand.
        return null;
    }

    protected function looksLikeBrandCatalogQueryText(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));

        return $lower !== ''
            && preg_match('/\b(?:brand\s+(?:products?|items?)|(?:products?|items?)\s*(?:of|by|from)|all\s+[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)|show\s+(?:me\s+)?(?:all\s+)?[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)|(?:fan\s+of|huge\s+fan\s+of|love|like|prefer|interested\s+in|looking\s+for)\s+[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)|[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)\s+(?:are\s+|is\s+)?(?:halal|haram|mushbooh|unknown|safe|available|show|list|give|find|search))\b/iu', $lower) === 1;
    }

    protected function cleanupBrandHintCandidate(string $candidate): ?string
    {
        $candidate = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $candidate)));
        $candidate = preg_replace('/^.*\b(?:fan\s+of|huge\s+fan\s+of|love|like|prefer|interested\s+in|looking\s+for)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:so\s+that|because|for\s+my|to\s+add|add\s+it|add\s+them|grocery\s+list|groccry\s+list).*$/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:its|their|all\s+the|all|some|available|brand|brands|product|products|items|item|show|list|give|find|search|fetch|bring|me|of|by|from|the|a|an|is|are|do|does|can|could|would)\b/iu', ' ', $candidate) ?? $candidate;
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));
        $candidate = trim($candidate, " \t\n\r\0\x0B,.;:!?&");

        if ($candidate === '' || mb_strlen($candidate) < 2) {
            return null;
        }

        $blocked = [
            'halal', 'haram', 'mushbooh', 'unknown', 'safe', 'muslim friendly',
            'grocery', 'groccry', 'groceries', 'shopping', 'store', 'supermarket', 'food',
            'drink', 'drinks', 'beverage', 'beverages', 'snack', 'snacks', 'chips', 'crisps',
            'biscuits', 'cookies', 'cakes', 'chocolates', 'chocolate', 'candy', 'candies', 'sweets',
            'pasta', 'noodles', 'spaghetti', 'sauces', 'spices', 'pantry', 'household',
            'usa', 'us', 'uk', 'pakistan', 'australia', 'italy', 'india', 'spain', 'france',
        ];

        return in_array($candidate, $blocked, true) ? null : $candidate;
    }

    protected function extractIncludedIngredients(string $message): array
    {
        $messageLower = mb_strtolower($message);
        $ingredients = [];

        $knownIngredients = [
            'fiber', 'fibers', 'fibre', 'soluble corn fiber', 'gelatin', 'gelatine', 'pork', 'lard',
            'alcohol', 'alcohal', 'alcahol', 'alchol', 'ethanol', 'alcoholic', 'caffeine', 'sugar', 'salt', 'sodium', 'sodium chloride', 'water', 'milk', 'whey', 'casein', 'soy', 'palm oil',
            'vegetable oil', 'vegitable oil', 'olive oil', 'sunflower oil', 'canola oil', 'coconut oil', 'mayonnaise', 'mayonese', 'mayounese', 'mayo', 'ketchup', 'soy sauce',
            'folic acid', 'folate', 'vitamin b', 'vitamin b1', 'vitamin b2', 'vitamin b3', 'vitamin b6', 'vitamin b12', 'thiamine', 'riboflavin', 'niacin', 'cyanocobalamin', 'pyridoxine',
            'wheat', 'wheat flour', 'flour', 'rice', 'rice powder', 'rice flour', 'rice starch', 'corn starch', 'cocoa butter',
            'lecithin', 'cocoa', 'glucose', 'fructose', 'corn syrup', 'carmine', 'animal fat', 'rennet', 'enzymes',
            'natural flavoring', 'natural flavour', 'additive', 'additives', 'preservative', 'preservatives', 'e471', 'vanilla', 'lemon juice', 'lime juice', 'carbohydrate', 'carbohydrates', 'carbs',
            'spice', 'spices', 'spicy', 'masala', 'seasoning', 'seasonings', 'curry', 'curry spice', 'curry spice mix', 'chili', 'chilli', 'red chilli', 'paprika', 'pepper', 'black pepper', 'garlic powder', 'onion powder', 'turmeric', 'ginger',
            'animal derived', 'animal-derived', 'animal-driven', 'animal driven', 'animal based',
        ];

        $nutritionKeywords = [
            'vitamin b' => 'vitamin b',
            'vitamin-b' => 'vitamin b',
            'folic acid' => 'folic acid',
            'folate'   => 'folic acid',
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

        $dependentAnimalCheckOnly = preg_match('/\b(?:any\s+of\s+)?(?:them|these|those|the\s+products?|the\s+items?|results?)\b[^.?!;]{0,120}\b(?:animal|derived|driven|gelatin|gelatine|alcohol|alcoholic|pork|carmine|rennet)\b/iu', $messageLower) === 1;

        foreach ($knownIngredients as $item) {
            $isDependentCheckTerm = preg_match('/\b(?:animal\s*(?:derived|driven|based)?|animal-derived|derived|gelatin|gelatine|alcohol|alcoholic|pork|carmine|rennet)\b/iu', $item) === 1;
            if (str_contains($messageLower, $item)
                && ! ($isDependentCheckTerm && $dependentAnimalCheckOnly)
                && ! $this->isIngredientNegatedInMessage($messageLower, $item)
                && ! in_array($item, $ingredients, true)) {
                $ingredients[] = $item;
            }
        }

        if (preg_match_all(
            '/(?<!not\s)(?:contain|contains|containing|with|having|have|has|include|includes|must have|must be having|rich in|high in)\s+(.+?)(?=\s+(?:from|made in|inside|in it|in them|for me|please|that are|which are|category|products?$)|[.?!;]|$)/iu',
            $message,
            $allMatches
        )) {
            foreach ($allMatches[1] as $group) {
                $group = $this->cleanupIngredientPhrase($group);
                $parts = $this->splitFilterTerms($group);
                foreach ($parts as $part) {
                    $part = $this->cleanupIngredientPhrase($part);
                    if ($part !== '' && mb_strlen($part) >= 3 && ! $this->isNonIngredientCheckPhrase($part)) {
                        $ingredients[] = mb_strtolower($part);
                    }
                }
            }
        }

        // Extra natural-language recovery for phrases like:
        // "I need items that include rice powder" / "I want something that has cocoa inside".
        // This is intentionally additive: it only adds ingredient filters and does not remove
        // any previous resolver behavior.
        if (preg_match_all(
            '/\b(?:products?|items?|options?|things?|something|anything|foods?|snacks?|chocolates?|biscuits?|drinks?|juices?)\b[^.?!;]{0,80}\b(?:that\s+)?(?:contain|contains|containing|with|having|have|has|include|includes|including|inside|rich\s+in|high\s+in)\s+(.+?)(?=\s+(?:but|without|avoid|exclude|excluding|free\s+from|from|made\s+in|inside|in\s+it|in\s+them|for\s+me|please|that\s+are|which\s+are|category|products?$)|[.?!;]|$)/iu',
            $message,
            $catalogIngredientMatches
        )) {
            foreach ($catalogIngredientMatches[1] as $group) {
                foreach ($this->splitFilterTerms($this->cleanupIngredientPhrase((string) $group)) as $part) {
                    $part = $this->cleanupIngredientPhrase($part);
                    if ($part !== '' && mb_strlen($part) >= 3 && ! $this->isNonIngredientCheckPhrase($part)) {
                        $ingredients[] = mb_strtolower($part);
                    }
                }
            }
        }

        $ingredients = array_values(array_filter($ingredients, function ($ingredient) use ($messageLower): bool {
            return ! $this->isIngredientNegatedInMessage($messageLower, (string) $ingredient);
        }));

        return $this->normalizeIngredientAliases($ingredients);
    }

    protected function extractExcludedIngredients(string $message): array
    {
        $ingredients = [];

        if (preg_match_all(
            '/(?:without|excluding|exclude|avoid|free from|no|do\s+not\s+contain|does\s+not\s+contain|don\'t\s+contain|dont\s+contain|not\s+contain|not\s+containing|must\s+not\s+have)\s+((?:[a-z][a-z\s\-]*?)(?:\s*(?:and|or|,|&)\s*(?:[a-z][a-z\s\-]*?))*)/iu',
            $message,
            $allMatches
        )) {
            foreach ($allMatches[1] as $group) {
                $parts = $this->splitFilterTerms($group);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part !== '' && mb_strlen($part) >= 3 && ! $this->isNonIngredientCheckPhrase($part)) {
                        $ingredients[] = mb_strtolower($part);
                    }
                }
            }
        }

        $shorthand = [
            'dairy-free'     => ['milk', 'dairy', 'whey', 'casein'],
            'gluten-free'    => ['gluten', 'wheat'],
            'pork-free'      => ['pork', 'lard', 'gelatin'],
            'alcohol-free'   => ['alcohol', 'ethanol', 'wine', 'beer'],
            'alcohal-free'   => ['alcohol', 'ethanol', 'wine', 'beer'],
            'vegan'          => ['milk', 'egg', 'honey', 'gelatin', 'whey'],
        ];

        $messageLower = mb_strtolower($message);
        foreach ($shorthand as $phrase => $expanded) {
            if (str_contains($messageLower, $phrase)) {
                $ingredients = array_merge($ingredients, $expanded);
            }
        }

        $knownExclusions = ['sugar', 'salt', 'sodium', 'sodium chloride', 'water', 'milk', 'palm oil', 'mayonnaise', 'mayo', 'gelatin', 'gelatine', 'alcohol', 'alcohal', 'alcahol', 'alchol', 'alcoholic', 'pork', 'lard', 'whey', 'casein', 'soy', 'caffeine', 'glucose', 'fructose', 'corn syrup', 'carmine', 'rennet', 'enzymes', 'animal fat', 'e471', 'vegetable oil', 'vegitable oil', 'wheat', 'flour', 'rice powder', 'rice flour', 'rice starch', 'cocoa butter', 'additive', 'additives', 'preservative', 'preservatives', 'animal derived', 'animal-derived', 'animal driven', 'animal based'];
        foreach ($knownExclusions as $term) {
            if ($this->isIngredientNegatedInMessage($messageLower, $term)) {
                $ingredients[] = $term;
            }
        }

        return $this->normalizeIngredientAliases($ingredients);
    }

    protected function cleanupIngredientPhrase(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/["""\'`]+/u', '', $value) ?? $value;
        $value = preg_replace('/\b(?:and\s+also\s+)?(?:tell|check|show|explain)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:if|whether)\s+any\s+of\s+(?:them|these|those)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:but|and)?\s*(?:is\s+)?not\s+(?:marked\s+)?haram\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:but|and)?\s*(?:avoid|without|excluding|exclude|free\s+from|do\s+not\s+contain|does\s+not\s+contain|don\'t\s+contain|dont\s+contain|not\s+containing|not\s+contain|must\s+not\s+have|should\s+not\s+contain|should\s+not\s+have|no)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:halal|haram|mushbooh|mashbooh|unknown|pending)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(deficiency|deficient|body|nutrients?|nutrition|nutritious)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\b(in it|in them|inside|products?|items?|things?|ingredient|ingredients|please|for me|that contain|that contains|which contain|which contains)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\b(from|made in|origin|country|category)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value, " \t\n\r\0\x0B,.;:!?&");
    }

    protected function isIngredientNegatedInMessage(string $messageLower, string $ingredient): bool
    {
        $ingredient = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $ingredient)));
        if ($ingredient === '') {
            return false;
        }

        $aliases = [$ingredient];
        if ($ingredient === 'gelatine') {
            $aliases[] = 'gelatin';
        }
        if ($ingredient === 'alcoholic') {
            $aliases[] = 'alcohol';
        }
        if ($ingredient === 'sodium chloride' || $ingredient === 'sodium') {
            $aliases[] = 'salt';
        }
        if (preg_match('/\banimal\s+(?:derived|driven|based)\b/iu', $ingredient) === 1) {
            $aliases = array_merge($aliases, ['animal derived', 'animal driven', 'animal based']);
        }

        foreach (array_values(array_unique($aliases)) as $alias) {
            if (preg_match('/\b(?:without|no|not|free\s+from|does\s+not\s+contain|do\s+not\s+contain|don\'t\s+contain|dont\s+contain|not\s+containing|not\s+contain|exclude|excluding|avoid|must\s+not\s+have|should\s+not\s+contain|should\s+not\s+have)\b[^.?!;]{0,90}\b' . preg_quote($alias, '/') . '\b/iu', $messageLower) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeIngredientAliases(array $ingredients): array
    {
        $normalized = [];

        foreach ($ingredients as $ingredient) {
            $item = mb_strtolower(trim((string) $ingredient));
            if ($item === '') {
                continue;
            }

            if (in_array($item, ['fiber', 'fibers', 'fibre'], true)) {
                $item = 'fiber';
            } elseif (in_array($item, ['vitamins', 'vitamin'], true)) {
                $item = 'vitamin';
            } elseif (in_array($item, ['proteins', 'protein'], true)) {
                $item = 'protein';
            } elseif (in_array($item, ['vegitable oil', 'vegitable oils', 'vegetable oils'], true)) {
                $item = 'vegetable oil';
            } elseif (in_array($item, ['mayonese', 'mayounese', 'mayo'], true)) {
                $item = 'mayonnaise';
            } elseif (in_array($item, ['folate'], true)) {
                $item = 'folic acid';
            } elseif (in_array($item, ['vitamin-b', 'vit b'], true)) {
                $item = 'vitamin b';
            } elseif (in_array($item, ['vit b1'], true)) {
                $item = 'vitamin b1';
            } elseif (in_array($item, ['vit b2'], true)) {
                $item = 'vitamin b2';
            } elseif (in_array($item, ['vit b3', 'niacinamide'], true)) {
                $item = 'vitamin b3';
            } elseif (in_array($item, ['vit b6'], true)) {
                $item = 'vitamin b6';
            } elseif (in_array($item, ['vit b12'], true)) {
                $item = 'vitamin b12';
            } elseif (in_array($item, ['thiamin'], true)) {
                $item = 'thiamine';
            } elseif (in_array($item, ['riboflavine'], true)) {
                $item = 'riboflavin';
            } elseif (in_array($item, ['wheatflour'], true)) {
                $item = 'wheat flour';
            } elseif (in_array($item, ['ricepowder'], true)) {
                $item = 'rice powder';
            } elseif (in_array($item, ['riceflour'], true)) {
                $item = 'rice flour';
            } elseif (in_array($item, ['ricestarch'], true)) {
                $item = 'rice starch';
            } elseif (in_array($item, ['cocoabutter'], true)) {
                $item = 'cocoa butter';
            } elseif (in_array($item, ['carbs', 'carbohydrates'], true)) {
                $item = 'carbohydrate';
            } elseif (in_array($item, ['additives'], true)) {
                $item = 'additive';
            } elseif (in_array($item, ['preservatives'], true)) {
                $item = 'preservative';
            } elseif (in_array($item, ['gelatine'], true)) {
                $item = 'gelatin';
            } elseif (in_array($item, ['alcoholic'], true)) {
                $item = 'alcohol';
            } elseif (in_array($item, ['sodium', 'sodium chloride'], true)) {
                $item = 'salt';
            } elseif (preg_match('/\b(spicy|spiced|spice|spices|masala|seasoning|seasonings|curry|curry\s+spice(?:\s+mix)?|chilli?|chili|red\s+chilli|paprika|pepper|black\s+pepper|garlic\s+powder|onion\s+powder|turmeric|ginger)\b/iu', $item) === 1) {
                $item = 'spices';
            } elseif (preg_match('/\banimal\s+(?:derived|driven|based)\b/iu', $item) === 1) {
                $item = 'animal derived';
            }

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
            'olive oil'     => ['olive oil'],
            'sunflower oil' => ['sunflower oil'],
            'canola oil'    => ['canola oil', 'rapeseed oil'],
            'coconut oil'   => ['coconut oil'],
            'mayonnaise'    => ['mayonnaise', 'mayo', 'mayonese', 'mayounese'],
            'folic acid'    => ['folic acid', 'folate'],
            'vitamin b'     => ['vitamin b', 'vitamin-b', 'vit b'],
            'vitamin b1'    => ['vitamin b1', 'vit b1', 'thiamine', 'thiamin'],
            'vitamin b2'    => ['vitamin b2', 'vit b2', 'riboflavin'],
            'vitamin b3'    => ['vitamin b3', 'vit b3', 'niacin', 'niacinamide'],
            'vitamin b6'    => ['vitamin b6', 'vit b6', 'pyridoxine'],
            'vitamin b12'   => ['vitamin b12', 'vit b12', 'cyanocobalamin'],
            'wheat flour'   => ['wheat flour'],
            'wheat'         => ['wheat'],
            'flour'         => ['flour'],
            'rice powder'   => ['rice powder'],
            'rice flour'    => ['rice flour'],
            'rice starch'   => ['rice starch'],
            'rice'          => ['rice'],
            'corn starch'   => ['corn starch', 'maize starch'],
            'cocoa butter'  => ['cocoa butter'],
            'sugar'         => ['sugar', 'suger'],
            'salt'          => ['salt', 'sodium chloride', 'sodium'],
            'water'         => ['water'],
            'milk'          => ['milk'],
            'soy'           => ['soy'],
            'cocoa'         => ['cocoa'],
            'gelatin'       => ['gelatin', 'gelatine'],
            'alcohol'       => ['alcohol', 'alcoholic', 'ethanol'],
            'pork'          => ['pork', 'lard'],
            'animal derived'=> ['animal derived', 'animal-derived', 'animal-driven', 'animal driven', 'animal based', 'animal ingredients'],
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

    // FIX: CRITICAL - This is the main fix for status filter detection
    protected function extractIncludedStatuses(string $message): array
    {
        $message = mb_strtolower($message);
        $statuses = [];

        // "halal or at least not haram" is a safe-list request, not strict halal.
        if (preg_match('/\bhalal\b/iu', $message) === 1
            && preg_match('/\b(?:or\s+at\s+least|at\s+least|not\s+marked\s+haram|not\s+haram|safe\s+for\s+muslims?|muslim[-\s]*friendly|safe\s+grocery|safe\s+products?)\b/iu', $message) !== 1
            && ! preg_match('/\b(?:not|avoid|no)\s+halal\b/iu', $message)) {
            $statuses[] = 'halal';
        }

        if (preg_match('/\bharam\b/iu', $message)
            && ! preg_match('/\b(?:not|avoid|no|without|exclude|excluding|not\s+marked)\s+haram\b/iu', $message)) {
            $statuses[] = 'haram';
        }

        if ($this->containsAny($message, ['mushbooh', 'mashbooh', 'doubtful', 'questionable'])) {
            $statuses[] = 'mushbooh';
        }

        if (preg_match('/\bunknown\s+(?:status\s+)?(?:products?|items?)\b|\bproducts?\s+with\s+unknown\s+status\b/iu', $message) === 1) {
            $statuses[] = 'unknown';
        }

        return array_values(array_unique($statuses));
    }

    // FIX: CRITICAL - Extract excluded statuses
    protected function extractExcludedStatuses(string $message): array
    {
        $message = mb_strtolower($message);
        $statuses = [];

        if (preg_match('/\b(?:not\s+haram|not\s+marked\s+haram|exclude\s+haram|excluding\s+haram|without\s+haram|avoid\s+haram|safe\s+for\s+muslims?|muslim[-\s]*friendly|safe\s+grocery|safe\s+products?|safe\s+items?|not\s+forbidden|not\s+prohibited)\b/iu', $message) === 1) {
            $statuses[] = 'haram';
        }

        if (preg_match('/\b(?:not|exclude|excluding|without|avoid)\s+unknown\b/iu', $message) === 1) {
            $statuses[] = 'unknown';
        }

        return array_values(array_unique($statuses));
    }




    protected function isNonIngredientCheckPhrase(string $value): bool
    {
        $value = mb_strtolower(trim($value));

        return $value === ''
            || preg_match('/\b(animal\s*(?:derived|driven|based)?|derived|driven|substances?|inside|anything|any|any\s+of\s+them|these|those|products?|items?)\b/iu', $value) === 1;
    }

    protected function removeFocusedProductNameIngredients(array $ingredients, ?string $focusProductName): array
    {
        $focus = mb_strtolower(trim((string) $focusProductName));
        if ($focus === '' || empty($ingredients)) {
            return $ingredients;
        }

        return array_values(array_filter($ingredients, function ($ingredient) use ($focus): bool {
            $ingredient = mb_strtolower(trim((string) $ingredient));
            if ($ingredient === '') {
                return false;
            }

            // Product names such as "Dairy Milk" should not become ingredient filters.
            return ! preg_match('/(?<![a-z0-9])' . preg_quote($ingredient, '/') . '(?![a-z0-9])/iu', $focus);
        }));
    }

    protected function extractFocusedProductName(string $message): ?string
    {
        $message = trim($message);

        $detailBoundary = '(?=\s+(?:and\s+also\s+(?:its\s+)?)?(?:barcode|bar\s*code|ingredients?|ingredient|halal|haram|safe|safety|animal|derived|inside|details?)\b|[.?!;]|$)';
        $patterns = [
            '/\b(?:specially|especially|specifically|particularly)\s+(?:tell\s+me\s+about|about|check|show|explain|for)\s+(.+?)' . $detailBoundary . '/iu',
            '/\b(?:and|also)\s+(?:tell\s+me\s+about|check|explain)\s+(.+?)' . $detailBoundary . '/iu',
            '/\b(?:focus\s+on|mainly\s+about)\s+(.+?)' . $detailBoundary . '/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $candidate = $this->cleanupProductCandidate($matches[1]);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
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
        $clean = preg_replace('/["""?]+/u', '', $clean) ?? $clean;

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
        $value = preg_replace('/^(?:please\s+)?(?:can|could|would)\s+you\s+(?:please\s+)?(?:check|tell\s+me\s+about|tell\s+me|look\s+up|lookup|find|search)\s+/iu', '', $value ?? '');
        $value = preg_replace('/^(?:please\s+)?(?:give|show|tell|find|check|search|need|want|look\s+up|lookup)\s+(?:me\s+)?(?:the\s+)?/iu', '', $value ?? '');
        $value = preg_replace('/\b(?:give|show|tell)\s+(?:me\s+)?(?:its|the)?\s*(?:barcode|bar\s*code|ingredients?|details?|origin|brand).*$/iu', ' ', $value ?? '');
        $value = preg_replace('/\b(?:contain|contains|containing|has|have|with)\s+(?:any\s+)?(?:alcohol(?:ic)?|ethanol|gelatin|gelatine|palm\s+oil|animal[-\s]*derived|animal\s+driven|pork|lard|carmine|rennet|enzymes?)\b.*$/iu', ' ', $value ?? '');
        $value = preg_replace('/\b(?:barcode|bar\s*code|ingredients?|details?|origin|brand)\b.*$/iu', ' ', $value ?? '');
        $value = preg_replace('/\b(halal|haram|ingredients|ingredient|safe|consume|drink|eat|contain|contains|containing|products|product|show|list|suggest|recommend|party|home|from|italy|usa|uk|pakistan|new\s+zealand|give|need|want|its|barcode|bar code|details)\b/iu', ' ', $value ?? '');
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
        $questionWords = ['what', 'which', 'show', 'list', 'suggest', 'recommend', 'have', 'there', 'any', 'all', 'products', 'drinks', 'items', 'can', 'could', 'would', 'you', 'please', 'check', 'tell', 'me'];

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

        if (preg_match('/\b(?:all|show\s+me\s+all|list\s+all)\b/iu', mb_strtolower($message)) === 1) {
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

    protected function normalizeIntentText(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/([a-z])([A-Z])/u', '$1 $2', $value) ?? $value;
        $value = preg_replace('/(?<![a-z0-9])agrocery(?![a-z0-9])/iu', 'a grocery', $value) ?? $value;
        $value = preg_replace('/(?<![a-z0-9])a\s*grocery(?![a-z0-9])/iu', 'a grocery', $value) ?? $value;

        $replacements = [
            // Repair common accidental spaces inserted inside important intent words.
            // Example from Tinker/wrapped input: "brand p roducts" should still mean "brand products".
            '/(?<![a-z0-9])p\s+roducts?(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])pro\s+ducts?(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])prod\s+ucts?(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])peoducts?(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])prducts?(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])productz(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])drinkz(?![a-z0-9])/iu' => 'drinks',
            '/(?<![a-z0-9])choclates?(?![a-z0-9])/iu' => 'chocolates',
            '/(?<![a-z0-9])biskits?(?![a-z0-9])/iu' => 'biscuits',
            '/(?<![a-z0-9])coco(?![a-z0-9])/iu' => 'cocoa',
            '/(?<![a-z0-9])i\s+tems?(?![a-z0-9])/iu' => 'items',
            '/(?<![a-z0-9])br\s+and(?![a-z0-9])/iu' => 'brand',
            '/(?<![a-z0-9])groc\s+ery(?![a-z0-9])/iu' => 'grocery',
            '/(?<![a-z0-9])wool\s*worths?(?![a-z0-9])/iu' => 'woolworths',            '/(?<![a-z0-9])dose(?![a-z0-9])/iu' => 'does',
            '/(?<![a-z0-9])doze(?![a-z0-9])/iu' => 'does',
            '/(?<![a-z0-9])alcohal(?![a-z0-9])/iu' => 'alcohol',
            '/(?<![a-z0-9])alcahol(?![a-z0-9])/iu' => 'alcohol',
            '/(?<![a-z0-9])alchol(?![a-z0-9])/iu' => 'alcohol',
            '/(?<![a-z0-9])alkohol(?![a-z0-9])/iu' => 'alcohol',
            '/(?<![a-z0-9])gelatine(?![a-z0-9])/iu' => 'gelatin',
            '/(?<![a-z0-9])flavourings?(?![a-z0-9])/iu' => 'flavor',
            '/(?<![a-z0-9])flavours?(?![a-z0-9])/iu' => 'flavor',
            '/(?<![a-z0-9])fibre(?![a-z0-9])/iu' => 'fiber',
            '/(?<![a-z0-9])fizzy\s+drinks?(?![a-z0-9])/iu' => 'soft drink',
            '/(?<![a-z0-9])crisps(?![a-z0-9])/iu' => 'chips',
            '/(?<![a-z0-9])sweets(?![a-z0-9])/iu' => 'candies',
            '/(?<![a-z0-9])loo(?![a-z0-9])/iu' => 'washroom',
            '/(?<![a-z0-9])sprit(?![a-z0-9])/iu' => 'sprite',
            '/(?<![a-z0-9])dairymilk(?![a-z0-9])/iu' => 'dairy milk',
            '/(?<![a-z0-9])detol(?![a-z0-9])/iu' => 'dettol',
            '/(?<![a-z0-9])penut(?![a-z0-9])/iu' => 'peanut',
            '/(?<![a-z0-9])pennut(?![a-z0-9])/iu' => 'peanut',
            '/(?<![a-z0-9])peanutt(?![a-z0-9])/iu' => 'peanut',
            '/(?<![a-z0-9])mayonese(?![a-z0-9])/iu' => 'mayonnaise',
            '/(?<![a-z0-9])mayounese(?![a-z0-9])/iu' => 'mayonnaise',
            '/(?<![a-z0-9])groccry(?![a-z0-9])/iu' => 'grocery',
            '/(?<![a-z0-9])groccery(?![a-z0-9])/iu' => 'grocery',
            '/(?<![a-z0-9])grocerry(?![a-z0-9])/iu' => 'grocery',
            '/(?<![a-z0-9])grossery(?![a-z0-9])/iu' => 'grocery',
            '/(?<![a-z0-9])list\s+down(?![a-z0-9])/iu' => 'list',
            '/(?<![a-z0-9])insides(?![a-z0-9])/iu' => 'inside',
            '/(?<![a-z0-9])groceries(?![a-z0-9])/iu' => 'grocery items',
            '/animal[-\s]*driven/iu' => 'animal derived',
            '/animal-derived/iu' => 'animal derived',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
