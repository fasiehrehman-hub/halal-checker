<?php

namespace App\Services;

class ProductReasoningService
{
    public function buildReply(string $message, array $lookup, array $intent, ?array $imageContext = null): string
    {
        $tool = (string) ($intent['tool_name'] ?? '');
        $focuses = array_values(array_unique(array_filter(
            is_array($intent['question_focuses'] ?? null) ? $intent['question_focuses'] : [$intent['question_focus'] ?? 'details']
        )));
        $status = (string) ($lookup['status'] ?? 'not_found');
        $products = is_array($lookup['products'] ?? null) ? $lookup['products'] : [];
        $ingredientExplanation = $lookup['ingredient_explanation'] ?? null;
        $messageLower = strtolower(trim($message));
        $meta = is_array($lookup['meta'] ?? null) ? $lookup['meta'] : [];

        if ($status === 'error') {
            return 'Sorry, something went wrong while checking that. Please try again.';
        }

        if ($tool === 'explain_ingredient' && is_array($ingredientExplanation)) {
            return ($ingredientExplanation['ingredient'] ?? 'This ingredient') . ': ' . ($ingredientExplanation['summary'] ?? 'No explanation available.');
        }

        if ($status !== 'found' || empty($products)) {
            if (!empty($imageContext)) {
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

    protected function buildSingleProductReply(string $messageLower, array $product, array $focuses): string
    {
        $name = (string) ($product['name'] ?? 'This product');
        $brand = trim((string) ($product['brand'] ?? ''));
        $barcode = trim((string) ($product['barcode'] ?? ''));
        $decision = $this->normalizeDecision((string) ($product['decision'] ?? 'unknown'));
        $origin = trim((string) ($product['origin'] ?? ''));
        $ingredients = trim((string) ($product['ingredients'] ?? ''));

        $sections = [];
        $focuses = $this->normalizeFocuses($focuses, $messageLower);

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

    protected function buildSearchReply(string $messageLower, array $products, array $focuses, array $intent, array $meta): string
    {
        if ($this->looksRecommendation($messageLower) || (($intent['question_focus'] ?? '') === 'recommendation') || (($meta['is_recommendation'] ?? false) === true)) {
            return $this->buildRecommendationReply($products, $meta, $messageLower);
        }

        $topProduct = $products[0] ?? [];

        if (!empty($focuses)) {
            return $this->buildSingleProductReply($messageLower, $topProduct, $focuses);
        }

        if (($intent['question_focus'] ?? '') === 'similar_products') {
            return 'Here are some related products from your database: ' . $this->buildProductList($products) . '.';
        }

        return 'Here are the best matches from your database: ' . $this->buildProductList($products) . '.';
    }

    protected function buildRecommendationReply(array $products, array $meta, string $messageLower): string
    {
        $category = strtolower(trim((string) ($meta['category'] ?? '')));
        $requestedStatus = strtolower(trim((string) ($meta['status'] ?? '')));
        $usedStatusFallback = (bool) ($meta['used_status_fallback'] ?? false);

        $top = array_slice($products, 0, 4);

        if (empty($top)) {
            return 'I could not find suitable recommendation results in your database.';
        }

        $intro = 'Here are a few practical options from your database';
        if ($category !== '') {
            $intro .= ' for ' . $category;
        }

        if ($requestedStatus === 'halal' && !$usedStatusFallback) {
            $intro .= ' that match your safer preference most closely';
        } elseif ($requestedStatus === 'halal' && $usedStatusFallback) {
            $intro .= ' that look broadly suitable, although exact halal-only matches were limited';
        }

        $lines = [];

        foreach ($top as $product) {
            $name = (string) ($product['name'] ?? 'Unnamed product');
            $decision = $this->normalizeDecision((string) ($product['decision'] ?? 'unknown'));
            $brand = trim((string) ($product['brand'] ?? ''));
            $origin = trim((string) ($product['origin'] ?? ''));
            $ingredients = trim((string) ($product['ingredients'] ?? ''));

            $details = [];
            if ($brand !== '') {
                $details[] = "brand {$brand}";
            }
            if ($origin !== '') {
                $details[] = "origin {$origin}";
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

        $ordered = [];
        foreach (['brand_name', 'ingredients', 'barcode', 'origin', 'alcohol_check', 'animal_derived_check', 'suspicious_check', 'halal_status'] as $allowed) {
            if (in_array($allowed, $normalized, true)) {
                $ordered[] = $allowed;
            }
        }

        return array_values(array_unique($ordered));
    }

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

        if (!empty($hits)) {
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

        if (!empty($hits)) {
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

        if (!empty($hits)) {
            return "{$name} contains ingredients worth reviewing: " . implode(', ', $hits) . '.';
        }

        return "{$name} is marked as {$decision} in your database, and I did not find obvious sensitive terms in the listed ingredients.";
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
            $name = (string) ($product['name'] ?? 'Unnamed product');
            $decision = $this->normalizeDecision((string) ($product['decision'] ?? 'unknown'));
            return "{$name} ({$decision})";
        }, $top);

        return implode(', ', $names);
    }

    protected function normalizeDecision(string $decision): string
    {
        $decision = strtolower(trim($decision));

        return match ($decision) {
            'mashbooh' => 'mushbooh',
            '' => 'unknown',
            default => $decision,
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
        return (bool) preg_match('/\bbarcode\b|\bcode\b/i', $messageLower);
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

    protected function asksForHalalStatus(string $messageLower): bool
    {
        return (bool) preg_match('/\bhalal\b|\bharam\b|\bmushbooh\b|\bmashbooh\b|\bdoubtful\b/i', $messageLower);
    }
}
