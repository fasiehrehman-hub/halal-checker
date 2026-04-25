<?php

namespace App\Services;

class ProductReasoningService
{
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
        $messageLower = strtolower(trim($message));
        $meta     = is_array($lookup['meta'] ?? null) ? $lookup['meta'] : [];

        if ($status === 'error') {
            return 'Sorry, something went wrong while checking that. Please try again.';
        }

        if ($tool === 'explain_ingredient' && is_array($ingredientExplanation)) {
            return ($ingredientExplanation['ingredient'] ?? 'This ingredient') . ': ' . ($ingredientExplanation['summary'] ?? 'No explanation available.');
        }

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
                    return "The image appears to show {$label}, but I could not confidently match it in your database. Please send a clearer front photo, the barcode, or the exact product name.";
                }

                return 'I could not confidently match this image to a product in your database. Please send a clearer front photo, the barcode, or the exact product name.';
            }

            if ($this->looksRecommendation($messageLower)) {
                return 'I could not find strong recommendation matches for that request in your database. Try a broader category like snacks, chips, biscuits, or drinks, and I will suggest the closest options.';
            }

            return (string) ($lookup['message'] ?? 'I could not find a matching product.');
        }

        if (in_array($tool, ['find_product_by_barcode', 'find_product_by_name'], true)) {
            return $this->buildSingleProductReply($messageLower, $products[0], $focuses);
        }

        if ($tool === 'search_products') {
            return $this->buildSearchReply($messageLower, $products, $focuses, $intent, $meta);
        }

        return $this->buildSingleProductReply($messageLower, $products[0], $focuses);
    }

    // ─────────────────────────────────────────────────────────────
    // SINGLE PRODUCT REPLY
    // ─────────────────────────────────────────────────────────────

    protected function buildSingleProductReply(string $messageLower, array $product, array $focuses): string
    {
        $name        = (string) ($product['name'] ?? 'This product');
        $brand       = trim((string) ($product['brand'] ?? ''));
        $barcode     = trim((string) ($product['barcode'] ?? ''));
        $decision    = $this->normalizeDecision((string) ($product['decision'] ?? 'unknown'));
        $origin      = trim((string) ($product['origin'] ?? ''));
        $ingredients = trim((string) ($product['ingredients'] ?? ''));

        $sections = [];
        $focuses  = $this->normalizeFocuses($focuses, $messageLower);

        foreach ($focuses as $focus) {
            switch ($focus) {
                case 'brand_name':
                    $sections[] = $brand !== ''
                        ? "{$name} brand is {$brand}."
                        : "I found {$name}, but its brand is not available in your database.";
                    break;

                case 'ingredients':
                    $sections[] = $this->formatIngredientsAnswer($name, $ingredients);
                    break;

                case 'barcode':
                    $sections[] = $barcode !== ''
                        ? "{$name} barcode is {$barcode}."
                        : "I found {$name}, but its barcode is not available in your database.";
                    break;

                case 'origin':
                    $sections[] = $origin !== ''
                        ? "{$name} origin is {$origin}."
                        : "I found {$name}, but its origin is not available in your database.";
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
                    $sections[] = "{$name} is marked as {$decision} in your database.";
                    break;
            }
        }

        if (empty($sections)) {
            $sections[] = "{$name} is marked as {$decision} in your database.";

            if ($brand !== '') {
                $sections[] = "Brand: {$brand}.";
            }

            if ($ingredients !== '') {
                $sections[] = "Ingredients: {$ingredients}.";
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

    // ─────────────────────────────────────────────────────────────
    // SEARCH REPLY
    // ─────────────────────────────────────────────────────────────

    protected function buildSearchReply(string $messageLower, array $products, array $focuses, array $intent, array $meta): string
    {
        $isRecommendation = $this->looksRecommendation($messageLower)
            || ($intent['question_focus'] ?? '') === 'recommendation'
            || ($meta['is_recommendation'] ?? false) === true;

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
        $isSingleFocusQuery = ! empty($focuses)
            && $focuses !== ['details']
            && count($products) === 1;

        if ($isSingleFocusQuery) {
            return $this->buildSingleProductReply($messageLower, $products[0], $focuses);
        }

        // FIX: Build a proper multi-product listing response
        return $this->buildMultiProductListReply($products, $meta, $messageLower, $intent);
    }

    protected function buildMultiProductListReply(array $products, array $meta, string $messageLower, array $intent): string
    {
        $statusInclude = array_map('strtolower', (array) ($meta['status_include'] ?? []));
        $statusExclude = array_map('strtolower', (array) ($meta['status_exclude'] ?? []));
        $origin        = strtolower(trim((string) ($meta['origin'] ?? '')));
        $origins       = array_values(array_filter(array_map('strtolower', (array) ($meta['origins'] ?? []))));
        $category      = strtolower(trim((string) ($meta['category'] ?? '')));
        $ingredientsInclude = array_values(array_filter(array_map('strtolower', (array) ($meta['ingredients_include'] ?? []))));
        $ingredientsExclude = array_values(array_filter(array_map('strtolower', (array) ($meta['ingredients_exclude'] ?? []))));
        $usedFallback  = (bool) ($meta['used_status_fallback'] ?? false);
        $count         = count($products);

        // Build intro
        $intro = "Found {$count} product" . ($count !== 1 ? 's' : '');

        $filters = [];
        if (! empty($statusInclude) && ! $usedFallback) {
            $filters[] = implode('/', $statusInclude);
        }
        if ($category !== '') {
            $filters[] = $category;
        }
        if (! empty($origins)) {
            $filters[] = 'from ' . implode(' or ', $origins);
        } elseif ($origin !== '') {
            $filters[] = 'from ' . $origin;
        }
        if (! empty($ingredientsInclude)) {
            $filters[] = 'with ' . implode(' + ', $ingredientsInclude);
        }
        if (! empty($ingredientsExclude)) {
            $filters[] = 'without ' . implode(' + ', $ingredientsExclude);
        }

        if (! empty($filters)) {
            $intro .= ' (' . implode(', ', $filters) . ')';
        }

        if ($usedFallback && ! empty($statusInclude)) {
            $intro .= '. Note: exact ' . implode('/', $statusInclude) . ' matches were limited; showing closest alternatives';
        }

        $lines = [];
        foreach (array_slice($products, 0, 12) as $product) {
            $name     = (string) ($product['name'] ?? 'Unnamed product');
            $decision = $this->normalizeDecision((string) ($product['decision'] ?? 'unknown'));
            $origin_p = trim((string) ($product['origin'] ?? ''));
            $brand_p  = trim((string) ($product['brand'] ?? ''));

            $details = [];
            if ($brand_p !== '') {
                $details[] = $brand_p;
            }
            if ($origin_p !== '') {
                $details[] = 'origin: ' . $origin_p;
            }

            $detailText = ! empty($details) ? ' (' . implode(', ', $details) . ')' : '';
            $lines[] = "{$name} — {$decision}{$detailText}";
        }

        $reply = $intro . ': ' . implode('; ', $lines) . '.';

        // Hint for follow-up
        if ($this->asksForHealth($messageLower) || str_contains($messageLower, 'harmful')) {
            $reply .= " Health notes: check each item's ingredient list; products high in sugar, palm oil, artificial additives, or sensitive animal/alcohol-derived terms need extra review.";
        }

        $reply .= ' Ask me about any one item for full ingredient and safety details.';

        return $reply;
    }
    protected function buildRecommendationReply(array $products, array $meta, string $messageLower, array $intent = []): string
    {
        $category      = strtolower(trim((string) ($meta['category'] ?? '')));
        $statusInclude = array_map('strtolower', (array) ($meta['status_include'] ?? []));
        $requestedStatus = $statusInclude[0] ?? strtolower(trim((string) ($meta['status'] ?? '')));
        $usedStatusFallback = (bool) ($meta['used_status_fallback'] ?? false);
        $origin        = strtolower(trim((string) ($meta['origin'] ?? '')));

        $top = array_slice($products, 0, 4);

        if (empty($top)) {
            return 'I could not find suitable recommendation results in your database.';
        }

        $intro = 'Here are a few practical options from your database';
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
            $decision    = $this->normalizeDecision((string) ($product['decision'] ?? 'unknown'));
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
            $reply .= ' If you want, ask me about any one item from this list and I will break down its ingredients, barcode, and safety notes.';
        }

        return $reply;
    }

    // ─────────────────────────────────────────────────────────────
    // FOCUS NORMALIZATION
    // ─────────────────────────────────────────────────────────────

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

    protected function formatIngredientsAnswer(string $name, string $ingredients): string
    {
        if ($ingredients === '') {
            return "I found {$name}, but its ingredients are not available in your database.";
        }

        return "{$name} ingredients: {$ingredients}";
    }

    protected function checkAlcoholicSubstances(string $name, string $ingredients): string
    {
        $lower = strtolower($ingredients);

        if ($lower === '') {
            return "I found {$name}, but its ingredients are not available in your database, so I cannot verify alcohol-related substances.";
        }

        $hits = $this->collectHits($lower, ['alcohol', 'ethanol', 'wine', 'beer', 'rum', 'brandy', 'liqueur', 'spirit', 'vanilla extract']);

        if (! empty($hits)) {
            return "{$name} ingredients mention possible alcohol-related terms: " . implode(', ', $hits) . '.';
        }

        return "{$name} ingredients do not show obvious alcohol-related terms in your database record.";
    }

    protected function checkAnimalDerived(string $name, string $ingredients): string
    {
        $lower = strtolower($ingredients);

        if ($lower === '') {
            return "I found {$name}, but its ingredients are not available in your database, so I cannot verify animal-derived substances.";
        }

        $hits = $this->collectHits($lower, ['gelatin', 'whey', 'casein', 'milk', 'butter', 'cheese', 'animal fat', 'carmine', 'egg', 'honey', 'yogurt']);

        if (! empty($hits)) {
            return "{$name} ingredients include possibly animal-derived terms: " . implode(', ', $hits) . '.';
        }

        return "{$name} ingredients do not show obvious animal-derived terms in your database record.";
    }

    protected function checkSuspicious(string $name, string $ingredients, string $decision): string
    {
        $lower = strtolower($ingredients);

        if ($lower === '') {
            return "{$name} is marked as {$decision} in your database, but its ingredients are not available, so I cannot fully verify whether anything sensitive is inside it.";
        }

        $hits = $this->collectHits($lower, ['gelatin', 'e471', 'alcohol', 'ethanol', 'carmine', 'lecithin', 'natural flavor', 'natural flavour', 'animal fat']);

        if (! empty($hits)) {
            return "{$name} contains ingredients worth reviewing: " . implode(', ', $hits) . '.';
        }

        return "{$name} is marked as {$decision} in your database, and I did not find obvious sensitive terms in the listed ingredients.";
    }

    protected function checkHealth(string $name, string $ingredients, string $decision): string
    {
        $lower = strtolower($ingredients);

        if ($lower === '') {
            return "{$name} is marked as {$decision} in your database, but ingredients are not available, so I cannot judge its health profile properly.";
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

    protected function buildMultiProductHealthReply(array $products, string $messageLower): string
    {
        $lines = [];
        foreach (array_slice($products, 0, 8) as $product) {
            $name = (string) ($product['name'] ?? 'Unnamed product');
            $decision = $this->normalizeDecision((string) ($product['decision'] ?? 'unknown'));
            $ingredients = trim((string) ($product['ingredients'] ?? ''));
            $lines[] = $this->checkHealth($name, $ingredients, $decision);
        }

        return implode(' ', $lines);
    }

    protected function buildComparisonReply(array $products, string $messageLower): string
    {
        $lines = [];
        foreach (array_slice($products, 0, 6) as $product) {
            $name = (string) ($product['name'] ?? 'Unnamed product');
            $decision = $this->normalizeDecision((string) ($product['decision'] ?? 'unknown'));
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

    protected function buildProductList(array $products, int $limit = 5): string
    {
        $top = array_slice($products, 0, $limit);

        $names = array_map(function ($product) {
            $name     = (string) ($product['name'] ?? 'Unnamed product');
            $decision = $this->normalizeDecision((string) ($product['decision'] ?? 'unknown'));
            return "{$name} ({$decision})";
        }, $top);

        return implode(', ', $names);
    }

    // ─────────────────────────────────────────────────────────────
    // UTILITIES
    // ─────────────────────────────────────────────────────────────

    protected function normalizeDecision(string $decision): string
    {
        $decision = strtolower(trim($decision));

        return match ($decision) {
            'mashbooh' => 'mushbooh',
            ''         => 'unknown',
            default    => $decision,
        };
    }

    protected function joinSections(array $sections): string
    {
        $sections = array_values(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $sections
        )));

        return implode(' ', array_values(array_unique($sections)));
    }

    protected function looksRecommendation(string $messageLower): bool
    {
        return (bool) preg_match('/\brecommend\b|\bsuggest\b|\boptions\b|\bparty\b|\bguests\b|\bgathering\b|\bsafer snack options\b|\bsmall gathering\b/i', $messageLower);
    }

    protected function asksForBrand(string $messageLower): bool
    {
        return (bool) preg_match('/\bbrand\b|\bcompany\b|\bmanufacturer\b/i', $messageLower);
    }

    protected function asksForIngredients(string $messageLower): bool
    {
        return (bool) preg_match("/\bingredients?\b|\bwhat is in\b|\bwhat'?s in\b|\bcontains?\b|\bmade of\b|\binside\b/i", $messageLower);
    }

    protected function asksForBarcode(string $messageLower): bool
    {
        return (bool) preg_match('/\bbarcode\b|\bbar code\b/i', $messageLower);
    }

    protected function asksForOrigin(string $messageLower): bool
    {
        return (bool) preg_match('/\borigin\b|\bmade in\b|\bwhere.*made\b|\bwhere.*from\b/i', $messageLower);
    }

    protected function asksForAlcoholCheck(string $messageLower): bool
    {
        return (bool) preg_match('/\balcohol\b|\bethanol\b|\bwine\b|\bbeer\b|\brum\b|\bspirit\b/i', $messageLower);
    }

    protected function asksForAnimalDerivedCheck(string $messageLower): bool
    {
        return (bool) preg_match('/\banimal\b|\bgelatin\b|\bcarmine\b|\bvegan\b|\bvegetarian\b/i', $messageLower);
    }

    protected function asksForSafety(string $messageLower): bool
    {
        return (bool) preg_match('/\bsafe\b|\bunsafe\b|\bsuspicious\b|\bharmful\b|\bproblematic\b|\bshould i avoid\b|\banything bad\b/i', $messageLower);
    }

    protected function asksForHealth(string $messageLower): bool
    {
        return (bool) preg_match('/\bhealth\b|\bhealthy\b|\bgood for health\b|\bharmful\b|\bunhealthy\b|\bunsafe\b|\bbad for health\b/i', $messageLower);
    }

    protected function asksForHalalStatus(string $messageLower): bool
    {
        return (bool) preg_match('/\bhalal\b|\bharam\b|\bmushbooh\b|\bmashbooh\b|\bdoubtful\b/i', $messageLower);
    }

    protected function asksForComparison(string $messageLower): bool
    {
        return (bool) preg_match('/\bcompare\b|\bwhich is better\b|\bwhich is healthier\b|\bversus\b|\bvs\b/i', $messageLower);
    }
}
