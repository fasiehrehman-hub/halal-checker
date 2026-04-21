<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductLookupService
{
    protected string $table = 'products';

    public function executeTool(string $toolName, array $arguments = []): array
    {
        return match ($toolName) {
            'find_product_by_barcode' => $this->findProductByBarcode((string) ($arguments['barcode'] ?? '')),
            'find_product_by_name' => $this->findProductByName((string) ($arguments['name'] ?? ''), $arguments),
            'search_products' => $this->searchProducts($arguments),
            'explain_ingredient' => $this->explainIngredient((string) ($arguments['ingredient'] ?? '')),
            default => [
                'status' => 'error',
                'message' => 'Unknown tool requested.',
                'products' => [],
                'meta' => ['tool' => $toolName],
            ],
        };
    }

    public function findProductByBarcode(string $barcode): array
    {
        $barcode = $this->normalizeBarcode($barcode);

        if ($barcode === '') {
            return $this->notFound('No barcode was provided.', ['tool' => 'find_product_by_barcode']);
        }

        $products = Product::query()
            ->when($this->hasColumn('barcode'), function (Builder $query) use ($barcode): void {
                $query->where('barcode', $barcode)
                    ->orWhere('barcode', 'like', '%' . $barcode . '%');
            })
            ->limit(8)
            ->get();

        if ($products->isEmpty()) {
            return $this->notFound('I could not find this barcode in the database.', [
                'tool' => 'find_product_by_barcode',
                'barcode' => $barcode,
            ]);
        }

        return [
            'status' => 'found',
            'message' => 'Product found by barcode.',
            'products' => $products->map(fn (Product $product) => $this->formatProduct($product))->values()->all(),
            'meta' => [
                'tool' => 'find_product_by_barcode',
                'barcode' => $barcode,
                'result_count' => $products->count(),
            ],
        ];
    }

    public function findProductByName(string $name, array $arguments = []): array
    {
        $name = $this->normalizeText($name);
        $imageContext = is_array($arguments['image_context'] ?? null) ? $arguments['image_context'] : null;

        if ($name === '') {
            return $this->notFound('No product name was provided.', ['tool' => 'find_product_by_name']);
        }

        $tokens = $this->tokenize($name);
        $lower = Str::lower($name);
        $compact = $this->compact($name);

        $query = Product::query();

        $query->where(function (Builder $builder) use ($tokens, $lower, $compact, $imageContext): void {
            foreach (['name_normalized', 'name', 'brand_normalized', 'brand'] as $column) {
                if ($this->hasColumn($column)) {
                    $builder->orWhereRaw('LOWER(' . $column . ') = ?', [$lower])
                        ->orWhereRaw('REPLACE(LOWER(' . $column . '), " ", "") = ?', [$compact])
                        ->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $lower . '%']);
                }
            }

            if ($this->hasColumn('brand') && $this->hasColumn('name')) {
                $builder->orWhereRaw(
                    'REPLACE(LOWER(CONCAT(COALESCE(brand, ""), " ", COALESCE(name, ""))), " ", "") = ?',
                    [$compact]
                )->orWhereRaw(
                    'LOWER(CONCAT(COALESCE(brand, ""), " ", COALESCE(name, ""))) like ?',
                    ['%' . $lower . '%']
                );
            }

            if ($imageContext) {
                $imageProduct = strtolower(trim((string) ($imageContext['product_name'] ?? '')));
                $imageBrand = strtolower(trim((string) ($imageContext['brand'] ?? '')));

                if ($imageProduct !== '' && $this->hasColumn('name')) {
                    $builder->orWhereRaw('LOWER(name) like ?', ['%' . $imageProduct . '%']);
                }

                if ($imageBrand !== '' && $this->hasColumn('brand')) {
                    $builder->orWhereRaw('LOWER(brand) like ?', ['%' . $imageBrand . '%']);
                }
            }

            foreach ($tokens as $token) {
                foreach (['name_normalized', 'name', 'brand', 'description', 'category', 'main_category', 'main_category1'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $token . '%']);
                    }
                }
            }
        });

        $products = $this->rankByPhrase($query->limit(40)->get(), $name, $imageContext, true);

        if ($imageContext) {
            $products = $this->filterProductsByImageContext($products, $imageContext, true);
        }

        $topScore = (int) (($products->first()['__score'] ?? 0));
        $threshold = $imageContext ? 120 : 90;

        if ($products->isEmpty() || $topScore < $threshold) {
            return $this->notFound('I could not find this product in the database.', [
                'tool' => 'find_product_by_name',
                'name' => $name,
                'top_score' => $topScore,
            ]);
        }

        $filtered = $products
            ->filter(fn (array $product) => (int) ($product['__score'] ?? 0) >= max(40, $topScore - 70))
            ->take(8)
            ->map(function (array $product) {
                unset($product['__score'], $product['__image_product_hits'], $product['__image_brand_hits']);
                return $product;
            })
            ->values()
            ->all();

        return [
            'status' => 'found',
            'message' => 'Product search complete.',
            'products' => $filtered,
            'meta' => [
                'tool' => 'find_product_by_name',
                'name' => $name,
                'result_count' => count($filtered),
                'top_score' => $topScore,
            ],
        ];
    }

    public function searchProducts(array $arguments = []): array
    {
        $rawQueryText = $this->normalizeText((string) ($arguments['query'] ?? ''));
        $queryText = $this->normalizeSearchText($rawQueryText);

        $brand = $this->normalizeBrand((string) ($arguments['brand'] ?? ''));
        $category = $this->normalizeCategory((string) ($arguments['category'] ?? ''));
        $origin = $this->normalizeOrigin((string) ($arguments['origin'] ?? ''));
        $imageContext = is_array($arguments['image_context'] ?? null) ? $arguments['image_context'] : null;

        $ingredientsInclude = array_map(
            fn ($item) => $this->normalizeKeyword($item),
            $this->normalizeStringArray($arguments['ingredients_include'] ?? [])
        );

        $ingredientsExclude = array_map(
            fn ($item) => $this->normalizeKeyword($item),
            $this->normalizeStringArray($arguments['ingredients_exclude'] ?? [])
        );

        $status = $this->extractStatusFilter($arguments);
        $limit = max(1, min((int) ($arguments['limit'] ?? 12), 30));
        $matchMode = strtolower((string) ($arguments['match_mode'] ?? 'all'));
        $questionFocus = strtolower(trim((string) ($arguments['question_focus'] ?? '')));

        $edibleOnly = $this->wantsEdibleProducts($rawQueryText);
        $strictCategoryQuery = $this->isStrictCategoryQuery($rawQueryText, $category);
        $isRecommendation = $this->isRecommendationQuery($rawQueryText, $arguments);

        $attemptPlans = $this->buildSearchAttempts(
            queryText: $queryText,
            brand: $brand,
            category: $category,
            origin: $origin,
            ingredientsInclude: $ingredientsInclude,
            ingredientsExclude: $ingredientsExclude,
            status: $status,
            edibleOnly: $edibleOnly,
            strictCategoryQuery: $strictCategoryQuery,
            matchMode: $matchMode,
            isRecommendation: $isRecommendation
        );

        $bestCollection = collect();
        $bestMeta = [
            'attempt_name' => null,
            'used_status_fallback' => false,
            'used_query_fallback' => false,
            'used_broad_fallback' => false,
        ];

        foreach ($attemptPlans as $plan) {
            $collection = $this->buildSearchQuery(
                queryText: $plan['queryText'],
                brand: $plan['brand'],
                category: $plan['category'],
                origin: $plan['origin'],
                ingredientsInclude: $plan['ingredientsInclude'],
                ingredientsExclude: $plan['ingredientsExclude'],
                edibleOnly: $plan['edibleOnly'],
                broad: $plan['broad'],
                matchMode: $plan['matchMode']
            )->limit(180)->get();

            if ($collection->isNotEmpty()) {
                $bestCollection = $collection;
                $bestMeta = [
                    'attempt_name' => $plan['name'],
                    'used_status_fallback' => $plan['used_status_fallback'],
                    'used_query_fallback' => $plan['used_query_fallback'],
                    'used_broad_fallback' => $plan['used_broad_fallback'],
                ];
                break;
            }
        }

        if ($bestCollection->isEmpty()) {
            return $this->notFound('No products matched your filters.', [
                'tool' => 'search_products',
                'query' => $queryText,
                'raw_query' => $rawQueryText,
                'brand' => $brand,
                'category' => $category,
                'status' => $status,
                'origin' => $origin,
                'question_focus' => $questionFocus,
                'is_recommendation' => $isRecommendation,
            ]);
        }

        $rankingSeed = $this->buildRankingSeed($queryText, $brand, $category, $origin, $imageContext);
        $products = $this->rankByPhrase($bestCollection, $rankingSeed, $imageContext, false);

        if ($imageContext) {
            $products = $this->filterProductsByImageContext($products, $imageContext, false);
        }

        if ($strictCategoryQuery && $category !== '') {
            $products = $this->filterProductsByCategoryIntent($products, $category);
        }

        if ($products->isEmpty()) {
            return $this->notFound('I could not confidently match products for that request.', [
                'tool' => 'search_products',
                'query' => $queryText,
                'raw_query' => $rawQueryText,
                'brand' => $brand,
                'category' => $category,
                'origin' => $origin,
                'question_focus' => $questionFocus,
            ]);
        }

        $allProducts = $products->map(function ($product) {
            $formatted = $this->formatProduct($product);
            if (isset($product['__score'])) {
                $formatted['__score'] = $product['__score'];
            }
            return $formatted;
        })->values();

        $preferredProducts = $allProducts;
        $fallbackProducts = collect();

        if ($status !== null) {
            $preferredProducts = $allProducts
                ->filter(fn (array $product) => strtolower((string) ($product['status'] ?? 'unknown')) === $status)
                ->values();

            $fallbackProducts = $allProducts
                ->filter(fn (array $product) => strtolower((string) ($product['status'] ?? 'unknown')) !== $status)
                ->values();

            if ($preferredProducts->isEmpty()) {
                $preferredProducts = $fallbackProducts;
                $bestMeta['used_status_fallback'] = true;
            }
        }

        $finalProducts = $preferredProducts->take($limit)->values();

        if ($finalProducts->isEmpty()) {
            $finalProducts = $allProducts->take($limit)->values();
        }

        return [
            'status' => 'found',
            'message' => $bestMeta['used_status_fallback']
                ? 'I found related products, but not enough exact status matches.'
                : 'Product search complete.',
            'products' => $finalProducts
                ->map(function (array $product) {
                    unset($product['__score']);
                    return $product;
                })
                ->all(),
            'meta' => [
                'tool' => 'search_products',
                'query' => $queryText,
                'raw_query' => $rawQueryText,
                'brand' => $brand,
                'category' => $category,
                'status' => $status,
                'origin' => $origin,
                'result_count' => $finalProducts->count(),
                'question_focus' => $questionFocus,
                'is_recommendation' => $isRecommendation,
                'used_status_fallback' => $bestMeta['used_status_fallback'],
                'used_query_fallback' => $bestMeta['used_query_fallback'],
                'used_broad_fallback' => $bestMeta['used_broad_fallback'],
                'attempt_name' => $bestMeta['attempt_name'],
            ],
        ];
    }

    public function explainIngredient(string $ingredient): array
    {
        $ingredient = $this->normalizeText($ingredient);

        if ($ingredient === '') {
            return [
                'status' => 'not_found',
                'message' => 'No ingredient was provided.',
                'products' => [],
                'ingredient_explanation' => null,
                'meta' => ['tool' => 'explain_ingredient'],
            ];
        }

        $dictionary = [
            'gelatin' => 'Gelatin is usually derived from animal collagen. It can be sensitive for halal, kosher, vegetarian, and vegan users.',
            'e471' => 'E471 refers to mono- and diglycerides of fatty acids. Its source may be plant or animal based, so the source matters.',
            'lecithin' => 'Lecithin is often sourced from soy or sunflower, but some users still want source confirmation for dietary reasons.',
            'carmine' => 'Carmine is a red color derived from insects, so it is not vegetarian and may be unsuitable for some users.',
            'alcohol' => 'Alcohol can be used as a flavor carrier or solvent. The product context matters for dietary and religious decisions.',
            'natural flavor' => 'Natural flavor is a broad label. It does not automatically mean unsafe, but the exact source is often not visible from the label alone.',
        ];

        $key = strtolower($ingredient);
        $summary = $dictionary[$key] ?? 'No curated explanation is available for this ingredient yet.';

        return [
            'status' => 'found',
            'message' => 'Ingredient explanation found.',
            'products' => [],
            'ingredient_explanation' => [
                'ingredient' => $ingredient,
                'summary' => $summary,
            ],
            'meta' => ['tool' => 'explain_ingredient'],
        ];
    }

    protected function buildSearchAttempts(
        string $queryText,
        string $brand,
        string $category,
        string $origin,
        array $ingredientsInclude,
        array $ingredientsExclude,
        ?string $status,
        bool $edibleOnly,
        bool $strictCategoryQuery,
        string $matchMode,
        bool $isRecommendation
    ): array {
        $attempts = [];

        $attempts[] = [
            'name' => 'strict',
            'queryText' => $queryText,
            'brand' => $brand,
            'category' => $category,
            'origin' => $origin,
            'ingredientsInclude' => $ingredientsInclude,
            'ingredientsExclude' => $ingredientsExclude,
            'edibleOnly' => $edibleOnly,
            'broad' => false,
            'matchMode' => $matchMode,
            'used_status_fallback' => false,
            'used_query_fallback' => false,
            'used_broad_fallback' => false,
        ];

        if ($status !== null) {
            $attempts[] = [
                'name' => 'status_relaxed',
                'queryText' => $queryText,
                'brand' => $brand,
                'category' => $category,
                'origin' => $origin,
                'ingredientsInclude' => $ingredientsInclude,
                'ingredientsExclude' => $ingredientsExclude,
                'edibleOnly' => $edibleOnly,
                'broad' => false,
                'matchMode' => $matchMode,
                'used_status_fallback' => true,
                'used_query_fallback' => false,
                'used_broad_fallback' => false,
            ];
        }

        if ($queryText !== '' && !$strictCategoryQuery) {
            $attempts[] = [
                'name' => 'query_relaxed',
                'queryText' => '',
                'brand' => $brand,
                'category' => $category,
                'origin' => $origin,
                'ingredientsInclude' => $ingredientsInclude,
                'ingredientsExclude' => $ingredientsExclude,
                'edibleOnly' => $edibleOnly,
                'broad' => false,
                'matchMode' => $matchMode,
                'used_status_fallback' => $status !== null,
                'used_query_fallback' => true,
                'used_broad_fallback' => false,
            ];
        }

        if ($isRecommendation || $category !== '' || $brand !== '') {
            $attempts[] = [
                'name' => 'browse_fallback',
                'queryText' => '',
                'brand' => $brand,
                'category' => $category,
                'origin' => '',
                'ingredientsInclude' => [],
                'ingredientsExclude' => $ingredientsExclude,
                'edibleOnly' => $edibleOnly,
                'broad' => true,
                'matchMode' => 'any',
                'used_status_fallback' => true,
                'used_query_fallback' => true,
                'used_broad_fallback' => true,
            ];
        }

        if (!$strictCategoryQuery && ($queryText !== '' || $brand !== '')) {
            $attempts[] = [
                'name' => 'broad_text_fallback',
                'queryText' => $queryText,
                'brand' => $brand,
                'category' => '',
                'origin' => '',
                'ingredientsInclude' => [],
                'ingredientsExclude' => [],
                'edibleOnly' => $edibleOnly,
                'broad' => true,
                'matchMode' => 'any',
                'used_status_fallback' => true,
                'used_query_fallback' => true,
                'used_broad_fallback' => true,
            ];
        }

        return $attempts;
    }

    protected function buildSearchQuery(
        string $queryText,
        string $brand,
        string $category,
        string $origin,
        array $ingredientsInclude,
        array $ingredientsExclude,
        bool $edibleOnly,
        bool $broad,
        string $matchMode
    ): Builder {
        $query = Product::query();

        if ($queryText !== '') {
            $tokens = $this->tokenize($queryText);
            $query->where(function (Builder $builder) use ($tokens, $queryText, $broad): void {
                foreach (['name', 'name_normalized', 'brand', 'brand_normalized', 'description', 'category', 'main_category', 'main_category1', 'ingredients'] as $column) {
                    if (!$this->hasColumn($column)) {
                        continue;
                    }

                    if (!$broad) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . strtolower($queryText) . '%']);
                    }

                    foreach ($tokens as $token) {
                        if (strlen($token) >= 2) {
                            $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $token . '%']);
                        }
                    }
                }
            });
        }

        if ($brand !== '') {
            $query->where(function (Builder $builder) use ($brand): void {
                foreach (['brand', 'brand_normalized', 'name', 'name_normalized'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . strtolower($brand) . '%']);
                    }
                }
            });
        }

        if ($category !== '') {
            $query->where(function (Builder $builder) use ($category): void {
                foreach (['category', 'categories', 'main_category', 'main_category1', 'description', 'name'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . strtolower($category) . '%']);
                    }
                }
            });
        }

        if ($origin !== '' && $this->hasColumn('origin')) {
            $query->whereRaw('LOWER(origin) like ?', ['%' . strtolower($origin) . '%']);
        }

        if (!empty($ingredientsInclude) && $this->hasColumn('ingredients')) {
            $query->where(function (Builder $builder) use ($ingredientsInclude, $matchMode): void {
                foreach ($ingredientsInclude as $index => $term) {
                    $method = $matchMode === 'any' || $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $builder->{$method}('LOWER(ingredients) like ?', ['%' . strtolower($term) . '%']);
                }
            });
        }

        if (!empty($ingredientsExclude) && $this->hasColumn('ingredients')) {
            foreach ($ingredientsExclude as $term) {
                $query->whereRaw('LOWER(COALESCE(ingredients, "")) not like ?', ['%' . strtolower($term) . '%']);
            }
        }

        if ($edibleOnly) {
            $this->applyEdibleOnlyFilter($query);
        }

        return $query;
    }

    protected function applyEdibleOnlyFilter(Builder $query): void
    {
        $blocked = ['shampoo', 'soap', 'detergent', 'cleaner', 'toothpaste', 'cream', 'lotion'];

        $query->where(function (Builder $builder) use ($blocked): void {
            foreach (['name', 'category', 'description'] as $column) {
                if (!$this->hasColumn($column)) {
                    continue;
                }

                foreach ($blocked as $term) {
                    $builder->whereRaw('LOWER(COALESCE(' . $column . ', "")) not like ?', ['%' . $term . '%']);
                }
            }
        });
    }

    protected function rankByPhrase(Collection $products, string $seed, ?array $imageContext = null, bool $strictNameMode = false): Collection
    {
        $seedLower = strtolower(trim($seed));
        $seedCompact = $this->compact($seed);
        $seedTokens = $this->tokenize($seed);

        return $products
            ->map(function ($product) use ($seedLower, $seedCompact, $seedTokens, $imageContext, $strictNameMode) {
                $formatted = $product instanceof Product ? $this->formatProduct($product) : (array) $product;

                $name = strtolower(trim((string) ($formatted['name'] ?? '')));
                $brand = strtolower(trim((string) ($formatted['brand'] ?? '')));
                $category = strtolower(trim((string) ($formatted['category'] ?? '')));
                $ingredients = strtolower(trim((string) ($formatted['ingredients'] ?? '')));
                $full = trim($brand . ' ' . $name . ' ' . $category . ' ' . $ingredients);
                $fullCompact = $this->compact($full);

                $score = 0;

                if ($seedLower !== '') {
                    if ($name === $seedLower || $brand === $seedLower || trim($brand . ' ' . $name) === $seedLower) {
                        $score += 200;
                    }
                    if ($name !== '' && str_contains($name, $seedLower)) {
                        $score += 120;
                    }
                    if ($brand !== '' && str_contains($brand, $seedLower)) {
                        $score += 90;
                    }
                    if ($full !== '' && str_contains($full, $seedLower)) {
                        $score += 70;
                    }
                    if ($seedCompact !== '' && str_contains($fullCompact, $seedCompact)) {
                        $score += 120;
                    }
                }

                foreach ($seedTokens as $token) {
                    if (strlen($token) < 2) {
                        continue;
                    }

                    if (str_contains($name, $token)) {
                        $score += 30;
                    }
                    if (str_contains($brand, $token)) {
                        $score += 20;
                    }
                    if (str_contains($category, $token)) {
                        $score += 10;
                    }
                }

                if ($strictNameMode && $name !== '' && $brand !== '') {
                    similar_text($seedLower, trim($brand . ' ' . $name), $percent);
                    $score += (int) round($percent);
                }

                $formatted['__image_product_hits'] = 0;
                $formatted['__image_brand_hits'] = 0;

                if ($imageContext) {
                    $imageProduct = strtolower(trim((string) ($imageContext['product_name'] ?? '')));
                    $imageBrand = strtolower(trim((string) ($imageContext['brand'] ?? '')));

                    if ($imageProduct !== '' && str_contains($name, $imageProduct)) {
                        $score += 90;
                        $formatted['__image_product_hits']++;
                    }

                    if ($imageBrand !== '' && str_contains($brand, $imageBrand)) {
                        $score += 70;
                        $formatted['__image_brand_hits']++;
                    }
                }

                $formatted['__score'] = $score;

                return $formatted;
            })
            ->sortByDesc('__score')
            ->values();
    }

    protected function filterProductsByImageContext(Collection $products, ?array $imageContext, bool $strict = false): Collection
    {
        if (!$imageContext) {
            return $products;
        }

        $imageProduct = strtolower(trim((string) ($imageContext['product_name'] ?? '')));
        $imageBrand = strtolower(trim((string) ($imageContext['brand'] ?? '')));

        if ($imageProduct === '' && $imageBrand === '') {
            return $products;
        }

        return $products
            ->filter(function (array $product) use ($imageProduct, $imageBrand, $strict) {
                $name = strtolower(trim((string) ($product['name'] ?? '')));
                $brand = strtolower(trim((string) ($product['brand'] ?? '')));

                $productHit = $imageProduct !== '' && str_contains($name, $imageProduct);
                $brandHit = $imageBrand !== '' && str_contains($brand, $imageBrand);

                return $strict ? ($productHit || $brandHit) : true;
            })
            ->values();
    }

    protected function filterProductsByCategoryIntent(Collection $products, string $category): Collection
    {
        $needle = strtolower(trim($category));

        return $products
            ->filter(function (array $product) use ($needle) {
                $haystack = strtolower(trim(implode(' ', array_filter([
                    (string) ($product['category'] ?? ''),
                    (string) ($product['main_category'] ?? ''),
                    (string) ($product['main_category1'] ?? ''),
                    (string) ($product['name'] ?? ''),
                    (string) ($product['description'] ?? ''),
                ]))));

                return $needle === '' || str_contains($haystack, $needle);
            })
            ->values();
    }

    protected function buildRankingSeed(string $queryText, string $brand, string $category, string $origin, ?array $imageContext): string
    {
        $parts = array_filter([
            $brand,
            $queryText,
            $category,
            $origin,
            $imageContext['brand'] ?? null,
            $imageContext['product_name'] ?? null,
            $imageContext['category'] ?? null,
        ]);

        return trim(implode(' ', $parts));
    }

    protected function wantsEdibleProducts(string $queryText): bool
    {
        return !preg_match('/\b(shampoo|soap|cleaner|lotion|toothpaste|cosmetic|cream)\b/i', $queryText);
    }

    protected function isStrictCategoryQuery(string $rawQueryText, string $category): bool
    {
        if ($category === '') {
            return false;
        }

        return (bool) preg_match('/\b(category|type|kind|only|just)\b/i', $rawQueryText);
    }

    protected function isRecommendationQuery(string $rawQueryText, array $arguments = []): bool
    {
        $focus = strtolower(trim((string) ($arguments['question_focus'] ?? '')));

        if ($focus === 'recommendation') {
            return true;
        }

        return (bool) preg_match('/\b(recommend|suggest|options|party|guests|gathering|good options|practical|widely acceptable)\b/i', $rawQueryText);
    }

    protected function extractStatusFilter(array $arguments): ?string
    {
        if (!empty($arguments['status'])) {
            $status = strtolower(trim((string) $arguments['status']));
            return in_array($status, ['halal', 'haram', 'mushbooh'], true) ? $status : null;
        }

        if (!empty($arguments['halal_only'])) {
            return 'halal';
        }

        return null;
    }

    protected function formatProduct(Product|array $product): array
    {
        $item = $product instanceof Product ? $product->toArray() : $product;

        $exactStatus = $this->resolveExactDecision($item['status'] ?? null);
        $exactType = $this->resolveExactDecision($item['type'] ?? null);
        $exactDecision = $this->resolveExactDecision($item['decision'] ?? null);

        $resolved = $exactStatus
            ?? $exactType
            ?? $exactDecision
            ?? $this->resolveExactDecision($item['verdict'] ?? null)
            ?? 'unknown';

        return [
            'id' => $item['id'] ?? null,
            'name' => (string) ($item['name'] ?? ''),
            'brand' => (string) ($item['brand'] ?? ''),
            'barcode' => (string) ($item['barcode'] ?? ''),
            'origin' => (string) ($item['origin'] ?? ''),
            'category' => (string) ($item['category'] ?? ''),
            'main_category' => (string) ($item['main_category'] ?? ''),
            'main_category1' => (string) ($item['main_category1'] ?? ''),
            'ingredients' => (string) ($item['ingredients'] ?? ''),
            'image' => (string) ($item['image'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'decision' => $resolved,
            'status' => $resolved,
            'type' => $resolved,
            'notes' => (string) ($item['notes'] ?? ''),
            'allergens' => (string) ($item['allergens'] ?? ''),
        ];
    }

    protected function resolveExactDecision(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'halal' => 'halal',
            'haram' => 'haram',
            'mashbooh', 'mushbooh' => 'mushbooh',
            'unknown' => 'unknown',
            '' => null,
            default => null,
        };
    }

    protected function notFound(string $message, array $meta = []): array
    {
        return [
            'status' => 'not_found',
            'message' => $message,
            'products' => [],
            'meta' => $meta,
        ];
    }

    protected function hasColumn(string $column): bool
    {
        static $cache = [];

        if (!array_key_exists($column, $cache)) {
            $cache[$column] = Schema::hasColumn($this->table, $column);
        }

        return $cache[$column];
    }

    protected function normalizeText(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9\s\-\&]/', ' ')->replaceMatches('/\s+/', ' ')->trim()->toString();
    }

    protected function normalizeSearchText(string $value): string
    {
        $value = $this->normalizeText($value);

        $patterns = [
            '/\bplease\b/',
            '/\bcan you\b/',
            '/\bi want\b/',
            '/\bi would like\b/',
            '/\bi would prefer\b/',
            '/\bfrom your database\b/',
            '/\bthat would be suitable for\b/',
            '/\bfor someone looking for\b/',
            '/\bnot obviously problematic\b/',
            '/\bin terms of ingredients\b/',
            '/\bpractical\b/',
            '/\bclear\b/',
            '/\bhuman readable\b/',
            '/\brather than just a raw list\b/',
            '/\brecommend\b/',
            '/\bsuggest\b/',
            '/\bproducts?\b/',
            '/\boptions?\b/',
            '/\bgood\b/',
            '/\ba few\b/',
        ];

        $value = preg_replace($patterns, ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    protected function normalizeBrand(string $value): string
    {
        return $this->normalizeText($value);
    }

    protected function normalizeCategory(string $value): string
    {
        $value = $this->normalizeText($value);

        $map = [
            'snack' => 'snacks',
            'chip' => 'chips',
            'biscuit' => 'biscuits',
            'cookie' => 'cookies',
            'drink' => 'drinks',
            'beverage' => 'drinks',
        ];

        return $map[$value] ?? $value;
    }

    protected function normalizeOrigin(string $value): string
    {
        $value = $this->normalizeText($value);

        $map = [
            'us' => 'usa',
            'united states' => 'usa',
            'u s a' => 'usa',
            'britain' => 'uk',
            'united kingdom' => 'uk',
            'u a e' => 'uae',
            'united arab emirates' => 'uae',
        ];

        return $map[$value] ?? $value;
    }

    protected function normalizeKeyword(string $value): string
    {
        $value = $this->normalizeText($value);
        return match ($value) {
            'fibre' => 'fiber',
            default => $value,
        };
    }

    protected function normalizeStringArray(array $items): array
    {
        return array_values(array_filter(array_map(function ($item) {
            return is_scalar($item) ? trim((string) $item) : '';
        }, $items), fn ($item) => $item !== ''));
    }

    protected function normalizeBarcode(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    protected function tokenize(string $value): array
    {
        $value = $this->normalizeText($value);
        $parts = preg_split('/\s+/', $value) ?: [];

        $stopWords = ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'would', 'should', 'please', 'give', 'show', 'find'];

        return array_values(array_filter(array_unique($parts), function ($token) use ($stopWords) {
            return strlen($token) >= 2 && !in_array($token, $stopWords, true);
        }));
    }

    protected function compact(string $value): string
    {
        return preg_replace('/\s+/', '', $this->normalizeText($value)) ?? '';
    }
}
