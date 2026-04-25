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
            'find_product_by_name'    => $this->findProductByName((string) ($arguments['name'] ?? ''), $arguments),
            'search_products'         => $this->searchProducts($arguments),
            'explain_ingredient'      => $this->explainIngredient((string) ($arguments['ingredient'] ?? '')),
            'answer_without_db'      => [
                'status' => 'not_found',
                'message' => (string) ($arguments['query'] ?? 'Please ask about a product, ingredient, category, origin, or barcode.'),
                'products' => [],
                'meta' => ['tool' => 'answer_without_db'],
            ],
            default => [
                'status'   => 'error',
                'message'  => 'Unknown tool requested.',
                'products' => [],
                'meta'     => ['tool' => $toolName],
            ],
        };
    }

    // ─────────────────────────────────────────────────────────────
    // FIND BY BARCODE
    // ─────────────────────────────────────────────────────────────

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
            ->limit(5)
            ->get();

        if ($products->isEmpty()) {
            return $this->notFound('I could not find this barcode in the database.', [
                'tool'    => 'find_product_by_barcode',
                'barcode' => $barcode,
            ]);
        }

        return [
            'status'   => 'found',
            'message'  => 'Product found by barcode.',
            'products' => $products->map(fn (Product $product) => $this->formatProduct($product))->values()->all(),
            'meta'     => [
                'tool'         => 'find_product_by_barcode',
                'barcode'      => $barcode,
                'result_count' => $products->count(),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // FIND BY NAME
    // ─────────────────────────────────────────────────────────────

    public function findProductByName(string $name, array $arguments = []): array
    {
        $name = $this->normalizeText($name);
        $imageContext = is_array($arguments['image_context'] ?? null) ? $arguments['image_context'] : null;

        if ($name === '') {
            return $this->notFound('No product name was provided.', ['tool' => 'find_product_by_name']);
        }

        $tokens  = $this->tokenize($name);
        $lower   = Str::lower($name);
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
                $imageBrand   = strtolower(trim((string) ($imageContext['brand'] ?? '')));

                if ($imageProduct !== '' && $this->hasColumn('name')) {
                    $builder->orWhereRaw('LOWER(name) like ?', ['%' . $imageProduct . '%']);
                }

                if ($imageBrand !== '' && $this->hasColumn('brand')) {
                    $builder->orWhereRaw('LOWER(brand) like ?', ['%' . $imageBrand . '%']);
                }
            }

            foreach ($tokens as $token) {
                foreach (['name_normalized', 'name', 'brand', 'description', 'category'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $token . '%']);
                    }
                }
            }
        });

        $products = $this->rankByPhrase($query->limit(30)->get(), $name, $imageContext, true);

        if ($imageContext) {
            $products = $this->filterProductsByImageContext($products, $imageContext, true, true);
        }

        $topScore  = (int) (($products->first()['__score'] ?? 0));
        $threshold = $imageContext ? 150 : 120;

        if ($products->isEmpty() || $topScore < $threshold) {
            return $this->notFound('I could not find this product in the database.', [
                'tool'                  => 'find_product_by_name',
                'name'                  => $name,
                'top_score'             => $topScore,
                'image_strict_matching' => $imageContext ? true : false,
            ]);
        }

        $filtered = $products
            ->filter(fn ($product) => (int) ($product['__score'] ?? 0) >= max($threshold, $topScore - ($imageContext ? 80 : 120)))
            ->take(8)
            ->map(function ($product) {
                unset($product['__score'], $product['__image_product_hits'], $product['__image_brand_hits']);
                return $product;
            })
            ->values()
            ->all();

        return [
            'status'   => 'found',
            'message'  => 'Product search complete.',
            'products' => $filtered,
            'meta'     => [
                'tool'                  => 'find_product_by_name',
                'name'                  => $name,
                'result_count'          => count($filtered),
                'top_score'             => $topScore,
                'image_strict_matching' => $imageContext ? true : false,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // SEARCH PRODUCTS
    // ─────────────────────────────────────────────────────────────

    public function searchProducts(array $arguments = []): array
    {
        $rawQueryText = $this->normalizeText((string) ($arguments['query'] ?? ''));
        $queryText    = $this->normalizeSearchText($rawQueryText);

        $brand  = $this->normalizeBrand((string) ($arguments['brand'] ?? ''));
        $category = $this->normalizeCategory((string) ($arguments['category'] ?? ''));
        $origin = is_array($arguments['origin'] ?? null) ? '' : $this->normalizeOrigin((string) ($arguments['origin'] ?? ''));
        $origins = $this->normalizeOriginArray($arguments['origins'] ?? ($origin !== '' ? [$origin] : []));
        $productNames = $this->normalizeStringArray($arguments['product_names'] ?? []);
        $imageContext = is_array($arguments['image_context'] ?? null) ? $arguments['image_context'] : null;

        // Generic safety recovery: Gemini/intent may miss filters or misclassify ingredient words
        // (example: "lemon juice") as a drinks category. Recover filters from the raw user text.
        $category = $this->preferReliableCategoryFromQuery($rawQueryText, $category);

        // Recover origin/country words from natural language when the resolver misses them,
        // e.g. "austrailian chocolates", "UK chocolates", "Pakistani spices".
        $recoveredOrigins = $this->extractOriginFiltersFromQuery($rawQueryText);
        if (! empty($recoveredOrigins)) {
            $origins = array_values(array_unique(array_merge($origins, $recoveredOrigins)));
            if ($origin === '') {
                $origin = $origins[0] ?? '';
            }
        }

        $ingredientsInclude = array_map(
            fn ($item) => $this->normalizeKeyword($item),
            array_values(array_unique(array_merge(
                $this->normalizeStringArray($arguments['ingredients_include'] ?? []),
                $this->extractIngredientFiltersFromQuery($rawQueryText, false)
            )))
        );

        $ingredientsExclude = array_map(
            fn ($item) => $this->normalizeKeyword($item),
            array_values(array_unique(array_merge(
                $this->normalizeStringArray($arguments['ingredients_exclude'] ?? []),
                $this->extractIngredientFiltersFromQuery($rawQueryText, true)
            )))
        );

        // FIX: Support both old single 'status' field AND new 'status_include'/'status_exclude' arrays
        $statusInclude = $this->normalizeStatusArray($arguments['status_include'] ?? []);
        $statusExclude = $this->normalizeStatusArray($arguments['status_exclude'] ?? []);

        // Back-compat: if old 'status' key is provided, merge into status_include
        $legacyStatus = $this->extractLegacyStatusFilter($arguments);
        if ($legacyStatus !== null && ! in_array($legacyStatus, $statusInclude, true)) {
            $statusInclude[] = $legacyStatus;
        }

        $limit     = max(1, min((int) ($arguments['limit'] ?? 12), 30));
        $matchMode = strtolower((string) ($arguments['match_mode'] ?? 'all'));

        if (! empty($productNames) || ! empty($ingredientsInclude) || ! empty($ingredientsExclude) || ! empty($origins) || $brand !== '' || $category !== '') {
            $queryText = $this->cleanNoisySearchText($queryText, ! empty($ingredientsInclude) || ! empty($ingredientsExclude));
        }

        $edibleOnly          = $this->wantsEdibleProducts($rawQueryText);
        $strictCategoryQuery = $this->isStrictCategoryQuery($rawQueryText, $category);
        $hasHardFilters      = $category !== ''
            || $brand !== ''
            || $origin !== ''
            || ! empty($origins)
            || ! empty($productNames)
            || ! empty($ingredientsInclude)
            || ! empty($ingredientsExclude)
            || ! empty($statusInclude)
            || ! empty($statusExclude);

        $attempts   = [];
        $baseQuery  = fn () => $this->buildSearchQuery(
            queryText: $queryText,
            brand: $brand,
            category: $category,
            origin: $origin,
            origins: $origins,
            productNames: $productNames,
            ingredientsInclude: $ingredientsInclude,
            ingredientsExclude: $ingredientsExclude,
            edibleOnly: $edibleOnly,
            broad: false,
            matchMode: $matchMode
        );

        $attempts[] = $baseQuery()->limit(150)->get();

        $attempts[] = $this->buildSearchQuery(
            queryText: '',
            brand: $brand,
            category: $category,
            origin: $origin,
            origins: $origins,
            productNames: $productNames,
            ingredientsInclude: $ingredientsInclude,
            ingredientsExclude: $ingredientsExclude,
            edibleOnly: $edibleOnly,
            broad: true,
            matchMode: $matchMode
        )->limit(150)->get();

        if ($queryText !== '' && ! $strictCategoryQuery && ! $hasHardFilters) {
            $attempts[] = $this->buildSearchQuery(
                queryText: $queryText,
                brand: '',
                category: '',
                origin: '',
                origins: [],
                productNames: $productNames,
                ingredientsInclude: [],
                ingredientsExclude: [],
                edibleOnly: $edibleOnly,
                broad: true,
                matchMode: $matchMode
            )->limit(150)->get();
        }

        if ($brand !== '' && ! $strictCategoryQuery && ! $hasHardFilters) {
            $attempts[] = $this->buildSearchQuery(
                queryText: '',
                brand: $brand,
                category: '',
                origin: '',
                origins: [],
                productNames: $productNames,
                ingredientsInclude: [],
                ingredientsExclude: [],
                edibleOnly: $edibleOnly,
                broad: true,
                matchMode: $matchMode
            )->limit(150)->get();
        }

        $products = collect();
        foreach ($attempts as $attempt) {
            if ($attempt instanceof Collection && $attempt->isNotEmpty()) {
                $products = $attempt;
                break;
            }
        }

        if ($products->isEmpty()) {
            return $this->notFound('No products matched your filters.', [
                'tool'                 => 'search_products',
                'query'                => $queryText,
                'raw_query'            => $rawQueryText,
                'brand'                => $brand,
                'category'             => $category,
                'origin'               => $origin,
                'origins'              => $origins,
                'status_include'       => $statusInclude,
                'status_exclude'       => $statusExclude,
                'edible_only'          => $edibleOnly,
                'strict_category_query'=> $strictCategoryQuery,
                'has_hard_filters'     => $hasHardFilters,
            ]);
        }

        $rankingSeed = $this->buildRankingSeed($queryText, $brand, $category, $origin, $imageContext);
        $products    = $this->rankByPhrase($products, $rankingSeed, $imageContext, false);

        if ($imageContext) {
            $products = $this->filterProductsByImageContext($products, $imageContext, false, true);
        }

        if ($strictCategoryQuery && $category !== '') {
            $products = $this->filterProductsByCategoryIntent($products, $category);
        }

        if ($products->isEmpty()) {
            return $this->notFound(
                $strictCategoryQuery
                    ? 'I could not find products matching that category in your database.'
                    : 'I could not confidently match the detected product to your database.',
                [
                    'tool'                 => 'search_products',
                    'query'                => $queryText,
                    'raw_query'            => $rawQueryText,
                    'brand'                => $brand,
                    'category'             => $category,
                    'origin'               => $origin,
                    'image_context'        => $imageContext,
                    'strict_category_query'=> $strictCategoryQuery,
                    'image_strict_matching'=> $imageContext ? true : false,
                ]
            );
        }

        $allProducts = $products->map(function ($product) {
            $formatted = $this->formatProduct($product);
            if (isset($product->__score)) {
                $formatted['__score'] = $product->__score;
            }
            return $formatted;
        })->values();

        // Final guard: enforce every explicit user filter with AND logic after ranking.
        // This prevents fallback leakage such as chocolates returning chips, drinks returning snacks,
        // or ingredient queries returning products that do not contain the requested ingredient.
        $allProducts = $this->applyStrictResultFilters(
            $allProducts,
            $category,
            $origins,
            $origin,
            $ingredientsInclude,
            $ingredientsExclude,
            $statusInclude,
            $statusExclude
        );

        if ($allProducts->isEmpty()) {
            return $this->notFound('No products matched your filters.', [
                'tool'                 => 'search_products',
                'query'                => $queryText,
                'raw_query'            => $rawQueryText,
                'brand'                => $brand,
                'category'             => $category,
                'origin'               => $origin,
                'origins'              => $origins,
                'ingredients_include'  => $ingredientsInclude,
                'ingredients_exclude'  => $ingredientsExclude,
                'status_include'       => $statusInclude,
                'status_exclude'       => $statusExclude,
                'strict_filtered_empty'=> true,
            ]);
        }

        if (! empty($productNames)) {
            $allProducts = $this->selectBestProductNameMatches($allProducts, $productNames, $limit);
        }

        // FIX: Apply status_include filtering (multi-status array, not just one status string)
        // FIX: Apply status_exclude filtering
        $preferredProducts = $allProducts;
        $hasStatusFilter   = ! empty($statusInclude);

        if ($hasStatusFilter) {
            $preferredProducts = $allProducts->filter(function (array $product) use ($statusInclude, $statusExclude) {
                $decision = $this->productStatusValue($product);
                $inInclude = in_array($decision, $statusInclude, true);
                $inExclude = ! empty($statusExclude) && in_array($decision, $statusExclude, true);
                return $inInclude && ! $inExclude;
            })->values();
        } elseif (! empty($statusExclude)) {
            $preferredProducts = $allProducts->filter(function (array $product) use ($statusExclude) {
                $decision = $this->productStatusValue($product);
                return ! in_array($decision, $statusExclude, true);
            })->values();
        }

        $fallbackProducts = $allProducts;

        if ($preferredProducts->isEmpty() && $fallbackProducts->isEmpty()) {
            return $this->notFound('No products matched your filters.', [
                'tool'          => 'search_products',
                'query'         => $queryText,
                'raw_query'     => $rawQueryText,
                'brand'         => $brand,
                'category'      => $category,
                'status_include'=> $statusInclude,
                'status_exclude'=> $statusExclude,
                'origin'        => $origin,
                'origins'       => $origins,
                'edible_only'   => $edibleOnly,
            ]);
        }

        $finalProducts = $preferredProducts->take($limit)->values();
        $fallbackReason = null;

        if ($finalProducts->isEmpty()) {
            return $this->notFound('No products matched your filters.', [
                'tool'           => 'search_products',
                'query'          => $queryText,
                'raw_query'      => $rawQueryText,
                'category'       => $category,
                'origin'         => $origin,
                'origins'        => $origins,
                'status_include' => $statusInclude,
                'status_exclude' => $statusExclude,
            ]);
        }

        $finalProducts = $finalProducts->map(function ($product) {
            unset($product['__score']);
            return $product;
        })->values();

        return [
            'status'   => 'found',
            'message'  => $fallbackReason ?: 'Product search complete.',
            'products' => $finalProducts->all(),
            'meta'     => [
                'tool'                  => 'search_products',
                'query'                 => $queryText,
                'raw_query'             => $rawQueryText,
                'brand'                 => $brand,
                'category'              => $category,
                'origin'                => $origin,
                'origins'               => $origins,
                'status_include'        => $statusInclude,
                'status_exclude'        => $statusExclude,
                'result_count'          => $finalProducts->count(),
                'preferred_match_count' => $preferredProducts->count(),
                'fallback_match_count'  => $fallbackProducts->count(),
                'used_status_fallback'  => $fallbackReason !== null,
                'edible_only'           => $edibleOnly,
                'strict_category_query' => $strictCategoryQuery,
                'image_strict_matching' => $imageContext ? true : false,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // QUERY BUILDER
    // ─────────────────────────────────────────────────────────────

    protected function buildSearchQuery(
        string $queryText,
        string $brand,
        string $category,
        string $origin,
        array $origins,
        array $productNames,
        array $ingredientsInclude,
        array $ingredientsExclude,
        bool $edibleOnly,
        bool $broad,
        string $matchMode
    ): Builder {
        $query = Product::query();

        if ($brand !== '') {
            $this->applyBrandFilter($query, $brand);
        }

        if ($category !== '') {
            $this->applyCategoryFilter($query, $category);
        }

        if (! empty($origins)) {
            $this->applyOriginListFilter($query, $origins);
        } elseif ($origin !== '') {
            $this->applyOriginFilter($query, $origin);
        }

        if (! empty($productNames)) {
            $this->applyProductNamesFilter($query, $productNames);
        }

        if (! empty($ingredientsInclude)) {
            $this->applyIngredientFilter($query, $ingredientsInclude, $matchMode === 'any');
        }

        if (! empty($ingredientsExclude) && $this->hasColumn('ingredients')) {
            foreach ($ingredientsExclude as $ingredient) {
                $aliases = $this->expandIngredientAliases($ingredient);
                $query->where(function (Builder $excludeBuilder) use ($aliases): void {
                    foreach ($aliases as $alias) {
                        $excludeBuilder->whereRaw('LOWER(ingredients) not like ?', ['%' . Str::lower($alias) . '%']);
                    }
                });
            }
        }

        if ($edibleOnly) {
            $this->applyEdibleFilter($query);
        }

        if ($queryText !== '') {
            $tokens       = $this->tokenize($queryText);
            $strongTokens = array_values(array_filter($tokens, fn ($token) => strlen($token) >= 4));

            $query->where(function (Builder $builder) use ($queryText, $tokens, $strongTokens, $broad): void {
                $columns = ['name_normalized', 'name', 'brand_normalized', 'brand', 'description', 'ingredients', 'category', 'main_category', 'main_category1', 'categories', 'notes', 'origin'];

                if (! $broad) {
                    foreach ($columns as $column) {
                        if ($this->hasColumn($column)) {
                            $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . Str::lower($queryText) . '%']);
                        }
                    }
                }

                foreach (($broad ? $strongTokens : $tokens) as $token) {
                    foreach ($columns as $column) {
                        if ($this->hasColumn($column)) {
                            $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $token . '%']);
                        }
                    }
                }
            });
        }

        return $query;
    }

    // ─────────────────────────────────────────────────────────────
    // EXPLAIN INGREDIENT
    // ─────────────────────────────────────────────────────────────

    public function explainIngredient(string $ingredient): array
    {
        $ingredient = $this->normalizeText($ingredient);

        if ($ingredient === '') {
            return [
                'status'                 => 'not_found',
                'message'                => 'No ingredient was provided.',
                'ingredient_explanation' => null,
                'products'               => [],
                'meta'                   => ['tool' => 'explain_ingredient'],
            ];
        }

        $map = [
            'gelatin' => [
                'ingredient'    => 'Gelatin',
                'summary'       => 'Gelatin can come from halal animal, non-halal animal, fish, or porcine sources. It is source dependent.',
                'decision_hint' => 'source_dependent',
            ],
            'e471' => [
                'ingredient'    => 'E471',
                'summary'       => 'E471 may come from plant or animal fats. It is source dependent unless the source is verified.',
                'decision_hint' => 'source_dependent',
            ],
            'natural flavorings' => [
                'ingredient'    => 'Natural flavorings',
                'summary'       => 'Natural flavorings are not automatically halal or haram. Their carrier and source matter.',
                'decision_hint' => 'source_dependent',
            ],
            'alcohol' => [
                'ingredient'    => 'Alcohol',
                'summary'       => 'Alcohol-related ingredients are sensitive and should follow your halal policy and source context.',
                'decision_hint' => 'policy_sensitive',
            ],
            'carmine' => [
                'ingredient'    => 'Carmine / E120',
                'summary'       => 'Carmine is a red dye derived from insects (cochineal). It is considered haram by many Islamic scholars.',
                'decision_hint' => 'haram',
            ],
            'lecithin' => [
                'ingredient'    => 'Lecithin',
                'summary'       => 'Lecithin can be from soy (halal) or animal sources (needs verification). Check the source.',
                'decision_hint' => 'source_dependent',
            ],
        ];

        $key = Str::lower($ingredient);

        return [
            'status'                 => 'found',
            'message'                => 'Ingredient explanation prepared.',
            'ingredient_explanation' => $map[$key] ?? [
                'ingredient'    => $ingredient,
                'summary'       => 'This ingredient needs source verification before a halal ruling can be trusted.',
                'decision_hint' => 'needs_review',
            ],
            'products' => [],
            'meta'     => ['tool' => 'explain_ingredient'],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // FILTER HELPERS
    // ─────────────────────────────────────────────────────────────

    protected function applyProductNamesFilter(Builder $query, array $productNames): void
    {
        $productNames = array_values(array_filter(array_map(fn ($name) => $this->normalizeText((string) $name), $productNames)));
        if (empty($productNames)) {
            return;
        }

        // Keep SQL broad enough to fetch candidates, but do not OR every loose token globally.
        // Final exact-ish matching is done in selectBestProductNameMatches().
        $query->where(function (Builder $builder) use ($productNames): void {
            foreach ($productNames as $name) {
                $lower = Str::lower($name);
                foreach (['name_normalized', 'name', 'brand_normalized', 'brand', 'description'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $lower . '%']);
                    }
                }

                $tokens = $this->meaningfulTokens($name);
                if (! empty($tokens)) {
                    $builder->orWhere(function (Builder $tokenBuilder) use ($tokens): void {
                        foreach ($tokens as $token) {
                            $tokenBuilder->where(function (Builder $inner) use ($token): void {
                                foreach (['name_normalized', 'name', 'brand_normalized', 'brand'] as $column) {
                                    if ($this->hasColumn($column)) {
                                        $inner->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $token . '%']);
                                    }
                                }
                            });
                        }
                    });
                }
            }
        });
    }

    protected function applyOriginListFilter(Builder $query, array $origins): void
    {
        if (! $this->hasColumn('origin')) {
            return;
        }

        $aliases = [];
        foreach ($origins as $origin) {
            $aliases = array_merge($aliases, $this->expandOriginAliases((string) $origin));
        }
        $aliases = array_values(array_unique(array_filter($aliases)));

        if (empty($aliases)) {
            return;
        }

        $query->where(function (Builder $builder) use ($aliases): void {
            foreach ($aliases as $alias) {
                $builder->orWhereRaw('LOWER(origin) like ?', ['%' . Str::lower($alias) . '%']);
            }
        });
    }

    protected function applyBrandFilter(Builder $query, string $brand): void
    {
        $aliases = $this->expandBrandAliases($brand);

        $query->where(function (Builder $builder) use ($aliases): void {
            foreach ($aliases as $alias) {
                foreach (['brand', 'name', 'description'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $alias . '%']);
                    }
                }
            }
        });
    }

    protected function applyOriginFilter(Builder $query, string $origin): void
    {
        if (! $this->hasColumn('origin')) {
            return;
        }

        $aliases = $this->expandOriginAliases($origin);

        $query->where(function (Builder $builder) use ($aliases): void {
            foreach ($aliases as $alias) {
                $builder->orWhereRaw('LOWER(origin) like ?', ['%' . $alias . '%']);
            }
        });
    }

    protected function applyCategoryFilter(Builder $query, string $category): void
    {
        $aliases = $this->expandCategoryAliases($category);

        $query->where(function (Builder $builder) use ($aliases): void {
            foreach ($aliases as $alias) {
                foreach (['category', 'main_category', 'main_category1', 'categories'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $alias . '%']);
                    }
                }
            }
        });
    }

    protected function applyIngredientFilter(Builder $query, array $ingredients, bool $any = false): void
    {
        if (! $this->hasColumn('ingredients')) {
            return;
        }

        $query->where(function (Builder $builder) use ($ingredients, $any): void {
            foreach ($ingredients as $index => $ingredient) {
                $aliases = $this->expandIngredientAliases($ingredient);
                $method = $index === 0 ? 'where' : ($any ? 'orWhere' : 'where');

                $builder->{$method}(function (Builder $aliasBuilder) use ($aliases): void {
                    foreach ($aliases as $alias) {
                        $aliasBuilder->orWhereRaw('LOWER(ingredients) like ?', ['%' . Str::lower($alias) . '%']);
                    }
                });
            }
        });
    }

    protected function applyEdibleFilter(Builder $query): void
    {
        $positive = ['chocolate', 'chocolates', 'snack', 'snacks', 'drink', 'drinks', 'beverage', 'juice', 'biscuit', 'biscuits', 'cookie', 'cookies', 'cake', 'candy', 'bar', 'nougat', 'food', 'eat', 'edible', 'confectionery', 'dairy', 'sauce', 'mayonnaise', 'ketchup', 'chips', 'crisps', 'noodles', 'bread'];
        $negative = ['soap', 'shampoo', 'detergent', 'cleaner', 'household', 'toothpaste', 'cosmetic', 'lotion', 'cream', 'sanitizer', 'bleach'];

        $query->where(function (Builder $builder) use ($positive): void {
            foreach ($positive as $term) {
                foreach (['name', 'description', 'category', 'main_category', 'main_category1', 'categories', 'notes'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $term . '%']);
                    }
                }
            }
        });

        $query->where(function (Builder $builder) use ($negative): void {
            foreach ($negative as $term) {
                foreach (['name', 'description', 'category', 'main_category', 'main_category1', 'categories', 'notes'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->whereRaw('LOWER(' . $column . ') not like ?', ['%' . $term . '%']);
                    }
                }
            }
        });
    }

    protected function wantsEdibleProducts(string $queryText): bool
    {
        return (bool) preg_match('/\beat\b|\bdrink\b|\bto eat\b|\bto drink\b|\bhungry\b|\bsnack\b|\bfood\b/i', strtolower($queryText));
    }

    // ─────────────────────────────────────────────────────────────
    // RANKING
    // ─────────────────────────────────────────────────────────────

    protected function buildRankingSeed(string $queryText, string $brand, string $category, string $origin, ?array $imageContext = null): string
    {
        $parts = [$queryText, $brand, $category, $origin];

        if ($imageContext) {
            $parts[] = trim((string) ($imageContext['brand'] ?? ''));
            $parts[] = trim((string) ($imageContext['product_name'] ?? ''));
            $parts[] = trim((string) ($imageContext['visible_text'] ?? ''));
        }

        return trim(implode(' ', array_filter($parts)));
    }

    protected function rankByPhrase(Collection $products, string $phrase, ?array $imageContext = null, bool $asArray = true): Collection
    {
        $phrase        = Str::lower(trim($phrase));
        $compactPhrase = $this->compact($phrase);
        $tokens        = $this->tokenize($phrase);

        $imageProduct      = $imageContext ? strtolower(trim((string) ($imageContext['product_name'] ?? ''))) : '';
        $imageBrand        = $imageContext ? strtolower(trim((string) ($imageContext['brand'] ?? ''))) : '';
        $imageVisibleText  = $imageContext ? strtolower(trim((string) ($imageContext['visible_text'] ?? ''))) : '';

        $imageProductTokens = $this->meaningfulTokens($imageProduct);
        $imageBrandTokens   = $this->meaningfulTokens($imageBrand);
        $imageVisibleTokens = $this->meaningfulTokens($imageVisibleText);

        return $products->map(function ($product) use (
            $phrase,
            $compactPhrase,
            $tokens,
            $imageProduct,
            $imageBrand,
            $imageVisibleText,
            $imageProductTokens,
            $imageBrandTokens,
            $imageVisibleTokens,
            $asArray
        ) {
            $score = 0;

            $name        = Str::lower((string) ($product->name ?? ''));
            $brand       = Str::lower((string) ($product->brand ?? ''));
            $category    = Str::lower((string) ($product->category ?? ''));
            $description = Str::lower((string) ($product->description ?? ''));
            $notes       = Str::lower((string) ($product->notes ?? ''));

            $haystack        = trim(implode(' ', array_filter([$name, $brand, $category, $description, $notes])));
            $compactHaystack = $this->compact($haystack);
            $brandNameCompact = $this->compact(trim($brand . ' ' . $name));

            if ($phrase !== '') {
                if ($name === $phrase) $score += 1200;
                if ($brand === $phrase) $score += 700;
                if ($brandNameCompact === $compactPhrase) $score += 1400;
                if ($compactHaystack === $compactPhrase) $score += 1000;
                if (str_contains($name, $phrase)) $score += 500;
                if (str_contains($brand, $phrase)) $score += 250;
                if (str_contains($haystack, $phrase)) $score += 200;
            }

            foreach ($tokens as $token) {
                if (str_contains($name, $token)) $score += 80;
                if (str_contains($brand, $token)) $score += 45;
                if (str_contains($category, $token)) $score += 25;
                if (str_contains($description, $token)) $score += 20;
            }

            if ($imageProduct !== '' && str_contains($name, $imageProduct)) $score += 1000;
            if ($imageBrand !== '' && str_contains($brand, $imageBrand)) $score += 1200;
            if ($imageVisibleText !== '' && str_contains($haystack, $imageVisibleText)) $score += 600;

            $productTokenHits = $this->countTokenHits($name . ' ' . $description, $imageProductTokens);
            $brandTokenHits   = $this->countTokenHits($brand . ' ' . $name, $imageBrandTokens);
            $visibleTokenHits = $this->countTokenHits($haystack, $imageVisibleTokens);

            $score += ($productTokenHits * 220);
            $score += ($brandTokenHits * 260);
            $score += ($visibleTokenHits * 70);

            if ($brandTokenHits > 0 && $productTokenHits > 0) {
                $score += 900;
            }

            if ($asArray) {
                $formatted           = $this->formatProduct($product);
                $formatted['__score'] = $score;
                $formatted['__image_product_hits'] = $productTokenHits;
                $formatted['__image_brand_hits']   = $brandTokenHits;
                return $formatted;
            }

            $product->__score              = $score;
            $product->__image_product_hits = $productTokenHits;
            $product->__image_brand_hits   = $brandTokenHits;
            return $product;
        })->sortByDesc(fn ($item) => $asArray ? ($item['__score'] ?? 0) : ($item->__score ?? 0))->values();
    }

    protected function filterProductsByImageContext(Collection $products, array $imageContext, bool $arrayFormat, bool $strict = true): Collection
    {
        $brand        = strtolower(trim((string) ($imageContext['brand'] ?? '')));
        $productName  = strtolower(trim((string) ($imageContext['product_name'] ?? '')));

        $brandTokens   = $this->meaningfulTokens($brand);
        $productTokens = $this->meaningfulTokens($productName);

        if (empty($brandTokens) && empty($productTokens)) {
            return $products;
        }

        return $products->filter(function ($product) use ($brandTokens, $productTokens, $arrayFormat) {
            if ($arrayFormat) {
                $brandText   = strtolower(trim((string) (($product['brand'] ?? '') . ' ' . ($product['name'] ?? ''))));
                $productText = strtolower(trim((string) (($product['name'] ?? '') . ' ' . ($product['description'] ?? ''))));
            } else {
                $brandText   = strtolower(trim((string) (($product->brand ?? '') . ' ' . ($product->name ?? ''))));
                $productText = strtolower(trim((string) (($product->name ?? '') . ' ' . ($product->description ?? ''))));
            }

            $brandHits   = $this->countTokenHits($brandText, $brandTokens);
            $productHits = $this->countTokenHits($productText, $productTokens);

            if (! empty($brandTokens)) {
                return $brandHits >= 1 || $productHits >= 2;
            }

            return $productHits >= 2;
        })->values();
    }

    protected function expandIngredientAliases(string $ingredient): array
    {
        $ingredient = $this->normalizeKeyword($ingredient);

        $aliases = match ($ingredient) {
            'sugar' => ['sugar', 'suger', 'sugars', 'sugers', 'sucrose'],
            'palm oil' => ['palm oil', 'palmolein', 'palm olein'],
            'vegetable oil' => ['vegetable oil', 'vegetable oils', 'vegitable oil', 'vegitable oils'],
            'wheat' => ['wheat', 'wheat flour'],
            'flour' => ['flour', 'wheat flour'],
            'wheat flour' => ['wheat flour', 'wheat'],
            'milk' => ['milk', 'dairy', 'whey', 'casein', 'milk powder'],
            'chocolate' => ['chocolate', 'cocoa'],
            'vitamin' => ['vitamin', 'vitamins'],
            'fiber' => ['fiber', 'fibers', 'fibre'],
            'carbohydrate' => ['carbohydrate', 'carbohydrates', 'carbs'],
            'lemon juice' => ['lemon juice'],
            'lime juice' => ['lime juice'],
            default => [$ingredient],
        };

        return array_values(array_unique(array_filter($aliases)));
    }


    /**
     * Enforce explicit filters using strict AND logic after all DB/ranking attempts.
     * This is the safety net that stops unrelated fallback results.
     */
    protected function applyStrictResultFilters(
        Collection $products,
        string $category,
        array $origins,
        string $origin,
        array $ingredientsInclude,
        array $ingredientsExclude,
        array $statusInclude,
        array $statusExclude
    ): Collection {
        return $products->filter(function (array $product) use ($category, $origins, $origin, $ingredientsInclude, $ingredientsExclude, $statusInclude, $statusExclude) {
            if ($category !== '' && ! $this->productMatchesCategory($product, $category)) {
                return false;
            }

            $originFilters = ! empty($origins) ? $origins : ($origin !== '' ? [$origin] : []);
            if (! empty($originFilters) && ! $this->productMatchesAnyOrigin($product, $originFilters)) {
                return false;
            }

            if (! empty($statusInclude)) {
                $status = $this->productStatusValue($product);
                if (! in_array($status, $statusInclude, true)) {
                    return false;
                }
            }

            if (! empty($statusExclude)) {
                $status = $this->productStatusValue($product);
                if (in_array($status, $statusExclude, true)) {
                    return false;
                }
            }

            if (! empty($ingredientsInclude) && ! $this->productMatchesIncludedIngredients($product, $ingredientsInclude)) {
                return false;
            }

            if (! empty($ingredientsExclude) && $this->productMatchesExcludedIngredients($product, $ingredientsExclude)) {
                return false;
            }

            return true;
        })->values();
    }

    protected function productMatchesCategory(array $product, string $category): bool
    {
        $aliases = $this->expandCategoryAliases($category);
        $haystack = strtolower(trim(implode(' ', array_filter([
            (string) ($product['category'] ?? ''),
            (string) ($product['main_category'] ?? ''),
            (string) ($product['main_category1'] ?? ''),
            (string) ($product['categories'] ?? ''),
        ]))));

        foreach ($aliases as $alias) {
            $alias = strtolower(trim($alias));
            if ($alias !== '' && str_contains($haystack, $alias)) {
                return true;
            }
        }

        return false;
    }

    protected function productMatchesAnyOrigin(array $product, array $origins): bool
    {
        $originText = strtolower((string) ($product['origin'] ?? ''));
        if ($originText === '') {
            return false;
        }

        foreach ($origins as $origin) {
            foreach ($this->expandOriginAliases((string) $origin) as $alias) {
                $alias = strtolower(trim($alias));
                if ($alias !== '' && str_contains($originText, $alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function productMatchesIncludedIngredients(array $product, array $ingredients): bool
    {
        $ingredientsText = strtolower((string) ($product['ingredients'] ?? ''));
        if ($ingredientsText === '') {
            return false;
        }

        $ingredients = array_values(array_unique(array_filter(array_map(fn ($item) => $this->normalizeKeyword((string) $item), $ingredients))));
        if (empty($ingredients)) {
            return true;
        }

        $hits = 0;
        foreach ($ingredients as $ingredient) {
            if ($this->ingredientTextContains($ingredientsText, $ingredient)) {
                $hits++;
            }
        }

        // For 1-2 requested ingredients, require all.
        // For 3+ requested ingredients, return products that contain at least two requested ingredients,
        // as requested for broader ingredient-list searches.
        $requiredHits = count($ingredients) <= 2 ? count($ingredients) : 2;

        return $hits >= $requiredHits;
    }

    protected function productMatchesExcludedIngredients(array $product, array $ingredients): bool
    {
        $ingredientsText = strtolower((string) ($product['ingredients'] ?? ''));
        if ($ingredientsText === '') {
            return false;
        }

        foreach ($ingredients as $ingredient) {
            if ($this->ingredientTextContains($ingredientsText, $ingredient)) {
                return true;
            }
        }

        return false;
    }

    protected function ingredientTextContains(string $ingredientsText, string $ingredient): bool
    {
        foreach ($this->expandIngredientAliases($ingredient) as $alias) {
            $alias = strtolower(trim($alias));
            if ($alias !== '' && str_contains($ingredientsText, $alias)) {
                return true;
            }
        }

        return false;
    }

    protected function productStatusValue(array $product): string
    {
        foreach (['status', 'decision', 'type'] as $key) {
            $status = $this->normalizeStatusValue((string) ($product[$key] ?? ''));
            if ($status !== '') {
                return $status;
            }
        }

        return '';
    }

    protected function normalizeStatusValue(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);

        return match ($value) {
            'approved', 'approve', 'halal certified', 'halal' => 'halal',
            'haram', 'not halal', 'non halal' => 'haram',
            'mushbooh', 'mashbooh', 'doubtful', 'suspect' => 'mushbooh',
            'unknown', 'pending', 'decision pending', 'not found', 'unverified' => 'unknown',
            default => '',
        };
    }

    protected function selectBestProductNameMatches(Collection $products, array $productNames, int $limit): Collection
    {
        $selected = collect();
        $seen = [];

        foreach ($productNames as $target) {
            $target = $this->normalizeText((string) $target);
            if ($target === '') {
                continue;
            }

            $scored = $products->map(function (array $product) use ($target) {
                $product['__name_score'] = $this->scoreProductNameArray($product, $target);
                return $product;
            })->filter(fn (array $product) => (int) ($product['__name_score'] ?? 0) >= 60)
              ->sortByDesc('__name_score')
              ->values();

            foreach ($scored->take(3) as $product) {
                $key = (string) ($product['id'] ?? ($product['barcode'] ?? ($product['name'] ?? '')));
                if ($key !== '' && isset($seen[$key])) {
                    continue;
                }
                unset($product['__name_score']);
                $selected->push($product);
                if ($key !== '') {
                    $seen[$key] = true;
                }
                break;
            }
        }

        if ($selected->isNotEmpty()) {
            return $selected->take($limit)->values();
        }

        return $products->take($limit)->values();
    }

    protected function scoreProductNameArray(array $product, string $target): int
    {
        $targetLower = Str::lower($this->normalizeText($target));
        $targetCompact = $this->compact($targetLower);
        $name = Str::lower((string) ($product['name'] ?? ''));
        $brand = Str::lower((string) ($product['brand'] ?? ''));
        $haystack = trim($brand . ' ' . $name . ' ' . Str::lower((string) ($product['description'] ?? '')));
        $compactHaystack = $this->compact($haystack);
        $score = 0;

        if ($name === $targetLower || trim($brand . ' ' . $name) === $targetLower) {
            $score += 220;
        }

        if ($targetLower !== '' && str_contains($haystack, $targetLower)) {
            $score += 140;
        }

        if ($targetCompact !== '' && str_contains($compactHaystack, $targetCompact)) {
            $score += 120;
        }

        foreach ($this->meaningfulTokens($targetLower) as $token) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($token, '/') . '(?![a-z0-9])/i', $haystack) === 1) {
                $score += 35;
            }
        }

        return $score;
    }

    protected function filterProductsByCategoryIntent(Collection $products, string $category): Collection
    {
        $aliases = $this->expandCategoryAliases($category);

        return $products->filter(function ($product) use ($aliases) {
            $haystack = strtolower(trim(implode(' ', array_filter([
                (string) ($product->category ?? ''),
                (string) ($product->main_category ?? ''),
                (string) ($product->main_category1 ?? ''),
                (string) ($product->categories ?? ''),
            ]))));

            foreach ($aliases as $alias) {
                if ($alias !== '' && str_contains($haystack, strtolower($alias))) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    protected function isStrictCategoryQuery(string $rawQueryText, string $category): bool
    {
        if ($category === '') {
            return false;
        }

        return (bool) preg_match('/\b(suggest|show|list|give|find)\b/i', strtolower($rawQueryText))
            || (bool) preg_match('/\b(biscuits?|cookies?|chocolates?|drinks?|snacks?|cakes?|beverages?)\b/i', strtolower($rawQueryText));
    }

    // ─────────────────────────────────────────────────────────────
    // FORMAT / UTILITY
    // ─────────────────────────────────────────────────────────────

    protected function countTokenHits(string $haystack, array $tokens): int
    {
        $hits = 0;

        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($haystack, $token)) {
                $hits++;
            }
        }

        return $hits;
    }

    protected function meaningfulTokens(string $text): array
    {
        $tokens = $this->tokenize($text);

        return array_values(array_filter($tokens, function ($token) {
            return mb_strlen($token) >= 3 && ! in_array($token, ['plain', 'soft', 'bakes', 'product', 'item'], true);
        }));
    }

    protected function formatProduct(Product $product): array
    {
        return [
            'id'            => $product->id,
            'name'          => $product->name,
            'barcode'       => $product->barcode,
            'brand'         => $product->brand,
            'origin'        => $product->origin,
            'category'      => $product->category,
            'main_category' => $product->main_category,
            'main_category1'=> $product->main_category1,
            'description'   => $product->description,
            'ingredients'   => $product->ingredients,
            'allergens'     => $product->allergens,
            'notes'         => $product->notes,
            'image'         => $product->image,
            'decision'      => $product->decision,
            'status'        => $product->status,
            'type'          => $product->type,
        ];
    }

    protected function notFound(string $message, array $meta = []): array
    {
        return [
            'status'   => 'not_found',
            'message'  => $message,
            'products' => [],
            'meta'     => $meta,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // NORMALIZATION
    // ─────────────────────────────────────────────────────────────

    protected function compact(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', '', $value) ?? $value;
        return trim($value);
    }

    protected function normalizeBarcode(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = strtr($value, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'S' => '5', 'B' => '8', 'G' => '6', 'Z' => '2']);
        $value = preg_replace('/\D+/', '', $value) ?? '';
        return trim($value);
    }

    protected function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    protected function normalizeStringArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\n]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($item) => $this->normalizeText((string) $item), $value)));
    }

    protected function normalizeOriginArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*(?:,|\band\b|\bor\b|&)\s*/iu', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $item = $this->normalizeOrigin((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    protected function cleanNoisySearchText(string $queryText, bool $ingredientFilter = false): string
    {
        $queryText = trim($queryText);
        if ($queryText === '') {
            return '';
        }

        $noise = ['show', 'products', 'product', 'that', 'which', 'contain', 'contains', 'with', 'without', 'but', 'no', 'give', 'me', 'list', 'items', 'item', 'from', 'and', 'or', 'are', 'these', 'healthy', 'health', 'compare'];
        $tokens = array_values(array_filter($this->tokenize($queryText), fn ($token) => ! in_array($token, $noise, true)));

        if ($ingredientFilter) {
            return '';
        }

        return trim(implode(' ', $tokens));
    }

    /**
     * Generic filter recovery from raw query text.
     * This prevents missed ingredients (e.g. "lemon juice") from falling back to category lists.
     */
    protected function extractIngredientFiltersFromQuery(string $query, bool $exclude): array
    {
        $query = strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $pattern = $exclude
            ? '/(?:without|excluding|exclude|avoid|free from|no)\s+(.+?)(?=\s+(?:from|made in|inside|in it|in them|for me|please|that are|which are|category|products?$)|[.?!;]|$)/iu'
            : '/(?:contain|contains|containing|with|having|include|includes|must have|rich in|high in)\s+(.+?)(?=\s+(?:from|made in|inside|in it|in them|for me|please|that are|which are|category|products?$)|[.?!;]|$)/iu';

        $found = [];
        if (preg_match_all($pattern, $query, $matches)) {
            foreach ($matches[1] as $group) {
                foreach ($this->splitIngredientTerms($group) as $term) {
                    if ($term !== '') {
                        $found[] = $term;
                    }
                }
            }
        }

        $known = [
            'lemon juice', 'lime juice', 'vegetable oil', 'vegitable oil', 'palm oil', 'wheat flour',
            'cocoa butter', 'whey powder', 'milk solids', 'carbonated water', 'caramel color',
            'phosphoric acid', 'aspartame', 'carbohydrate', 'carbohydrates', 'protein', 'fiber',
            'sugar', 'salt', 'water', 'milk', 'whey', 'soy', 'cocoa', 'glucose', 'fructose',
            'preservative', 'preservatives', 'additive', 'additives', 'gelatin', 'alcohol', 'e471'
        ];

        foreach ($known as $term) {
            if (! str_contains($query, $term)) {
                continue;
            }

            $isExcluded = preg_match('/\b(?:without|excluding|exclude|avoid|free from|no)\b[^.?!;]*\b' . preg_quote($term, '/') . '\b/iu', $query) === 1;
            if (($exclude && $isExcluded) || (! $exclude && ! $isExcluded)) {
                $found[] = $term;
            }
        }

        return array_values(array_unique(array_filter(array_map(fn ($item) => $this->normalizeKeyword($item), $found))));
    }

    protected function splitIngredientTerms(string $value): array
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[“”"\'`]+/u', '', $value) ?? $value;
        $value = preg_replace('/\b(in it|in them|inside|products?|items?|things?|please|for me)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\b(from|made in|origin|country|category)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B,.;:!?&");

        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|&|\band\b|\bor\b)\s*/iu', $value) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            $part = trim($part, " \t\n\r\0\x0B,.;:!?&");
            if ($part !== '' && mb_strlen($part) >= 3) {
                $terms[] = $part;
            }
        }

        return $terms;
    }

    protected function preferReliableCategoryFromQuery(string $rawQuery, string $category): string
    {
        $raw = strtolower(trim($rawQuery));
        $category = strtolower(trim($category));

        if (in_array($category, ['juice', 'drinks', 'drink'], true)
            && preg_match('/\b(?:contain|contains|containing|with|include|includes|having|has|have)\b[^.?!,;]*\bjuice\b/iu', $raw)
            && ! preg_match('/\b(drinks?|beverages?|something\s+to\s+drink|to\s+drink|drinkable|soda|soft\s+drink|energy\s+drink)\b/iu', $raw)) {
            return '';
        }

        if ($category !== '') {
            return $this->normalizeCategory($category);
        }

        if (preg_match('/\b(drinks?|beverages?|something\s+to\s+drink|to\s+drink|drinkable|soda|soft\s+drink|energy\s+drink)\b/iu', $raw)) {
            return 'drinks';
        }
        if (preg_match('/\b(chocolates?|confectionery)\b/iu', $raw)) {
            return 'chocolates';
        }
        if (preg_match('/\b(snacks?|chips|crisps)\b/iu', $raw)) {
            return 'snacks';
        }
        if (preg_match('/\b(biscuits?|cookies?)\b/iu', $raw)) {
            return 'biscuits';
        }
        if (preg_match('/\b(dairy|cheese|yogurts?|yoghurts?)\b/iu', $raw)) {
            return 'dairy';
        }
        if (preg_match('/\b(spices?|masala|seasoning|seasonings|mixes?)\b/iu', $raw)) {
            return 'spices';
        }

        return '';
    }


    /**
     * Generic origin/country recovery from raw query text.
     * Keeps category+origin searches strict even when the intent resolver misses
     * adjective forms such as "Australian chocolates", "UK chocolates",
     * or typo variants like "austrailian".
     */
    protected function extractOriginFiltersFromQuery(string $query): array
    {
        $query = strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $countryMap = [
            'australia' => ['australia', 'australian', 'austrailian', 'austrelian', 'austrelia', 'asutrailia', 'austrailia'],
            'pakistan' => ['pakistan', 'pakistani', 'pk'],
            'uk' => ['uk', 'u.k.', 'united kingdom', 'britain', 'british', 'england', 'english'],
            'usa' => ['usa', 'u.s.', 'us', 'united states', 'america', 'american'],
            'italy' => ['italy', 'italian', 'itley', 'itely', 'italia'],
            'germany' => ['germany', 'german'],
            'canada' => ['canada', 'canadian'],
            'france' => ['france', 'french'],
            'switzerland' => ['switzerland', 'swiss'],
            'belgium' => ['belgium', 'belgian'],
            'malaysia' => ['malaysia', 'malaysian'],
            'india' => ['india', 'indian'],
            'uae' => ['uae', 'u.a.e.', 'emirates', 'dubai', 'abu dhabi'],
            'saudi arabia' => ['saudi arabia', 'saudi', 'ksa'],
            'spain' => ['spain', 'spanish'],
            'netherlands' => ['netherlands', 'dutch', 'holland'],
            'indonesia' => ['indonesia', 'indonesian'],
            'thailand' => ['thailand', 'thai'],
            'china' => ['china', 'chinese'],
            'japan' => ['japan', 'japanese'],
        ];

        $found = [];
        foreach ($countryMap as $normalized => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($alias, '/') . '(?![a-z0-9])/iu', $query) === 1) {
                    $found[] = $normalized;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }

    protected function normalizeBrand(string $value): string
    {
        return str_replace('-', ' ', Str::lower($this->normalizeText($value)));
    }

    protected function normalizeOrigin(string $value): string
    {
        $value = Str::lower($this->normalizeText($value));

        return match ($value) {
            'america', 'american', 'united states', 'us', 'u.s.'     => 'usa',
            'britain', 'british', 'england', 'great britain'          => 'uk',
            'emirates', 'dubai', 'abu dhabi'                          => 'uae',
            'saudi', 'ksa'                                            => 'saudi arabia',
            'swiss'                                                    => 'switzerland',
            'belgian'                                                  => 'belgium',
            'italian', 'itley', 'itely', 'italia'                         => 'italy',
            'turkish'                                                  => 'turkey',
            'french'                                                   => 'france',
            'german'                                                   => 'germany',
            'canadian'                                                 => 'canada',
            'spanish'                                                  => 'spain',
            'dutch', 'holland'                                         => 'netherlands',
            'malaysian'                                                => 'malaysia',
            'australian', 'austrailian', 'austrelian', 'austrelia', 'asutrailia', 'austrailia' => 'australia',
            'indonesian'                                               => 'indonesia',
            'thai'                                                     => 'thailand',
            'indian'                                                   => 'india',
            'chinese'                                                  => 'china',
            'japanese'                                                 => 'japan',
            'pakistani'                                                => 'pakistan',
            default                                                    => $value,
        };
    }

    protected function normalizeCategory(string $value): string
    {
        $value = Str::lower($this->normalizeText($value));

        $map = [
            'drink'         => 'drinks',
            'drinks'        => 'drinks',
            'beverage'      => 'drinks',
            'beverages'     => 'drinks',
            'soda'          => 'drinks',
            'soft drink'    => 'drinks',
            'energy drink'  => 'drinks',
            'chocolate'     => 'chocolates',
            'chocolates'    => 'chocolates',
            'cocoa'         => 'chocolates',
            'biscuit'       => 'biscuits',
            'cookie'        => 'biscuits',
            'cookies'       => 'biscuits',
            'snack'         => 'snacks',
            'chips'         => 'snacks',
            'crisps'        => 'snacks',
            'mayonnaise'    => 'sauces',
            'ketchup'       => 'sauces',
            'sauce'         => 'sauces',
            'condiment'     => 'sauces',
            'condiments'    => 'sauces',
            'cake'          => 'cakes',
            'bakery'        => 'bakery',
            'bread'         => 'bakery',
            'pastry'        => 'bakery',
            'dairy'         => 'dairy',
            'milk'          => 'dairy',
            'cheese'        => 'dairy',
            'yogurt'        => 'dairy',
            'spice'         => 'spices',
            'spices'        => 'spices',
            'masala'        => 'spices',
            'seasoning'     => 'spices',
            'seasonings'    => 'spices',
        ];

        return $map[$value] ?? $value;
    }

    protected function normalizeKeyword(string $word): string
    {
        $word = strtolower(trim($word));

        $map = [
            'antioxidants'  => 'antioxidant',
            'antioxidant'   => 'antioxidant',
            'preservatives' => 'preservative',
            'preservative'  => 'preservative',
            'drinks'        => 'drink',
            'drink'         => 'drink',
            'biscuits'      => 'biscuit',
            'biscuit'       => 'biscuit',
            'cookies'       => 'cookie',
            'cookie'        => 'cookie',
            'snacks'        => 'snack',
            'snack'         => 'snack',
            'flavours'      => 'flavour',
            'flavors'       => 'flavor',
            'sooper'        => 'sooper',
            'super'         => 'super',
            'vitamins'      => 'vitamin',
            'vitamin'       => 'vitamin',
            'proteins'      => 'protein',
            'protein'       => 'protein',
            'carbs'         => 'carbohydrate',
            'carbohydrates' => 'carbohydrate',
            'vegitable oil' => 'vegetable oil',
            'vegitable oils'=> 'vegetable oil',
        ];

        return $map[$word] ?? $word;
    }

    /**
     * FIX: Normalize an array of status values.
     */
    protected function normalizeStatusArray(mixed $value): array
    {
        $items = $this->normalizeStringArray($value);
        $normalized = [];
        foreach ($items as $item) {
            $status = $this->normalizeStatusValue((string) $item);
            if ($status !== '') {
                $normalized[] = $status;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Back-compat: extract old single 'status' field.
     */
    protected function extractLegacyStatusFilter(array $arguments): ?string
    {
        $status = Str::lower((string) ($arguments['status'] ?? ''));

        return in_array($status, ['halal', 'haram', 'mushbooh', 'mashbooh'], true)
            ? str_replace('mashbooh', 'mushbooh', $status)
            : null;
    }

    protected function normalizeSearchText(string $text): string
    {
        $tokens = $this->tokenize($text);

        return trim(implode(' ', $tokens));
    }

    // ─────────────────────────────────────────────────────────────
    // ALIAS EXPANSION
    // ─────────────────────────────────────────────────────────────

    protected function expandBrandAliases(string $brand): array
    {
        $brand = $this->normalizeBrand($brand);

        $aliases = [
            'pepsi'              => ['pepsi', 'pepsi cola', 'pepsi max'],
            'coca cola'          => ['coca cola', 'coca-cola', 'coke'],
            'coke'               => ['coke', 'coca cola', 'coca-cola'],
            'nestle'             => ['nestle'],
            'kinder'             => ['kinder'],
            'peek freans'        => ['peek freans'],
            'peek freans sooper' => ['peek freans', 'sooper', 'peek freans sooper'],
        ];

        return array_values(array_unique($aliases[$brand] ?? [$brand]));
    }

    /**
     * FIX: Expanded to cover all major countries including Italy, Turkey, France, Switzerland, Belgium, Saudi Arabia, Pakistan.
     */
    protected function expandOriginAliases(string $origin): array
    {
        $origin = $this->normalizeOrigin($origin);

        return match ($origin) {
            'usa'          => ['usa', 'us', 'u.s.', 'united states', 'united states of america', 'america', 'american'],
            'uk'           => ['uk', 'u.k.', 'united kingdom', 'britain', 'great britain', 'england', 'british'],
            'uae'          => ['uae', 'u.a.e.', 'united arab emirates', 'emirates', 'dubai'],
            'australia'    => ['australia', 'australian', 'austrailian', 'austrelian', 'austrelia', 'asutrailia', 'austrailia'],
            'pakistan'     => ['pakistan', 'pakistani', 'pk'],
            'italy'        => ['italy', 'italian'],
            'turkey'       => ['turkey', 'turkish'],
            'france'       => ['france', 'french'],
            'switzerland'  => ['switzerland', 'swiss'],
            'belgium'      => ['belgium', 'belgian'],
            'saudi arabia' => ['saudi arabia', 'saudi', 'ksa'],
            'malaysia'     => ['malaysia', 'malaysian'],
            'india'        => ['india', 'indian'],
            'germany'      => ['germany', 'german'],
            'canada'       => ['canada', 'canadian'],
            'spain'        => ['spain', 'spanish'],
            'netherlands'  => ['netherlands', 'dutch', 'holland'],
            'indonesia'    => ['indonesia', 'indonesian'],
            'thailand'     => ['thailand', 'thai'],
            'china'        => ['china', 'chinese'],
            'japan'        => ['japan', 'japanese'],
            default        => [$origin],
        };
    }

    protected function expandCategoryAliases(string $category): array
    {
        $category = $this->normalizeCategory($category);

        return match ($category) {
            'drinks'     => ['drink', 'drinks', 'beverage', 'beverages', 'juice', 'soft drink', 'soda'],
            'snacks'     => ['snack', 'snacks', 'chips', 'crisps'],
            'biscuits'   => ['biscuit', 'biscuits', 'cookie', 'cookies'],
            'chocolates' => ['chocolate', 'chocolates', 'cocoa', 'confectionery', 'candy', 'candies', 'nougat', 'ferrero', 'rocher', 'kinder', 'dairy milk'],
            'cakes'      => ['cake', 'cakes', 'bake', 'bakes'],
            'sauces'     => ['sauce', 'sauces', 'mayonnaise', 'ketchup', 'condiment'],
            'bakery'     => ['bakery', 'bread', 'pastry', 'baked'],
            'dairy'      => ['dairy', 'milk', 'cheese', 'yogurt', 'yoghurt', 'cream'],
            'spices'     => ['spice', 'spices', 'masala', 'seasoning', 'seasonings', 'mix'],
            default      => [$category],
        };
    }

    // ─────────────────────────────────────────────────────────────
    // TOKENIZATION
    // ─────────────────────────────────────────────────────────────

    protected function tokenize(string $text): array
    {
        $text  = Str::lower($text);
        $text  = preg_replace('/[^\pL\pN\s]+/u', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/', trim($text)) ?: [];

        $stop = [
            'is', 'are', 'the', 'a', 'an', 'me', 'please', 'show', 'find', 'give', 'tell',
            'about', 'with', 'without', 'for', 'of', 'this', 'that', 'these', 'those',
            'does', 'do', 'has', 'have', 'can', 'i', 'you', 'my', 'product', 'products',
            'halal', 'haram', 'mushbooh', 'mashbooh', 'suggest', 'recommend', 'to', 'eat',
            'drink', 'some', 'any', 'want', 'need', 'have', 'has',
        ];

        return array_values(array_filter(array_map(function ($part) use ($stop) {
            if ($part === '' || in_array($part, $stop, true)) {
                return null;
            }

            return $this->normalizeKeyword($part);
        }, $parts)));
    }

    protected function hasColumn(string $column): bool
    {
        static $columns = null;

        if ($columns === null) {
            try {
                $columns = Schema::getColumnListing($this->table);
            } catch (\Throwable) {
                $columns = [];
            }
        }

        return in_array($column, $columns, true);
    }
}
