<?php

namespace App\Services;

/**
 * ProductQueryPlannerService
 *
 * Final safety/planning layer between intent resolution and DB execution.
 * It does not replace the current resolver. It locks high-risk intents into
 * clean executable arguments so later layers cannot accidentally turn them into
 * fake combined product names, broad category searches, or noisy ingredient filters.
 */
class ProductQueryPlannerService
{
    public function plan(string $message, array $intent, string $toolName, array $arguments, ?array $imageContext = null): ?array
    {
        $message = $this->normalizeText($message);
        $imageContext = is_array($imageContext) ? $imageContext : [];

        $arguments = $this->normalizeArguments($arguments, $imageContext);

        $intentProductNames = $this->normalizeStringArray($arguments['product_names'] ?? []);
        $messageProductNames = $this->extractMultiProductNames($message);
        $productNames = count($intentProductNames) > 1 ? $intentProductNames : $messageProductNames;

        if (count($productNames) > 1) {
            return $this->singleStepPlan(
                'search_products',
                [
                    'query' => '',
                    'category' => null,
                    'brand' => null,
                    'origin' => null,
                    'origins' => [],
                    'product_names' => $productNames,
                    'ingredients_include' => [],
                    'ingredients_exclude' => [],
                    'status_include' => $this->normalizeStringArray($arguments['status_include'] ?? []),
                    'status_exclude' => $this->normalizeStringArray($arguments['status_exclude'] ?? []),
                    'diet_include' => $this->normalizeStringArray($arguments['diet_include'] ?? []),
                    'diet_exclude' => $this->normalizeStringArray($arguments['diet_exclude'] ?? []),
                    'match_mode' => 'any',
                    'limit' => max(12, count($productNames) * 4),
                    'image_context' => $imageContext,
                    'locked_plan' => true,
                    'plan_reason' => 'multi_product_names_locked',
                ],
                [
                    'source' => 'query_planner_multi_product_names',
                    'original_tool_name' => $toolName,
                    'detected_product_names' => $productNames,
                ]
            );
        }

        $directProductName = $this->extractDirectProductName($message);
        // Catalog requests like "show chocolates without gelatin" must never be treated as
        // direct product names such as "chocolates without". Category/filter plans win.
        if ($directProductName !== null && $this->looksLikeCatalogCategoryFilterRequest($message)) {
            $directProductName = null;
        }
        if ($directProductName !== null) {
            return $this->singleStepPlan(
                'find_product_by_name',
                [
                    'name' => $directProductName,
                    'image_context' => $imageContext,
                    'locked_plan' => true,
                    'plan_reason' => 'direct_product_safety_or_detail_locked',
                ],
                [
                    'source' => 'query_planner_direct_product',
                    'original_tool_name' => $toolName,
                    'detected_product_name' => $directProductName,
                ]
            );
        }

        $category = $this->normalizeNullableString($arguments['category'] ?? null) ?? $this->extractCategory($message);

        // V3: status words are real catalog filters and must be locked with the plan.
        // Examples: "not haram noodles", "halal biscuits", "only show halal or not-haram products".
        $statusInclude = $this->normalizeStringArray(array_merge(
            $this->normalizeStringArray($arguments['status_include'] ?? []),
            $this->extractStatusInclude($message)
        ));
        $statusExclude = $this->normalizeStringArray(array_merge(
            $this->normalizeStringArray($arguments['status_exclude'] ?? []),
            $this->extractStatusExclude($message)
        ));

        $ingredientFilters = $this->extractIngredientFilters($message);
        $dependentSensitiveExcludes = $this->extractDependentSensitiveIngredientExclusions($message);
        $ingredientsInclude = $this->normalizeStringArray(array_merge(
            $this->normalizeStringArray($arguments['ingredients_include'] ?? []),
            $ingredientFilters['include']
        ));
        $ingredientsExclude = $this->normalizeStringArray(array_merge(
            $this->normalizeStringArray($arguments['ingredients_exclude'] ?? []),
            $ingredientFilters['exclude'],
            $dependentSensitiveExcludes
        ));

        // V8: dependent result checks are report/exclusion constraints, not search includes.
        // Example: "products with milk and sugar, but tell me if any contain gelatin or animal-derived ingredients"
        // should search include=[milk,sugar] and exclude/report sensitive terms. It must not add
        // animal-derived expansions like whey/casein/butter/cheese/egg/honey to include filters.
        if (! empty($dependentSensitiveExcludes)) {
            $ingredientsInclude = $this->removeDependentSensitiveIncludeTerms($ingredientsInclude, $message);
        }

        $ingredientsInclude = array_values(array_diff($ingredientsInclude, $ingredientsExclude));

        $dietInclude = $this->normalizeStringArray(array_merge(
            $this->normalizeStringArray($arguments['diet_include'] ?? []),
            $this->extractDietInclude($message)
        ));
        $dietExclude = $this->normalizeStringArray(array_merge(
            $this->normalizeStringArray($arguments['diet_exclude'] ?? []),
            $this->extractDietExclude($message)
        ));

        $origin = $this->normalizeNullableString($arguments['origin'] ?? null) ?? $this->extractOrigin($message);
        $origins = $this->normalizeStringArray($arguments['origins'] ?? []);
        if ($origin !== null && $origin !== '' && ! in_array($origin, $origins, true)) {
            $origins[] = $origin;
        }

        $brand = $this->normalizeNullableString($arguments['brand'] ?? null) ?? $this->extractBrand($message);

        $hasCatalogFilters = $category !== null
            || $brand !== null
            || $origin !== null
            || ! empty($origins)
            || ! empty($ingredientsInclude)
            || ! empty($ingredientsExclude)
            || ! empty($dietInclude)
            || ! empty($dietExclude)
            || ! empty($statusInclude)
            || ! empty($statusExclude);

        if ($hasCatalogFilters && ($this->isCatalogIntent($message) || $toolName === 'search_products')) {
            return $this->singleStepPlan(
                'search_products',
                [
                    'query' => '',
                    'category' => $category,
                    'brand' => $brand,
                    'origin' => $origin,
                    'origins' => $origins,
                    'product_names' => [],
                    'ingredients_include' => $ingredientsInclude,
                    'ingredients_exclude' => $ingredientsExclude,
                    'status_include' => $statusInclude,
                    'status_exclude' => $statusExclude,
                    'diet_include' => $dietInclude,
                    'diet_exclude' => $dietExclude,
                    'match_mode' => $this->shouldUseAnyMode($message, $ingredientsInclude),
                    'limit' => $this->resolveLimit($arguments),
                    'image_context' => $imageContext,
                    'locked_plan' => true,
                    'plan_reason' => 'catalog_filters_locked',
                ],
                [
                    'source' => 'query_planner_catalog_filters',
                    'original_tool_name' => $toolName,
                ]
            );
        }

        return null;
    }

