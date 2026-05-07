<?php

namespace App\Services;

/**
 * ProductReasoningService
 *
 * Manager/Developer overview: Response reasoning layer: converts product lookup results into user-facing answers for details, barcode, ingredients, halal/safety, and list results.
 * Comments were added for documentation only; business logic is unchanged from v3.
 */
class ProductReasoningService
{
    /**
     * Main reply builder. Selects not-found, single-product, search-list, or ingredient-explanation response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    public function buildReply(string $message, array $lookup, array $intent, ?array $imageContext = null): string
    {
        $tool     = (string) ($intent['tool_name'] ?? '');
        $focuses  = array_values(array_unique(array_filter(
            is_array($intent['question_focuses'] ?? null)
                ? $intent['question_focuses']
                : [$intent['question_focus'] ?? 'details']
        )));
        $status   = (string) ($lookup['status'] ?? 'not_found');
        $products = is_array($lookup['products'] ?? null) ? $lookup['products'] : [];
        $ingredientExplanation = $lookup['ingredient_explanation'] ?? null;
        $messageLower = strtolower(trim($this->normalizeMessageText($message)));
        $meta     = is_array($lookup['meta'] ?? null) ? $lookup['meta'] : [];

        if ($status === 'error') {
            return 'Sorry, something went wrong while checking that. Please try again.';
        }

        if ($tool === 'explain_ingredient' && is_array($ingredientExplanation)) {
            return ($ingredientExplanation['ingredient'] ?? 'This ingredient') . ': ' . ($ingredientExplanation['summary'] ?? 'No explanation available.');
        }

        if ($tool === 'answer_without_db') {
            return $this->buildDatabaseScopeReply($messageLower);
        }

        // Never hallucinate product details when our records did not return a match.
        if ($status !== 'found' || empty($products)) {
            if (! empty($imageContext)) {
                $label       = trim((string) (($imageContext['brand'] ?? '') . ' ' . ($imageContext['product_name'] ?? '')));
                $category    = strtolower(trim((string) ($imageContext['category'] ?? '')));
                $visibleText = strtolower(trim((string) ($imageContext['visible_text'] ?? '')));

                $isNonFood = (bool) preg_match('/\b(hand wash|soap|shampoo|cleaner|detergent|lotion|cream|sanitizer)\b/i', strtolower($label . ' ' . $category . ' ' . $visibleText));

                if ($isNonFood) {
                    return ($label !== '' ? "The image appears to show {$label}. " : 'This looks like a non-food product. ')
                        . 'It looks like a non-food personal care or cleaning product, so it is not something to consume.';
                }

                if ($label !== '') {
                    return "The image appears to show {$label}, but I could not confidently match it. Please send a clearer front photo, the barcode, or the exact product name.";
                }

                return 'I could not confidently match this image to a product. Please send a clearer front photo, the barcode, or the exact product name.';
            }

            if ($this->looksRecommendation($messageLower)) {
                return 'I could not find strong matches for that request. Try a broader category like snacks, chips, biscuits, or drinks.';
            }

            return (string) ($lookup['message'] ?? 'I could not find a matching product.');
        }

        if (in_array($tool, ['find_product_by_barcode', 'find_product_by_name'], true)) {
            return $this->buildSingleProductReply($messageLower, $products[0], $focuses);
        }

        if (in_array($tool, ['search_products', 'find_similar_by_category'], true)) {
            return $this->buildSearchReply($messageLower, $products, $focuses, $intent, $meta);
        }

        return $this->buildSingleProductReply($messageLower, $products[0], $focuses);
    }

    // ─────────────────────────────────────────────────────────────
    // SINGLE PRODUCT REPLY
    // ─────────────────────────────────────────────────────────────

    /**
     * Builds a focused answer for one matched product.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildSingleProductReply(string $messageLower, array $product, array $focuses): string
    {
        $name        = (string) ($product['name'] ?? 'This product');
        $brand       = trim((string) ($product['brand'] ?? ''));
        $barcode     = trim((string) ($product['barcode'] ?? ''));
        $decision    = $this->productDecision($product);
        $origin      = trim((string) ($product['origin'] ?? ''));
        $ingredients = trim((string) ($product['ingredients'] ?? ''));

        $sections = [];
        $focuses  = $this->normalizeFocuses($focuses, $messageLower);

        if ($this->shouldUseCombinedSingleProductReply($focuses)) {
            return $this->buildCombinedSingleProductReply(
                $name,
                $brand,
                $barcode,
                $decision,
                $origin,
                $ingredients,
                $focuses,
                $messageLower
            );
        }

        foreach ($focuses as $focus) {
            switch ($focus) {
                case 'brand_name':
                    $sections[] = $brand !== ''
                        ? "{$name} brand is {$brand}."
                        : "I found {$name}, but its brand is not available in our records.";
                    break;

                case 'ingredients':
                    $sections[] = $this->formatIngredientsAnswer($name, $ingredients);
                    break;

                case 'barcode':
                    $sections[] = $barcode !== ''
                        ? "{$name} barcode is {$barcode}."
                        : "I found {$name}, but its barcode is not available in our records.";
                    break;

                case 'origin':
                    $sections[] = $origin !== ''
                        ? "{$name} origin is {$origin}."
                        : "I found {$name}, but its origin is not available in our records.";
                    break;

                case 'alcohol_check':
                    $sections[] = $this->checkAlcoholicSubstances($name, $ingredients);
                    break;

                case 'animal_derived_check':
                    $sections[] = $this->checkAnimalDerived($name, $ingredients);
                    break;

                case 'suspicious_check':
                    $sections[] = $this->checkSuspicious($name, $ingredients, $decision);
                    break;

                case 'health_check':
                    $sections[] = $this->checkHealth($name, $ingredients, $decision);
                    break;

                case 'halal_status':
                    $sections[] = $this->formatHalalStatusAnswer($name, $decision);
                    break;
            }
        }

        if ($this->asksForGelatinCheck($messageLower)) {
            $sections[] = $this->checkSpecificIngredient($name, $ingredients, 'gelatin', ['gelatin', 'gelatine']);
        }

        if ($this->asksForPalmOilCheck($messageLower)) {
            $sections[] = $this->checkSpecificIngredient($name, $ingredients, 'palm oil', ['palm oil', 'palmolein', 'palm olein']);
        }

        if (empty($sections)) {
            $sections[] = $this->formatHalalStatusAnswer($name, $decision);

            if ($brand !== '') {
                $sections[] = "Brand: {$brand}.";
            }

            if ($origin !== '') {
                $sections[] = "Origin: {$origin}.";
            }

            if ($barcode !== '') {
                $sections[] = "Barcode: {$barcode}.";
            }
        }

        return $this->joinSections($sections);
    }

    protected function formatHalalStatusAnswer(string $name, string $decision): string
    {
        $decision = strtolower(trim($decision));

        return match ($decision) {
            'halal' => "Yes, {$name} is halal according to our records.",
            'haram' => "No, {$name} is not halal. It is haram according to our records.",
            'mushbooh' => "I cannot confirm {$name} as halal. It is marked as mushbooh in our records.",
            'out_of_scope' => "{$name} is out of scope according to our records.",
            default => "I cannot confirm {$name} as halal. It is marked as {$decision} in our records.",
        };
    }

    /**
     * Boolean helper that checks whether the current request or product matches "should use combined single product reply".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function shouldUseCombinedSingleProductReply(array $focuses): bool
    {
        $combinedFocuses = array_intersect($focuses, [
            'ingredients',
            'halal_status',
            'suspicious_check',
            'health_check',
            'alcohol_check',
            'animal_derived_check',
        ]);

        return count($combinedFocuses) > 1;
    }

    /**
     * Builds a complete answer with barcode, origin, ingredients, halal status, and safety checks.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildCombinedSingleProductReply(
        string $name,
        string $brand,
        string $barcode,
        string $decision,
        string $origin,
        string $ingredients,
        array $focuses,
        string $messageLower = ''
    ): string {
        $sections = [];

        if (in_array('halal_status', $focuses, true)
            || in_array('suspicious_check', $focuses, true)
            || in_array('alcohol_check', $focuses, true)
            || in_array('animal_derived_check', $focuses, true)) {
            $sections[] = $this->formatHalalStatusAnswer($name, $decision);
        }

        $identity = [];
        if ($brand !== '') {
            $identity[] = "brand: {$brand}";
        }
        if ($origin !== '') {
            $identity[] = "origin: {$origin}";
        }
        if ($barcode !== '') {
            $identity[] = "barcode: {$barcode}";
        }
        if (! empty($identity)) {
            $sections[] = 'Product details — ' . implode(', ', $identity) . '.';
        }

        if (in_array('ingredients', $focuses, true)) {
            $sections[] = $ingredients !== ''
                ? "Ingredients: {$ingredients}."
                : 'Ingredients are not available in our records.';
        }

        $ingredientLower = strtolower($ingredients);

        if ($ingredients === '') {
            if (in_array('health_check', $focuses, true)
                || in_array('suspicious_check', $focuses, true)
                || in_array('alcohol_check', $focuses, true)
                || in_array('animal_derived_check', $focuses, true)) {
                $sections[] = 'Because the ingredient list is not available, I cannot fully verify harmful, alcohol-related, or animal-derived ingredients.';
            }

            return $this->joinSections($sections);
        }

        if ($this->asksForGelatinCheck($messageLower)) {
            $sections[] = $this->checkSpecificIngredient($name, $ingredients, 'gelatin', ['gelatin', 'gelatine']);
        }

        if ($this->asksForPalmOilCheck($messageLower)) {
            $sections[] = $this->checkSpecificIngredient($name, $ingredients, 'palm oil', ['palm oil', 'palmolein', 'palm olein']);
        }

        if (in_array('suspicious_check', $focuses, true)
            || in_array('alcohol_check', $focuses, true)
            || in_array('animal_derived_check', $focuses, true)) {
            $halalSensitiveTerms = $this->collectHits($ingredientLower, [
                'gelatin',
                'e471',
                'alcohol',
                'ethanol',
                'wine',
                'beer',
                'rum',
                'brandy',
                'liqueur',
                'spirit',
                'vanilla extract',
                'carmine',
                'animal fat',
                'rennet',
                'enzymes',
                'natural flavor',
                'natural flavour',
                'whey',
                'casein',
                'milk',
                'butter',
                'cheese',
                'egg',
                'honey',
            ]);

            $sections[] = ! empty($halalSensitiveTerms)
                ? 'Halal-sensitive terms to review: ' . implode(', ', $halalSensitiveTerms) . '.'
                : 'I did not find obvious alcohol-related or animal-derived sensitive terms in the listed ingredients.';
        }

        if (in_array('health_check', $focuses, true)) {
            $positives = $this->collectHits($ingredientLower, ['almond', 'calcium', 'vitamin', 'fiber', 'protein', 'peanut', 'olive', 'chickpeas', 'broad beans']);
            $concerns = $this->collectHits($ingredientLower, ['sugar', 'glucose', 'fructose', 'corn syrup', 'palm oil', 'hydrogenated oil', 'artificial', 'caffeine', 'salt', 'e-621', 'e621', 'e-635', 'e635', 'flavor enhancers', 'colour', 'color']);

            $healthParts = [];
            if (! empty($positives)) {
                $healthParts[] = 'positives: ' . implode(', ', $positives);
            }
            if (! empty($concerns)) {
                $healthParts[] = 'consume in moderation due to: ' . implode(', ', $concerns);
            }
            if (empty($positives) && empty($concerns)) {
                $healthParts[] = 'no obvious health concern terms were found from the ingredient text, but nutrition facts are needed for a full health judgement';
            }

            $sections[] = 'Health notes — ' . implode('; ', $healthParts) . '.';
        }

        return $this->joinSections($sections);
    }

    // ─────────────────────────────────────────────────────────────
    // SEARCH REPLY
    // ─────────────────────────────────────────────────────────────

    /**
     * Builds responses for multi-product search/catalog results.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildSearchReply(string $messageLower, array $products, array $focuses, array $intent, array $meta): string
    {
        $hasStrictStatusFilter = ! empty($meta['status_include'] ?? []) || ! empty($meta['status_exclude'] ?? []);

        $isRecommendation = ! $hasStrictStatusFilter && (
            $this->looksRecommendation($messageLower)
            || ($intent['question_focus'] ?? '') === 'recommendation'
            || ($meta['is_recommendation'] ?? false) === true
        );

        if ($this->asksForComparison($messageLower) && count($products) > 1) {
            return $this->buildComparisonReply($products, $messageLower);
        }

        if (($this->asksForHealth($messageLower) || str_contains($messageLower, 'harmful')) && count($products) > 1) {
            return $this->buildMultiProductHealthReply($products, $messageLower);
        }

        if ($isRecommendation) {
            return $this->buildRecommendationReply($products, $meta, $messageLower, $intent);
        }

        // FIX: For multi-product search, show full list — not a single-product focus reply
        // Only apply single-product logic when there's 1 result AND user asked about specific attributes
        $hasFocusedProduct = is_array($meta['focus_product'] ?? null);

        $isSingleFocusQuery = ! $hasFocusedProduct
            && ! empty($focuses)
            && $focuses !== ['details']
            && count($products) === 1;

        if ($isSingleFocusQuery) {
            return $this->buildSingleProductReply($messageLower, $products[0], $focuses);
        }

        // FIX: Build a proper multi-product listing response
        return $this->buildMultiProductListReply($products, $meta, $messageLower, $intent);
    }

    /**
     * Formats multiple matching products with short status and origin/brand notes.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildMultiProductListReply(array $products, array $meta, string $messageLower, array $intent): string
    {
        $count = count($products);
        if ($count <= 0) {
            return 'No matching products found.';
        }

        $statusInclude = array_values(array_filter(array_map('strtolower', (array) ($meta['status_include'] ?? []))));
        $statusExclude = array_values(array_filter(array_map('strtolower', (array) ($meta['status_exclude'] ?? []))));
        $category = strtolower(trim((string) ($meta['category'] ?? '')));
        $ingredientsInclude = array_values(array_filter(array_map('strtolower', (array) ($meta['ingredients_include'] ?? []))));
        $ingredientsExclude = array_values(array_filter(array_map('strtolower', (array) ($meta['ingredients_exclude'] ?? []))));
        $matchMode = strtolower((string) ($meta['match_mode'] ?? data_get($intent, 'arguments.match_mode', 'all')));

        $filters = [];
        if (! empty($statusInclude)) {
            $filters[] = implode('/', $statusInclude);
        } elseif (! empty($statusExclude)) {
            $filters[] = 'not ' . implode('/', $statusExclude);
        }
        if ($category !== '') {
            $filters[] = $category;
        }
        if (! empty($ingredientsInclude)) {
            $joiner = $matchMode === 'any' ? ' or ' : ' and ';
            $filters[] = 'with ' . implode($joiner, $ingredientsInclude);
        }
        if (! empty($ingredientsExclude)) {
            $filters[] = 'without ' . implode(' and ', $ingredientsExclude);
        }

        $filterText = ! empty($filters) ? ' for ' . implode(', ', $filters) : '';
        return 'Found ' . $count . ' matching product' . ($count === 1 ? '' : 's') . $filterText . '. Please check the product cards below.';
    }

    /**
     * Builds "multi product specific ingredient check summary" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildMultiProductSpecificIngredientCheckSummary(array $products, string $label, array $terms): string
    {
        $withHits = [];
        $withoutHits = [];
        $missingIngredients = [];

        foreach (array_slice($products, 0, 8) as $product) {
            $name = (string) ($product['name'] ?? 'Unnamed product');
            $ingredients = trim((string) ($product['ingredients'] ?? ''));

            if ($ingredients === '') {
                $missingIngredients[] = $name;
                continue;
            }

            $hits = $this->collectHits(strtolower($ingredients), $terms);
            if (! empty($hits)) {
                $withHits[] = $name . ' (' . implode(', ', $hits) . ')';
            } else {
                $withoutHits[] = $name;
            }
        }

        $parts = [];
        if (! empty($withHits)) {
            $parts[] = ucfirst($label) . ' found in: ' . implode('; ', array_slice($withHits, 0, 5));
        }
        if (! empty($withoutHits)) {
            $parts[] = 'no ' . $label . ' found in: ' . implode(', ', array_slice($withoutHits, 0, 5));
        }
        if (! empty($missingIngredients)) {
            $parts[] = 'ingredient data missing for: ' . implode(', ', array_slice($missingIngredients, 0, 4));
        }

        if (empty($parts)) {
            return "I could not verify {$label} because ingredient data is not available for these results.";
        }

        return ucfirst($label) . ' check from available ingredients — ' . implode('. ', $parts) . '.';
    }

    /**
     * Builds "multi product ingredient check summary" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildMultiProductIngredientCheckSummary(array $products, string $type): string
    {
        $type = strtolower($type);
        $terms = $type === 'alcohol'
            ? ['alcohol', 'alcohal', 'alcahol', 'alchol', 'ethanol', 'wine', 'beer', 'rum', 'brandy', 'liqueur', 'spirit', 'vanilla extract']
            : ['gelatin', 'gelatine', 'pork', 'lard', 'animal fat', 'carmine', 'rennet', 'enzymes', 'whey', 'casein', 'milk', 'butter', 'cheese', 'egg', 'honey', 'yogurt', 'yoghurt'];

        $label = $type === 'alcohol' ? 'alcohol-related' : 'animal-derived';
        $checked = 0;
        $withHits = [];
        $withoutHits = [];
        $missingIngredients = [];

        foreach (array_slice($products, 0, 8) as $product) {
            $name = (string) ($product['name'] ?? 'Unnamed product');
            $ingredients = trim((string) ($product['ingredients'] ?? ''));

            if ($ingredients === '') {
                $missingIngredients[] = $name;
                continue;
            }

            $checked++;
            $hits = $this->collectHits(strtolower($ingredients), $terms);
            if (! empty($hits)) {
                $withHits[] = $name . ' (' . implode(', ', $hits) . ')';
            } else {
                $withoutHits[] = $name;
            }
        }

        if ($checked === 0 && empty($missingIngredients)) {
            return "I could not verify {$label} ingredients because ingredient data is not available for these results.";
        }

        $parts = [];
        if (! empty($withHits)) {
            $parts[] = 'possible ' . $label . ' terms found in: ' . implode('; ', array_slice($withHits, 0, 5));
        }
        if (! empty($withoutHits)) {
            $parts[] = 'no obvious ' . $label . ' terms found in: ' . implode(', ', array_slice($withoutHits, 0, 5));
        }
        if (! empty($missingIngredients)) {
            $parts[] = 'ingredient data missing for: ' . implode(', ', array_slice($missingIngredients, 0, 4));
        }

        return ucfirst($label) . ' check from available ingredients — ' . implode('. ', $parts) . '.';
    }

    /**
     * Builds "focused product details for list" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildFocusedProductDetailsForList(array $product, string $messageLower, array $intent): string
    {
        $name        = (string) ($product['name'] ?? 'This product');
        $decision    = $this->normalizeDecision((string) ($product['decision'] ?? ($product['status'] ?? 'unknown')));
        $barcode     = trim((string) ($product['barcode'] ?? ''));
        $ingredients = trim((string) ($product['ingredients'] ?? ''));
        $focuses     = $this->normalizeFocuses(
            is_array($intent['question_focuses'] ?? null) ? $intent['question_focuses'] : [],
            $messageLower
        );

        $parts = ["For {$name}: it is marked as {$decision} in our records"];

        if (in_array('barcode', $focuses, true) || $this->asksForBarcode($messageLower)) {
            $parts[] = $barcode !== '' ? "barcode: {$barcode}" : 'barcode is not available';
        }

        if (in_array('ingredients', $focuses, true) || $this->asksForIngredients($messageLower)) {
            $parts[] = $ingredients !== '' ? "ingredients: {$ingredients}" : 'ingredients are not available';
        }

        if (in_array('animal_derived_check', $focuses, true) || $this->asksForAnimalDerivedCheck($messageLower)) {
            if ($ingredients === '') {
                $parts[] = 'animal-derived check: ingredient list is not available';
            } else {
                $lower = strtolower($ingredients);
                $hits = $this->collectHits($lower, ['gelatin', 'whey', 'casein', 'milk', 'butter', 'cheese', 'animal fat', 'carmine', 'egg', 'honey', 'yogurt']);
                $parts[] = empty($hits)
                    ? 'animal-derived check: no obvious animal-derived terms were found in the listed ingredients'
                    : 'animal-derived check: possible animal-derived terms found — ' . implode(', ', $hits);
            }
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Builds recommendation replies while still respecting filters like not haram or without alcohol.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildRecommendationReply(array $products, array $meta, string $messageLower, array $intent = []): string
    {
        $category      = strtolower(trim((string) ($meta['category'] ?? '')));
        $statusInclude = array_map('strtolower', (array) ($meta['status_include'] ?? []));
        $requestedStatus = $statusInclude[0] ?? strtolower(trim((string) ($meta['status'] ?? '')));
        $usedStatusFallback = (bool) ($meta['used_status_fallback'] ?? false);
        $origin        = strtolower(trim((string) ($meta['origin'] ?? '')));

        $top = array_slice($products, 0, 4);

        if (empty($top)) {
            return 'I could not find suitable recommendation results.';
        }

        $intro = 'Here are a few practical options';
        if ($category !== '') {
            $intro .= ' for ' . $category;
        }
        if ($origin !== '') {
            $intro .= ' from ' . $origin;
        }

        if ($requestedStatus === 'halal' && ! $usedStatusFallback) {
            $intro .= ' that match your safer preference most closely';
        } elseif ($requestedStatus === 'halal' && $usedStatusFallback) {
            $intro .= ' that look broadly suitable, although exact halal-only matches were limited';
        } elseif ($requestedStatus === 'haram') {
            $intro .= ' marked as haram';
        }

        $lines = [];

        foreach ($top as $product) {
            $name        = (string) ($product['name'] ?? 'Unnamed product');
            $decision    = $this->productDecision($product);
            $brand       = trim((string) ($product['brand'] ?? ''));
            $origin_p    = trim((string) ($product['origin'] ?? ''));
            $ingredients = trim((string) ($product['ingredients'] ?? ''));

            $details = [];
            if ($brand !== '') {
                $details[] = "brand {$brand}";
            }
            if ($origin_p !== '') {
                $details[] = "origin {$origin_p}";
            }
            if ($ingredients !== '') {
                $details[] = 'ingredients listed';
            }

            $detailText = empty($details) ? '' : ' (' . implode(', ', $details) . ')';
            $lines[] = "{$name} — {$decision}{$detailText}";
        }

        $reply = $intro . ': ' . implode('; ', $lines) . '.';

        if ($usedStatusFallback) {
            $reply .= ' I did not find enough exact matches for your stricter preference, so I included the closest available alternatives.';
        }

        if (str_contains($messageLower, 'ingredient') || str_contains($messageLower, 'safe') || str_contains($messageLower, 'doubtful')) {
            $reply .= ' Ask me about any one item from this list and I will break down its ingredients, barcode, and safety notes.';
        }

        return $reply;
    }

    // ─────────────────────────────────────────────────────────────
    // GENERAL / SCOPE REPLIES
    // ─────────────────────────────────────────────────────────────

    /**
     * Builds "records scope reply" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildDatabaseScopeReply(string $messageLower): string
    {
        $messageLower = strtolower(trim($messageLower));

        if ($messageLower === '' || preg_match('/^(hi|hello|hey|salam|salaam|assalamualaikum|assalamu alaikum)$/iu', $messageLower) === 1) {
            return 'Hi! I am your halal product assistant. Send a product name, barcode, image, category, ingredient, or country and I will check it for you.';
        }

        if (preg_match('/\b(tell me about yourself|who are you|what can you do|help|guide me|how do you work)\b/iu', $messageLower) === 1) {
            return 'I can help with halal status, barcode, ingredients, origin, brand, category browsing, and product suggestions.';
        }

        if (preg_match('/\b(thanks|thank you|shukriya|jazakallah)\b/iu', $messageLower) === 1) {
            return 'You are welcome. Send another product name, barcode, category, country, or ingredient whenever you need a check.';
        }

        return 'I can answer product questions: halal status, ingredients, barcode, origin, brand, category lists, and recommendations. Send a product name, barcode, image, category, country, or ingredient to check.';
    }

    // ─────────────────────────────────────────────────────────────
    // FOCUS NORMALIZATION
    // ─────────────────────────────────────────────────────────────

    /**
     * Product helper used for "product decision".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function productDecision(array $product): string
    {
        foreach (['type', 'decision', 'status'] as $key) {
            $value = $this->normalizeDecision((string) ($product[$key] ?? ''));
            if ($value !== 'unknown') {
                return $value;
            }
        }

        return 'unknown';
    }

    /**
     * Normalizes what the user asked to see: details, barcode, ingredients, origin, safety, etc.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeFocuses(array $focuses, string $messageLower): array
    {
        $normalized = [];

        foreach ($focuses as $focus) {
            $focus = trim((string) $focus);
            if ($focus !== '') {
                $normalized[] = $focus;
            }
        }

        if ($this->asksForBrand($messageLower)) {
            $normalized[] = 'brand_name';
        }

        if ($this->asksForIngredients($messageLower)) {
            $normalized[] = 'ingredients';
        }

        if ($this->asksForBarcode($messageLower)) {
            $normalized[] = 'barcode';
        }

        if ($this->asksForOrigin($messageLower)) {
            $normalized[] = 'origin';
        }

        if ($this->asksForAlcoholCheck($messageLower)) {
            $normalized[] = 'alcohol_check';
        }

        if ($this->asksForAnimalDerivedCheck($messageLower)) {
            $normalized[] = 'animal_derived_check';
        }

        if ($this->asksForSafety($messageLower)) {
            $normalized[] = 'suspicious_check';
        }

        if ($this->asksForHalalStatus($messageLower)) {
            $normalized[] = 'halal_status';
        }

        if ($this->asksForHealth($messageLower)) {
            $normalized[] = 'health_check';
        }

        $order   = ['brand_name', 'ingredients', 'barcode', 'origin', 'alcohol_check', 'animal_derived_check', 'suspicious_check', 'health_check', 'halal_status'];
        $ordered = [];

        foreach ($order as $allowed) {
            if (in_array($allowed, $normalized, true)) {
                $ordered[] = $allowed;
            }
        }

        return array_values(array_unique($ordered));
    }

    // ─────────────────────────────────────────────────────────────
    // INGREDIENT CHECKS
    // ─────────────────────────────────────────────────────────────

    /**
     * Helper method for "format ingredients answer".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function formatIngredientsAnswer(string $name, string $ingredients): string
    {
        if ($ingredients === '') {
            return "I found {$name}, but its ingredients are not available in our records.";
        }

        return "{$name} ingredients: {$ingredients}";
    }

    /**
     * Checks whether one product contains a specific ingredient or alias.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function checkSpecificIngredient(string $name, string $ingredients, string $label, array $terms): string
    {
        $lower = strtolower($ingredients);

        if ($lower === '') {
            return "I found {$name}, but its ingredients are not available in our records, so I cannot verify {$label}.";
        }

        $hits = $this->collectHits($lower, $terms);

        if (! empty($hits)) {
            return "{$name} ingredients mention {$label}: " . implode(', ', $hits) . '.';
        }

        return "{$name} ingredients do not show {$label} in our records.";
    }

    /**
     * Checks product ingredients for alcohol-related terms.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function checkAlcoholicSubstances(string $name, string $ingredients): string
    {
        $lower = strtolower($ingredients);

        if ($lower === '') {
            return "I found {$name}, but its ingredients are not available in our records, so I cannot verify alcohol-related substances.";
        }

        $hits = $this->collectHits($lower, ['alcohol', 'alcohal', 'alcahol', 'alchol', 'ethanol', 'wine', 'beer', 'rum', 'brandy', 'liqueur', 'spirit', 'vanilla extract']);

        if (! empty($hits)) {
            return "{$name} ingredients mention possible alcohol-related terms: " . implode(', ', $hits) . '.';
        }

        return "{$name} ingredients do not show obvious alcohol-related terms in our records.";
    }

    /**
     * Checks product ingredients for animal-derived/suspicious terms.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function checkAnimalDerived(string $name, string $ingredients): string
    {
        $lower = strtolower($ingredients);

        if ($lower === '') {
            return "I found {$name}, but its ingredients are not available in our records, so I cannot verify animal-derived substances.";
        }

        $hits = $this->collectHits($lower, ['gelatin', 'whey', 'casein', 'milk', 'butter', 'cheese', 'animal fat', 'carmine', 'egg', 'honey', 'yogurt']);

        if (! empty($hits)) {
            return "{$name} ingredients include possibly animal-derived terms: " . implode(', ', $hits) . '.';
        }

        return "{$name} ingredients do not show obvious animal-derived terms in our records.";
    }

    /**
     * Helper method for "check suspicious".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function checkSuspicious(string $name, string $ingredients, string $decision): string
    {
        $lower = strtolower($ingredients);

        if ($lower === '') {
            return "{$name} is marked as {$decision} in our records, but its ingredients are not available, so I cannot fully verify whether anything sensitive is inside it.";
        }

        $hits = $this->collectHits($lower, ['gelatin', 'e471', 'alcohol', 'ethanol', 'carmine', 'lecithin', 'natural flavor', 'natural flavour', 'animal fat']);

        if (! empty($hits)) {
            return "{$name} contains ingredients worth reviewing: " . implode(', ', $hits) . '.';
        }

        return "{$name} is marked as {$decision} in our records, and I did not find obvious sensitive terms in the listed ingredients.";
    }

    /**
     * Helper method for "check health".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function checkHealth(string $name, string $ingredients, string $decision): string
    {
        $lower = strtolower($ingredients);

        if ($lower === '') {
            return "{$name} is marked as {$decision} in our records, but ingredients are not available, so I cannot judge its health profile properly.";
        }

        $positives = $this->collectHits($lower, ['almond', 'calcium', 'vitamin', 'fiber', 'protein', 'peanut', 'olive']);
        $concerns = $this->collectHits($lower, ['sugar', 'glucose', 'fructose', 'corn syrup', 'palm oil', 'hydrogenated oil', 'artificial', 'caffeine', 'salt']);
        $sensitive = $this->collectHits($lower, ['gelatin', 'alcohol', 'ethanol', 'carmine', 'animal fat']);

        $parts = [];
        $parts[] = "ingredients: {$ingredients}";
        if (! empty($positives)) {
            $parts[] = 'positives: ' . implode(', ', $positives);
        }
        if (! empty($concerns)) {
            $parts[] = 'health concerns/moderation: ' . implode(', ', $concerns);
        }
        if (! empty($sensitive)) {
            $parts[] = 'halal-sensitive terms: ' . implode(', ', $sensitive);
        }

        if (empty($concerns) && empty($sensitive)) {
            $parts[] = 'quick view: looks relatively okay from ingredients, but nutrition facts are still needed for a complete health judgement';
        }

        return "{$name} health check — " . implode('; ', $parts) . '.';
    }

    /**
     * Builds "multi product health reply" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildMultiProductHealthReply(array $products, string $messageLower): string
    {
        $lines = [];
        foreach (array_slice($products, 0, 8) as $product) {
            $name = (string) ($product['name'] ?? 'Unnamed product');
            $decision = $this->productDecision($product);
            $ingredients = trim((string) ($product['ingredients'] ?? ''));
            $lines[] = $this->checkHealth($name, $ingredients, $decision);
        }

        return implode(' ', $lines);
    }

    /**
     * Builds "comparison reply" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildComparisonReply(array $products, string $messageLower): string
    {
        $lines = [];
        foreach (array_slice($products, 0, 6) as $product) {
            $name = (string) ($product['name'] ?? 'Unnamed product');
            $decision = $this->productDecision($product);
            $origin = trim((string) ($product['origin'] ?? ''));
            $ingredients = trim((string) ($product['ingredients'] ?? ''));

            $summary = "{$name}";
            if ($origin !== '') {
                $summary .= " (origin: {$origin})";
            }
            $summary .= " — {$decision}. ";
            $summary .= $ingredients !== ''
                ? "Ingredients: {$ingredients}. "
                : "Ingredients are not available. ";

            $lower = strtolower($ingredients);
            $concerns = $this->collectHits($lower, ['sugar', 'glucose', 'fructose', 'corn syrup', 'palm oil', 'hydrogenated oil', 'artificial', 'caffeine', 'salt', 'gelatin', 'alcohol', 'carmine']);
            $summary .= empty($concerns)
                ? 'No obvious listed concern terms found.'
                : 'Concern terms: ' . implode(', ', $concerns) . '.';

            $lines[] = $summary;
        }

        return 'Comparison: ' . implode(' ', $lines);
    }

    /**
     * Helper method for "collect hits".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function collectHits(string $lower, array $terms): array
    {
        $hits = [];

        foreach ($terms as $term) {
            if (str_contains($lower, strtolower($term))) {
                $hits[] = $term;
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * Builds "product list" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildProductList(array $products, int $limit = 5): string
    {
        $top = array_slice($products, 0, $limit);

        $names = array_map(function ($product) {
            $name     = (string) ($product['name'] ?? 'Unnamed product');
            $decision = $this->productDecision($product);
            return "{$name} ({$decision})";
        }, $top);

        return implode(', ', $names);
    }

    // ─────────────────────────────────────────────────────────────
    // UTILITIES
    // ─────────────────────────────────────────────────────────────

    /**
     * Normalizes "decision" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeDecision(string $decision): string
    {
        $decision = strtolower(trim($decision));
        $decision = str_replace(['_', '-'], ' ', $decision);

        return match ($decision) {
            'approved', 'approve', 'halal certified', 'halal', 'permissible', 'permitted' => 'halal',
            'haram', 'not halal', 'non halal', 'forbidden', 'prohibited' => 'haram',
            'mashbooh', 'mushbooh', 'doubtful', 'suspect', 'questionable' => 'mushbooh',
            'decision pending', 'pending', 'unknown', 'not found', 'unverified', 'needs review', 'needs verification', '' => 'unknown',
            'out of scope', 'non food', 'non-food', 'not food' => 'out of scope',
            default => $decision,
        };
    }

    /**
     * Helper method for "join sections".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function joinSections(array $sections): string
    {
        $sections = array_values(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $sections
        )));

        return implode(' ', array_values(array_unique($sections)));
    }

    protected function normalizeMessageText(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }

        $replacements = [
            '/(?<![a-z0-9])dose(?![a-z0-9])/iu' => 'does',
            '/(?<![a-z0-9])doze(?![a-z0-9])/iu' => 'does',
            '/(?<![a-z0-9])alcohal(?![a-z0-9])/iu' => 'alcohol',
            '/(?<![a-z0-9])alcahol(?![a-z0-9])/iu' => 'alcohol',
            '/(?<![a-z0-9])alchol(?![a-z0-9])/iu' => 'alcohol',
            '/(?<![a-z0-9])sprit(?![a-z0-9])/iu' => 'sprite',
            '/(?<![a-z0-9])dairymilk(?![a-z0-9])/iu' => 'dairy milk',
            '/(?<![a-z0-9])detol(?![a-z0-9])/iu' => 'dettol',
            '/animal[-\s]*driven/iu' => 'animal derived',
            '/animal-derived/iu' => 'animal derived',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Boolean helper that checks whether the current request or product matches "looks recommendation".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function looksRecommendation(string $messageLower): bool
    {
        return (bool) preg_match('/\brecommend\b|\bsuggest\b|\boptions\b|\bparty\b|\bguests\b|\bgathering\b|\bsafer snack options\b|\bsmall gathering\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for brand".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForBrand(string $messageLower): bool
    {
        return (bool) preg_match('/\bbrand\b|\bcompany\b|\bmanufacturer\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for ingredients".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForIngredients(string $messageLower): bool
    {
        return (bool) preg_match("/\bingredients?\b|\bwhat is in\b|\bwhat'?s in\b|\bcontains?\b|\bmade of\b|\binside\b/i", $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for barcode".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForBarcode(string $messageLower): bool
    {
        return (bool) preg_match('/\bbarcode\b|\bbar code\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for origin".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForOrigin(string $messageLower): bool
    {
        return (bool) preg_match('/\borigin\b|\bmade in\b|\bwhere.*made\b|\bwhere.*from\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for gelatin check".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForGelatinCheck(string $messageLower): bool
    {
        return (bool) preg_match('/\bgelatin\b|\bgelatine\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for palm oil check".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForPalmOilCheck(string $messageLower): bool
    {
        return (bool) preg_match('/\bpalm\s+oil\b|\bpalmolein\b|\bpalm\s+olein\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for alcohol check".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForAlcoholCheck(string $messageLower): bool
    {
        return (bool) preg_match('/\balcohol\b|\balcohal\b|\balcahol\b|\balchol\b|\balcoholic\b|\bethanol\b|\bisopropyl\s+alcohol\b|\bwine\b|\bbeer\b|\brum\b|\bbrandy\b|\bliqueur\b|\bspirit\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for animal derived check".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForAnimalDerivedCheck(string $messageLower): bool
    {
        if (preg_match('/\b(?:any\s+of\s+)?(?:them|these|those|the\s+products?|the\s+items?|results?)\b.*\b(?:gelatin|gelatine|animal|derived|driven|pork|rennet|carmine)\b/i', $messageLower) === 1) {
            return true;
        }

        if (preg_match('/\banimal\b|\bderived\b|\bdriven\b|\bcarmine\b|\bpork\b|\brennet\b|\bvegan\b|\bvegetarian\b/i', $messageLower) === 1) {
            return true;
        }

        // A catalog filter like "show snacks without gelatin" or "snacks with gelatin"
        // already uses gelatin as an include/exclude filter. Do not add a separate
        // broad animal-derived summary unless the user explicitly asks about it.
        if (preg_match('/\b(?:show|find|list|give|suggest|recommend|products?|items?|snacks?|drinks?|chocolates?|biscuits?|cookies?|cakes?)\b[^.?!;]{0,120}\b(?:with|without|contain|contains|containing|free\s+from|no)\b[^.?!;]{0,80}\b(?:gelatin|gelatine)\b/i', $messageLower) === 1) {
            return false;
        }

        return (bool) preg_match('/\bgelatin\b|\bgelatine\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for safety".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForSafety(string $messageLower): bool
    {
        return (bool) preg_match('/\bsafe\b|\bunsafe\b|\bsuspicious\b|\bharmful\b|\bproblematic\b|\bshould i avoid\b|\banything bad\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for health".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForHealth(string $messageLower): bool
    {
        return (bool) preg_match('/\bhealth\b|\bhealthy\b|\bgood for health\b|\bharmful\b|\bunhealthy\b|\bunsafe\b|\bbad for health\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for halal status".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForHalalStatus(string $messageLower): bool
    {
        return (bool) preg_match('/\bhalal\b|\bharam\b|\bmushbooh\b|\bmashbooh\b|\bdoubtful\b/i', $messageLower);
    }

    /**
     * Boolean helper that checks whether the current request or product matches "asks for comparison".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function asksForComparison(string $messageLower): bool
    {
        return (bool) preg_match('/\bcompare\b|\bwhich is better\b|\bwhich is healthier\b|\bversus\b|\bvs\b/i', $messageLower);
    }
}