    protected function singleStepPlan(string $toolName, array $arguments, array $meta = []): array
    {
        return [
            'mode' => 'single_step',
            'tool_name' => $toolName,
            'arguments' => $arguments,
            'locked' => true,
            'meta' => $meta,
        ];
    }

    protected function normalizeArguments(array $arguments, array $imageContext): array
    {
        if (! isset($arguments['image_context'])) {
            $arguments['image_context'] = $imageContext;
        }
        return $arguments;
    }

    protected function extractMultiProductNames(string $message): array
    {
        $message = $this->normalizeText($message);
        if ($message === '') {
            return [];
        }

        $patterns = [
            '/^(?:please\s+)?(?:check|compare)\s+(.+?)\s+(?:for\s+(?:muslim\s+)?safety|for\s+halal\s+status|halal\s+status|safe\s+for\s+muslims?|ingredients?|details?|$)/iu',
            '/^(?:please\s+)?tell\s+me\s+about\s+(.+)$/iu',
            '/^(?:please\s+)?(?:is|are)\s+(.+?)\s+(?:safe\s+for\s+muslims?|halal|haram|permissible)\b/iu',
            '/^(?:please\s+)?(?:can\s+muslims\s+(?:eat|drink|consume)|can\s+i\s+(?:eat|drink|consume|buy|use))\s+(.+)$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) !== 1) {
                continue;
            }
            $candidate = trim((string) ($matches[1] ?? ''));
            $names = $this->splitProductList($candidate);
            if (count($names) > 1) {
                return $names;
            }
        }

        return [];
    }

    protected function splitProductList(string $value): array
    {
        $value = $this->normalizeText($value);
        $value = preg_replace('/\b(?:for\s+(?:muslim\s+)?safety|for\s+halal\s+status|halal\s+status|safe\s+for\s+muslims?|ingredients?|details?)\b.*$/iu', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B,.;:!?&");
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*|\s+\band\b\s+|\s+\bor\b\s+/iu', $value) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $clean = $this->cleanupProductName($part);
            if ($clean !== null) {
                $names[] = $clean;
            }
        }

        return array_values(array_unique($names));
    }

    protected function looksLikeCatalogCategoryFilterRequest(string $message): bool
    {
        $lower = mb_strtolower($this->normalizeText($message));
        if ($lower === '') {
            return false;
        }

        $hasCategory = $this->extractCategory($lower) !== null;
        if (! $hasCategory) {
            return false;
        }

        $hasCatalogVerb = preg_match('/\b(?:show|list|find|search|suggest|recommend|give|need|want)\b/iu', $lower) === 1;
        $hasFilter = preg_match('/\b(?:with|without|avoid|exclude|no|free\s+from|contain|contains|containing|include|includes|including|halal|haram|not\s+haram|not-haram)\b/iu', $lower) === 1;
        $hasPluralCategory = preg_match('/\b(?:products?|items?|options?|chocolates?|drinks?|juices?|snacks?|chips|crisps|biscuits?|cookies?|cakes?|candies|sweets|pasta|noodles?|sauces?)\b/iu', $lower) === 1;

        return $hasCatalogVerb || $hasFilter || $hasPluralCategory;
    }

    protected function extractDirectProductName(string $message): ?string
    {
        $message = $this->normalizeText($message);
        if ($message === '') {
            return null;
        }
        if (preg_match('/\b\d{8,40}\b/u', $message) === 1) {
            return null;
        }

        $patterns = [
            // Unicode/direct detail patterns. These cover Arabic/Urdu product names and
            // no-space mobile typing like "tellme ingredients of اكوافينا".
            '/^(?:please\s+)?(?:tell\s*me|show|give|check)\s+(?:me\s+)?(?:the\s+)?(?:ingredients?|ingredient|barcode|bar\s*code|details?|origin|brand|halal\s+status|status)\s+(?:of|for)\s+(.+?)(?=\s*[.?!؟؛;]|$)/iu',
            '/^(?:please\s+)?(?:is|are)\s+(.+?)\s+(?:is|are)\s+(?:halal|haram|safe\s+for\s+muslims?|safe|permissible|vegetarian|vegan|kosher)\b/iu',
            // tell me if Sprite contains alcohol / check if Dairy Milk contains gelatin
            '/^(?:please\s+)?(?:tell|check|show|explain)\s+(?:me\s+)?(?:if|whether)\s+(.+?)\s+(?:contains?|has|have|includes?|with)\s+.+$/iu',
            '/^(?:please\s+)?(?:check|tell|show|explain)\s+(.+?)\s+(?:for\s+)?(?:alcohol|gelatin|gelatine|animal[-\s]*derived|pork|palm\s+oil|ingredients?|halal\s+status|status)\b/iu',
            '/^(?:please\s+)?(?:can\s+i\s+(?:buy|eat|drink|consume|use))\s+(.+?)(?:\s+(?:for|before|please)\b|[.?!;]|$)/iu',
            '/^(?:i\s+am\s+muslim,?\s*)?(?:can\s+i\s+(?:buy|eat|drink|consume|use))\s+(.+?)(?:\s*\?|[.?!;]|$)/iu',
            '/^(?:please\s+)?(?:is|are)\s+(.+?)\s+(?:halal|haram|safe\s+for\s+muslims?|permissible|vegetarian|vegan|kosher)\b/iu',
            '/^(?:please\s+)?(?:does|do)\s+(.+?)\s+(?:contain|contains|have|has|include|includes)\s+.+$/iu',
            '/^(?:please\s+)?(?:check|lookup|find|search|tell\s+me\s+about)\s+(.+?)\s+(?:barcode|bar\s*code|ingredients?|halal\s+status|status|details?|origin|palm\s+oil|gelatin|alcohol)\b/iu',
            '/^(?:before\s+buying|before\s+i\s+buy)\s+(.+?),?\s+(?:tell|check|show|give)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) !== 1) {
                continue;
            }
            $candidate = $this->cleanupProductName((string) ($matches[1] ?? ''));
            if ($candidate !== null && ! $this->looksLikeGenericEntity($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function extractCategory(string $message): ?string
    {
        $lower = mb_strtolower($message);
        $map = [
            'pasta' => ['pasta', 'pastas', 'spaghetti', 'macaroni'],
            'noodles' => ['noodle', 'noodles'],
            'chocolate' => ['chocolate', 'chocolates'],
            'drink' => ['drink', 'drinks', 'beverage', 'beverages', 'juice', 'juices', 'soda', 'soft drink', 'soft drinks', 'fizzy drink', 'fizzy drinks'],
            'snack' => ['snack', 'snacks', 'chips', 'crisps'],
            'biscuit' => ['biscuit', 'biscuits', 'cookie', 'cookies'],
            'cake' => ['cake', 'cakes'],
            'candy' => ['candy', 'candies', 'sweets', 'sweet'],
            'sauce' => ['sauce', 'sauces', 'ketchup', 'mayonnaise', 'mayo'],
            'cereal' => ['cereal', 'cereals'],
            'bread' => ['bread', 'bakery'],
            'dairy_alternatives' => ['almond milk', 'oat milk', 'soy milk', 'soya milk', 'milk alternative', 'milk alternatives', 'dairy alternative', 'dairy alternatives'],
            'household' => ['household', 'dettol', 'hand wash', 'cleaning', 'bathroom'],
        ];

        foreach ($map as $category => $terms) {
            foreach ($terms as $term) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($term, '/') . '(?![a-z0-9])/iu', $lower) === 1) {
                    return $category;
                }
            }
        }
        return null;
    }

    /**
     * In catalog searches, trailing checks like
     * "but tell me if any contain gelatin or animal-derived ingredients" should not
     * be converted into positive include filters. They are sensitive exclusions/checks
     * around the returned set. Keep requested ingredients (milk + sugar) separate
     * from sensitive terms so the UI does not show "with milk + sugar + gelatin + ...".
     */
    protected function extractDependentSensitiveIngredientExclusions(string $message): array
    {
        $lower = mb_strtolower($this->normalizeText($message));
        if ($lower === '') {
            return [];
        }

        if (preg_match('/\b(?:but|and\s+also|also|and)?\s*(?:tell|check|show|explain)\s+(?:me\s+)?(?:if|whether)\s+(?:any\s+(?:returned\s+)?(?:products?|items?|results?)|any\s+of\s+(?:them|these|those)|they|them|these|those|any)\s+(?:contains?|have|has|include|includes|with)\b/iu', $lower) !== 1
            && preg_match('/\b(?:if|whether)\s+any\s+(?:contains?|contain|have|has|include|includes|with)\b/iu', $lower) !== 1) {
            return [];
        }

        $exclude = [];
        if (preg_match('/\bgelatin\b|\bgelatine\b|\bfish\s+gelatin\b/iu', $lower) === 1) {
            $exclude[] = 'gelatin';
        }
        if (preg_match('/\balcohol\b|\bethanol\b|\brum\b|\bwine\b|\bbeer\b/iu', $lower) === 1) {
            $exclude[] = 'alcohol';
        }
        if (preg_match('/\bpork\b|\blard\b/iu', $lower) === 1) {
            $exclude[] = 'pork';
            $exclude[] = 'lard';
        }
        if (preg_match('/\banimal[-\s]*(?:derived|driven|based)\b|\banimal\s+ingredients?\b|\banimal\b/iu', $lower) === 1) {
            // Do not add broad "animal derived" because ProductLookup expands it to milk/whey/casein,
            // which conflicts with valid requests like "products with milk and sugar".
            $exclude = array_merge($exclude, ['gelatin', 'pork', 'lard', 'animal fat', 'carmine', 'rennet', 'enzymes']);
        }

        return array_values(array_unique(array_filter($exclude)));
    }

    protected function removeDependentSensitiveIncludeTerms(array $ingredientsInclude, string $message): array
    {
        $messageLower = mb_strtolower($this->normalizeText($message));
        if ($messageLower === '') {
            return $ingredientsInclude;
        }

        $blocked = [];

        // These terms usually appear after "tell/check if any contain...". They should be
        // checked on the returned products or used as exclusions, not added as required includes.
        if (preg_match('/\b(?:animal[-\s]*(?:derived|driven|based)|animal\s+ingredients?|animal)\b/iu', $messageLower) === 1) {
            $blocked = array_merge($blocked, [
                'animal derived', 'animal-derived', 'animal driven', 'animal based',
                'pork', 'lard', 'animal fat', 'carmine', 'rennet', 'enzymes',
                'whey', 'casein', 'butter', 'cheese', 'egg', 'honey', 'yogurt', 'yoghurt',
            ]);
        }

        if (preg_match('/\b(?:gelatin|gelatine|fish\s+gelatin)\b/iu', $messageLower) === 1) {
            $blocked = array_merge($blocked, ['gelatin', 'gelatine', 'fish gelatin']);
        }

        if (preg_match('/\b(?:alcohol|ethanol|rum|wine|beer)\b/iu', $messageLower) === 1) {
            $blocked = array_merge($blocked, ['alcohol', 'ethanol', 'rum', 'wine', 'beer']);
        }

        $blocked = $this->normalizeStringArray($blocked);
        if ($blocked === []) {
            return $ingredientsInclude;
        }

        return array_values(array_filter($ingredientsInclude, fn ($ingredient): bool => ! in_array($this->normalizeIngredient((string) $ingredient), $blocked, true)));
    }

    protected function extractIngredientFilters(string $message): array
    {
        $lower = mb_strtolower($this->stripDependentResultCheck($message));
        $known = [
            'folic acid', 'vitamin b', 'vitamin', 'gelatin', 'gelatine', 'fish gelatin', 'alcohol', 'pork', 'lard',
            'animal derived', 'palm oil', 'cocoa butter', 'cocoa powder', 'cocoa', 'rice powder', 'rice flour',
            'rice starch', 'milk powder', 'milk', 'sugar', 'glucose syrup', 'fiber', 'fibre', 'protein', 'whey',
            'soy', 'vinegar', 'caffeine', 'corn starch', 'wheat flour', 'salt', 'spices', 'palm oil',
        ];

        $include = [];
        $exclude = [];
        foreach ($known as $term) {
            $canonical = $this->normalizeIngredient($term);
            $quoted = preg_quote($term, '/');
            if (preg_match('/\b(?:without|avoid|exclude|no|free\s+from|do\s+not\s+contain|does\s+not\s+contain|don\'t\s+contain|dont\s+contain|not\s+contain|should\s+not\s+contain)\b[^.?!;,]{0,120}\b' . $quoted . '\b/iu', $lower) === 1) {
                $exclude[] = $canonical;
                continue;
            }
            if (preg_match('/\b(?:with|contain|contains|containing|include|includes|including|having|has|have|rich\s+in|high\s+in)\b[^.?!;,]{0,120}\b' . $quoted . '\b/iu', $lower) === 1
                || preg_match('/\b' . $quoted . '\b[^.?!;,]{0,80}\b(?:inside|in\s+ingredients?|as\s+ingredients?)\b/iu', $lower) === 1) {
                $include[] = $canonical;
            }
        }

        return [
            'include' => array_values(array_unique(array_filter($include))),
            'exclude' => array_values(array_unique(array_filter($exclude))),
        ];
    }

    protected function stripDependentResultCheck(string $message): string
    {
        $clean = $this->normalizeText($message);
        if ($clean === '') {
            return '';
        }

        // Keep search filters before the dependent check only.
        // Examples:
        // "products with milk and sugar, but tell me if any contain gelatin"
        // -> "products with milk and sugar"
        // "halal chocolates and also check if any returned products contain gelatin"
        // -> "halal chocolates"
        $patterns = [
            '/\s*,?\s*(?:but|and\s+also|also|and)?\s*(?:tell|check|show|explain)\s+(?:me\s+)?(?:if|whether)\s+(?:any\s+(?:returned\s+)?(?:products?|items?|results?)|any\s+of\s+(?:them|these|those)|they|them|these|those)\s+(?:contains?|have|has|include|includes|with)\b.*$/iu',
            '/\s*,?\s*(?:but|and\s+also|also|and)?\s*(?:if|whether)\s+(?:any\s+(?:returned\s+)?(?:products?|items?|results?)|any\s+of\s+(?:them|these|those)|they|them|these|those|any)\s+(?:contains?|contain|have|has|include|includes|with)\b.*$/iu',
        ];

        foreach ($patterns as $pattern) {
            $clean = preg_replace($pattern, '', $clean) ?? $clean;
        }

        return trim($clean, " \t\n\r\0\x0B,.;:!?&");
    }

    protected function extractStatusInclude(string $message): array
    {
        $lower = mb_strtolower($this->normalizeText($message));
        $include = [];

        // In this app, "halal", "not haram", "safe for Muslims", and
        // "halal or not-haram" list requests should return halal-approved rows only,
        // not unknown/out_of_scope/haram rows.
        if (preg_match('/\b(?:halal|not\s+haram|not-haram|not\s+marked\s+haram|safe\s+for\s+muslims?|muslim[-\s]*friendly)\b/iu', $lower) === 1) {
            $include[] = 'halal';
        }

        if (preg_match('/(?<!not\s)\bharam\b/iu', $lower) === 1
            && preg_match('/\b(?:show|list|find|give)\b/iu', $lower) === 1
            && preg_match('/\bnot\s+haram\b/iu', $lower) !== 1) {
            $include[] = 'haram';
        }

        if (preg_match('/\b(?:mushbooh|mashbooh|doubtful)\b/iu', $lower) === 1
            && preg_match('/\b(?:not|avoid|exclude|without)\s+(?:mushbooh|mashbooh|doubtful)\b/iu', $lower) !== 1) {
            $include[] = 'mushbooh';
        }

        if (preg_match('/\b(?:unknown|decision\s+pending|pending)\b/iu', $lower) === 1
            && preg_match('/\b(?:not|avoid|exclude|without)\s+(?:unknown|decision\s+pending|pending)\b/iu', $lower) !== 1) {
            $include[] = 'unknown';
        }

        return array_values(array_unique($include));
    }

    protected function extractStatusExclude(string $message): array
    {
        $lower = mb_strtolower($this->normalizeText($message));
        $exclude = [];

        if (preg_match('/\b(?:not\s+haram|not-haram|not\s+marked\s+haram|without\s+haram|avoid\s+haram|exclude\s+haram|safe\s+for\s+muslims?|muslim[-\s]*friendly|safe\s+products?|safe\s+items?)\b/iu', $lower) === 1) {
            $exclude[] = 'haram';
        }

        if (preg_match('/\b(?:exclude|avoid|without|not|no)\s+(?:unknown|pending|decision\s+pending)\b/iu', $lower) === 1) {
            $exclude[] = 'unknown';
        }

        if (preg_match('/\b(?:exclude|avoid|without|not|no)\s+(?:mushbooh|mashbooh|doubtful)\b/iu', $lower) === 1) {
            $exclude[] = 'mushbooh';
        }

        return array_values(array_unique($exclude));
    }

    protected function extractDietInclude(string $message): array
    {
        $lower = mb_strtolower($message);
        $diet = [];
        if (preg_match('/\bvegetarian\b/iu', $lower) === 1 && preg_match('/\b(?:show|list|find|products?|items?|options?)\b/iu', $lower) === 1) {
            $diet[] = 'vegetarian';
        }
        if (preg_match('/\bvegan\b/iu', $lower) === 1 && preg_match('/\b(?:show|list|find|products?|items?|options?)\b/iu', $lower) === 1) {
            $diet[] = 'vegan';
        }
        if (preg_match('/\bkosher\b/iu', $lower) === 1 && preg_match('/\b(?:show|list|find|products?|items?|options?)\b/iu', $lower) === 1) {
            $diet[] = 'kosher';
        }
        if (preg_match('/\bgluten[-\s]*free\b/iu', $lower) === 1 && preg_match('/\b(?:show|list|find|products?|items?|options?)\b/iu', $lower) === 1) {
            $diet[] = 'gluten_free';
        }
        return $diet;
    }

    protected function extractDietExclude(string $message): array
    {
        $lower = mb_strtolower($message);
        $diet = [];
        if (preg_match('/\bnon[-\s]*vegetarian\b/iu', $lower) === 1) {
            $diet[] = 'vegetarian';
        }
        if (preg_match('/\bnon[-\s]*vegan\b/iu', $lower) === 1) {
            $diet[] = 'vegan';
        }
        return $diet;
    }

    protected function extractOrigin(string $message): ?string
    {
        $lower = mb_strtolower($message);
        $map = [
            'morocco' => ['morocco', 'moroccan'],
            'australia' => ['australia', 'australian'],
            'united states' => ['united states', 'usa', 'u.s.a', 'american'],
            'united kingdom' => ['united kingdom', 'uk', 'u.k', 'british'],
            'pakistan' => ['pakistan', 'pakistani'],
            'italy' => ['italy', 'italian'],
            'canada' => ['canada', 'canadian'],
        ];
        foreach ($map as $origin => $terms) {
            foreach ($terms as $term) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($term, '/') . '(?![a-z0-9])/iu', $lower) === 1) {
                    return $origin;
                }
            }
        }
        return null;
    }

    protected function extractBrand(string $message): ?string
    {
        $lower = mb_strtolower($message);
        if (preg_match('/\b(?:items\s+in\s+)?woolworths?\b/iu', $lower) === 1) {
            return 'woolworths';
        }
        if (preg_match('/\b(?:brand|by)\s+([a-z0-9][a-z0-9\s&\-\'’]{1,60})\s+(?:products?|items?)\b/iu', $message, $m) === 1) {
            return $this->cleanupProductName((string) $m[1]);
        }
        if (preg_match('/^show\s+(.+?)\s+(?:products?|items?)$/iu', $message, $m) === 1) {
            $candidate = $this->cleanupProductName((string) $m[1]);
            if ($candidate !== null && ! $this->looksLikeGenericEntity($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    protected function isCatalogIntent(string $message): bool
    {
        $lower = mb_strtolower($message);
        return preg_match('/\b(?:show|list|find|search|suggest|recommend|give|need|want|looking\s+for|products?|items?|options?|grocery|without|with|include|contains?|containing|avoid|exclude|from|halal|haram|vegetarian|vegan|kosher)\b/iu', $lower) === 1
            && preg_match('/\b(?:products?|items?|options?|grocery|pasta|chocolates?|drinks?|juices?|snacks?|biscuits?|cookies?|cakes?|cand(?:y|ies)|sweets?|sauces?|vegetarian|vegan|kosher|without|with|include|contains?|containing|avoid|exclude|from)\b/iu', $lower) === 1;
    }

    protected function shouldUseAnyMode(string $message, array $ingredients): string
    {
        if (preg_match('/\b(?:or|either|any\s+of|vitamins?|nutrients?)\b/iu', $message) === 1 || count($ingredients) > 2) {
            return 'any';
        }
        return 'all';
    }

    protected function resolveLimit(array $arguments): int
    {
        $limit = (int) ($arguments['limit'] ?? 12);
        return max(1, min($limit, 30));
    }

    protected function cleanupProductName(string $candidate): ?string
    {
        $candidate = $this->normalizeText($candidate);
        $candidate = preg_replace('/^(?:please\s+)?(?:check|compare|tell\s+me\s+about|about|is|are|does|do)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s+(?:is|are)\s*$/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:for\s+(?:muslim\s+)?safety|for\s+halal\s+status|safe\s+for\s+muslims?|halal\s+status|ingredients?|details?|barcode|bar\s*code|status)\b.*$/iu', '', $candidate) ?? $candidate;
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));
        $candidate = trim($candidate, " \t\n\r\0\x0B,.;:!?&");
        if ($candidate === '' || mb_strlen($candidate) < 2) {
            return null;
        }
        return $this->canonicalProductName($candidate);
    }

    protected function canonicalProductName(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $name));
        $lower = mb_strtolower($clean);
        return match ($lower) {
            'barilla pasta' => 'BARILLA pasta',
            'nestle ghi' => 'Nestle GHI',
            'la choy soy sauce', 'la choy soy' => 'LA CHOY Soy sauce',
            'tomato ketchup' => 'Tomato Ketchup',
            'gelatina sabor morango' => 'Gelatina sabor Morango',
            'pepsi tuck biscuit', 'pepsi tuc biscuit', 'pepsi tuck biscuits' => 'Pepsi Tuck Biscuit',
            'almond milk' => 'Almond Milk',
            'dairy milk' => 'Dairy Milk',
            'kinder bueno' => 'Kinder Bueno',
            'sprite' => 'Sprite',
            'coke' => 'Coke',
            'dettol' => 'Dettol',
            default => $clean,
        };
    }

    protected function looksLikeGenericEntity(string $candidate): bool
    {
        $lower = mb_strtolower($candidate);
        return in_array($lower, [
            'product', 'products', 'item', 'items', 'something', 'anything', 'pasta', 'chocolate', 'chocolates', 'drink', 'drinks', 'sauce', 'sauces', 'biscuits', 'biscuit', 'snacks', 'snack', 'vegetarian', 'vegan', 'kosher',
        ], true);
    }

    protected function normalizeIngredient(string $ingredient): string
    {
        $ingredient = mb_strtolower($this->normalizeText($ingredient));
        return match ($ingredient) {
            'gelatine' => 'gelatin',
            'fibre' => 'fiber',
            default => $ingredient,
        };
    }

    protected function normalizeText(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/([a-z])([A-Z])/u', '$1 $2', $value) ?? $value;
        $value = preg_replace('/(?<![a-z0-9])tell\s*me(?![a-z0-9])/iu', 'tell me', $value) ?? $value;
        $value = preg_replace('/\bwool\s*worths?\b/iu', 'woolworths', $value) ?? $value;
        $value = preg_replace('/\bgelatine\b/iu', 'gelatin', $value) ?? $value;
        $value = preg_replace('/\balcohal|alcahol|alchol\b/iu', 'alcohol', $value) ?? $value;
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    protected function normalizeNullableString(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }
        $value = $this->normalizeText((string) $value);
        return $value === '' ? null : $value;
    }

    protected function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }
        $out = [];
        foreach ($value as $item) {
            $item = $this->normalizeText((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }
}
