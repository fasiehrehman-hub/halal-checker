<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ProductLookupService
 *
 * Manager/Developer overview: Database lookup layer: performs barcode/name searches, catalog filtering, ingredient/status/origin/category filters, strict result checks, and ranking.
 * Comments were added for documentation only; business logic is unchanged from v3.
 */
class ProductLookupService
{
    protected string $table = 'products';

    /**
     * Central dispatcher that maps resolved tool names to the correct lookup method.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    public function executeTool(string $toolName, array $arguments = []): array
    {
        // All resolved tools enter the database layer through this dispatcher.
        return match ($toolName) {
            'find_product_by_barcode' => $this->findProductByBarcode((string) ($arguments['barcode'] ?? '')),
            'find_product_by_name'    => $this->findProductByName((string) ($arguments['name'] ?? ''), $arguments),
            'search_products'         => $this->searchProducts($arguments),
            'find_similar_by_category'=> $this->findSimilarByCategory($arguments),
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

    /**
     * Finds products by barcode using exact/partial barcode matching.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    public function findProductByBarcode(string $barcode): array
    {
        $barcodeInput = trim($barcode);
        $barcode = $this->normalizeBarcode($barcodeInput);
        $candidates = $this->barcodeLookupCandidates($barcodeInput);

        if ($barcode === '' && empty($candidates)) {
            return $this->notFound('No barcode was provided.', ['tool' => 'find_product_by_barcode']);
        }

        $products = Product::query()
            ->when($this->hasColumn('barcode'), function (Builder $query) use ($candidates): void {
                $query->where(function (Builder $barcodeBuilder) use ($candidates): void {
                    foreach ($candidates as $candidate) {
                        $lower = strtolower($candidate);
                        $barcodeBuilder
                            ->orWhereRaw('LOWER(barcode) = ?', [$lower])
                            ->orWhereRaw('LOWER(barcode) like ?', ['%' . $lower . '%']);
                    }
                });
            })
            ->limit(8)
            ->get();

        if ($products->isEmpty()) {
            return $this->notFound('I could not find this barcode in the database.', [
                'tool'    => 'find_product_by_barcode',
                'barcode' => $barcode !== '' ? $barcode : $barcodeInput,
                'barcode_candidates' => $candidates,
            ]);
        }

        $formattedProducts = $this->deduplicateProductArray($products->map(fn (Product $product) => $this->formatProduct($product))->values()->all());

        return [
            'status'   => 'found',
            'message'  => 'Product found by barcode.',
            'products' => $formattedProducts,
            'meta'     => [
                'tool'         => 'find_product_by_barcode',
                'barcode'      => $barcode !== '' ? $barcode : $barcodeInput,
                'barcode_candidates' => $candidates,
                'result_count' => count($formattedProducts),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // FIND BY NAME
    // ─────────────────────────────────────────────────────────────

    /**
     * Finds a specific product by exact/fuzzy name, brand, aliases, and optional image context.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    public function findProductByName(string $name, array $arguments = []): array
    {
        $name = $this->normalizeProductNameInput($this->normalizeText($name));
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

        $filtered = $this->deduplicateProductArray($filtered);

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

    /**
     * Main product search. Applies filters, ranking, strict post-filtering, and returns matching products.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    public function searchProducts(array $arguments = []): array
    {
        $rawQueryText = $this->normalizeText((string) ($arguments['query'] ?? ''));
        $ingredientQueryText = $this->normalizeText((string) ($arguments['ingredient_query'] ?? $rawQueryText));
        $queryText    = $this->normalizeSearchText($rawQueryText);

        $brand  = $this->normalizeBrand((string) ($arguments['brand'] ?? ''));
        $hasExplicitBrandArgument = $brand !== '';
        $hasArgumentIngredientFilters = ! empty($arguments['ingredients_include'] ?? []) || ! empty($arguments['ingredients_exclude'] ?? []);
        $looksLikeNutritionQuery = $this->looksLikeNutritionQueryText($rawQueryText);

        // Do not recover a brand from generic helper wording in ingredient/nutrition prompts.
        // Example: "Can you show me items rich in vitamins" previously captured "you" as brand.
        if ($brand === '' && ! $looksLikeNutritionQuery && ! $hasArgumentIngredientFilters) {
            $brand = $this->extractBrandHintFromQuery($rawQueryText) ?? '';
        }

        if ($this->isBlockedBrandToken($brand)) {
            $brand = '';
        }

        if ($brand !== '' && ($this->isBrandCatalogQuery($rawQueryText) || preg_match('/\bbrand\s+(?:products?|items?)\b/iu', $rawQueryText) === 1)) {
            $queryText = '';
        }
        $category = $this->normalizeCategory((string) ($arguments['category'] ?? ''));
        $origin = is_array($arguments['origin'] ?? null) ? '' : $this->normalizeOrigin((string) ($arguments['origin'] ?? ''));
        $origins = $this->normalizeOriginArray($arguments['origins'] ?? ($origin !== '' ? [$origin] : []));
        $productNames = $this->normalizeStringArray($arguments['product_names'] ?? []);
        $dietInclude = $this->normalizeDietArray($arguments['diet_include'] ?? []);
        $dietExclude = $this->normalizeDietArray($arguments['diet_exclude'] ?? []);
        $focusProductName = $this->normalizeText((string) ($arguments['focus_product_name'] ?? ''));
        $imageContext = is_array($arguments['image_context'] ?? null) ? $arguments['image_context'] : null;

        // A focused product request is an add-on to a list query:
        // "show chocolates and specially tell me about Dairy Milk".
        // It must NOT narrow the category list through product_names.
        if ($focusProductName !== '' && ($category !== '' || $brand !== '' || $origin !== '' || ! empty($origins))) {
            $productNames = [];
        }

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

        // Generic origin safety: phrases like "products from Montenegro" or
        // "products from Democratic-republic-of-the-congo" were sometimes parsed
        // as a brand because "products from X" can also mean brand X. If X is also
        // an explicit origin phrase, origin wins and brand is cleared. This is DB/future
        // origin friendly and avoids per-country code changes.
        if (! $hasExplicitBrandArgument && $brand !== '' && ! empty($origins) && $this->brandLooksLikeOriginFilter($brand, $origins)) {
            $brand = '';
        }

        $ingredientFilterSourceText = $rawQueryText !== '' ? $rawQueryText : $ingredientQueryText;
        $ingredientSourceText = $focusProductName !== ''
            ? $this->removePhraseFromText($ingredientFilterSourceText, $focusProductName)
            : $ingredientFilterSourceText;

        $ingredientsInclude = array_map(
            fn ($item) => $this->normalizeKeyword($item),
            array_values(array_unique(array_merge(
                $this->normalizeStringArray($arguments['ingredients_include'] ?? []),
                $this->extractIngredientFiltersFromQuery($ingredientSourceText, false)
            )))
        );

        if ($focusProductName !== '') {
            $ingredientsInclude = $this->removeIngredientsThatArePartOfFocusName($ingredientsInclude, $focusProductName);
        }

        $ingredientsExclude = array_map(
            fn ($item) => $this->normalizeKeyword($item),
            array_values(array_unique(array_merge(
                $this->normalizeStringArray($arguments['ingredients_exclude'] ?? []),
                $this->extractIngredientFiltersFromQuery($ingredientSourceText, true)
            )))
        );

        $includeHadBroadAnimalGroup = in_array('animal derived', $ingredientsInclude, true);
        $ingredientsInclude = $this->expandBroadIngredientConcepts($ingredientsInclude);
        $ingredientsExclude = $this->expandBroadIngredientConcepts($ingredientsExclude);
        $ingredientsInclude = array_values(array_diff($ingredientsInclude, $ingredientsExclude));

        if (! $hasExplicitBrandArgument && ($looksLikeNutritionQuery || ! empty($ingredientsInclude) || ! empty($ingredientsExclude)) && $this->isBlockedBrandToken($brand)) {
            $brand = '';
        }

        // FIX: Support both old single 'status' field AND new 'status_include'/'status_exclude' arrays
        $statusInclude = $this->normalizeStatusArray($arguments['status_include'] ?? []);
        $statusExclude = $this->normalizeStatusArray($arguments['status_exclude'] ?? []);

        // Back-compat: if old 'status' key is provided, merge into status_include
        $legacyStatus = $this->extractLegacyStatusFilter($arguments);
        if ($legacyStatus !== null && ! in_array($legacyStatus, $statusInclude, true)) {
            $statusInclude[] = $legacyStatus;
        }

        // Natural-language status recovery. This is intentionally text-based so
        // "safe for Muslims", "not haram", and "halal or at least not marked haram"
        // still work even when Gemini misses status_include/status_exclude.
        foreach ($this->extractStatusIncludeFromQuery($rawQueryText) as $status) {
            if (! in_array($status, $statusInclude, true)) {
                $statusInclude[] = $status;
            }
        }

        foreach ($this->extractStatusExcludeFromQuery($rawQueryText) as $status) {
            if (! in_array($status, $statusExclude, true)) {
                $statusExclude[] = $status;
            }
        }

        foreach ($this->extractDietIncludeFromQuery($rawQueryText) as $diet) {
            if (! in_array($diet, $dietInclude, true)) {
                $dietInclude[] = $diet;
            }
        }

        foreach ($this->extractDietExcludeFromQuery($rawQueryText) as $diet) {
            if (! in_array($diet, $dietExclude, true)) {
                $dietExclude[] = $diet;
            }
        }

        $limit     = max(1, min((int) ($arguments['limit'] ?? 12), 30));
        $matchMode = strtolower((string) ($arguments['match_mode'] ?? 'all'));
        if (! in_array($matchMode, ['all', 'any'], true)) {
            $matchMode = 'all';
        }

        // Final DB-side safety: explicit ingredient connectors in the original query
        // override any resolver/LLM mistake. This protects demo prompts like
        // "sugar and salt" from becoming "sugar OR salt" because of broad words
        // such as gym, nutrients, healthy, minerals, etc.
        $ingredientConnectorText = $ingredientQueryText !== '' ? $ingredientQueryText : $rawQueryText;
        $explicitIngredientMode = $this->resolveExplicitIngredientMatchMode($ingredientConnectorText, $ingredientsInclude);
        if ($explicitIngredientMode !== null) {
            $matchMode = $explicitIngredientMode;
        }

        $ingredientBooleanGroups = $this->resolveIngredientBooleanGroups($ingredientConnectorText, $ingredientsInclude);
        $dbIngredientMatchMode = ! empty($ingredientBooleanGroups)
            ? ($this->ingredientBooleanGroupsNeedBroadDbMatch($ingredientBooleanGroups) ? 'any' : 'all')
            : $matchMode;

        if ($includeHadBroadAnimalGroup && $matchMode === 'all' && $explicitIngredientMode !== 'all') {
            $matchMode = 'any';
            if (empty($ingredientBooleanGroups)) {
                $dbIngredientMatchMode = 'any';
            }
        }

        if (! empty($productNames)) {
            // Product-name lists are explicit targets. Keeping the full conversational
            // sentence as query text causes false narrowing and unrelated fuzzy leakage.
            $queryText = '';
            $brand = '';
            $category = '';
        } elseif (! empty($productNames) || ! empty($ingredientsInclude) || ! empty($ingredientsExclude) || ! empty($origins) || $brand !== '' || $category !== '' || ! empty($dietInclude) || ! empty($dietExclude)) {
            $queryText = $this->cleanNoisySearchText($queryText, ! empty($ingredientsInclude) || ! empty($ingredientsExclude));
        }

        // For category browsing, the category filter itself is the main search.
        // Keeping long natural-language query text here accidentally narrows results
        // to words like "specially", "barcode", "ingredients", or the focused product name.
        if ($category !== '' && $this->isCategoryBrowseQuery($rawQueryText) && ! $this->shouldPreserveSubtypeQueryText($rawQueryText, $category)) {
            $queryText = '';
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
            || ! empty($statusExclude)
            || ! empty($dietInclude)
            || ! empty($dietExclude);

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
            statusInclude: $statusInclude,
            statusExclude: $statusExclude,
            dietInclude: $dietInclude,
            dietExclude: $dietExclude,
            edibleOnly: $edibleOnly,
            broad: false,
            matchMode: $dbIngredientMatchMode
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
            statusInclude: $statusInclude,
            statusExclude: $statusExclude,
            dietInclude: $dietInclude,
            dietExclude: $dietExclude,
            edibleOnly: $edibleOnly,
            broad: true,
            matchMode: $dbIngredientMatchMode
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
                statusInclude: $statusInclude,
                statusExclude: $statusExclude,
                dietInclude: $dietInclude,
                dietExclude: $dietExclude,
                edibleOnly: $edibleOnly,
                broad: true,
                matchMode: $dbIngredientMatchMode
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
                statusInclude: $statusInclude,
                statusExclude: $statusExclude,
                dietInclude: $dietInclude,
                dietExclude: $dietExclude,
                edibleOnly: $edibleOnly,
                broad: true,
                matchMode: $dbIngredientMatchMode
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
            $focusProduct = $focusProductName !== '' ? $this->findFocusedProductForMeta($focusProductName) : null;

            if ($focusProduct !== null) {
                return [
                    'status'   => 'found',
                    'message'  => 'Focused product found, but no category list products matched your filters.',
                    'products' => [$focusProduct],
                    'meta'     => [
                        'tool'                 => 'search_products',
                        'query'                => $queryText,
                        'raw_query'            => $rawQueryText,
                        'brand'                => $brand,
                        'category'             => $category,
                        'origin'               => $origin,
                        'origins'              => $origins,
                        'status_include'       => $statusInclude,
                        'status_exclude'       => $statusExclude,
                        'diet_include'         => $dietInclude,
                        'diet_exclude'         => $dietExclude,
                        'focus_product_name'   => $focusProductName,
                        'focus_product'        => $focusProduct,
                        'category_list_empty'  => true,
                        'edible_only'          => $edibleOnly,
                        'strict_category_query'=> $strictCategoryQuery,
                        'has_hard_filters'     => $hasHardFilters,
                    ],
                ];
            }

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
                'diet_include'         => $dietInclude,
                'diet_exclude'         => $dietExclude,
                'focus_product_name'  => $focusProductName,
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
            $statusExclude,
            $dietInclude,
            $dietExclude,
            $matchMode,
            $ingredientBooleanGroups
        );

        $allProducts = $this->filterUsableCatalogProducts($allProducts);

        if ($allProducts->isEmpty()) {
            $focusProduct = $focusProductName !== '' ? $this->findFocusedProductForMeta($focusProductName) : null;

            if ($focusProduct !== null) {
                return [
                    'status'   => 'found',
                    'message'  => 'Focused product found, but no category list products matched your filters.',
                    'products' => [$focusProduct],
                    'meta'     => [
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
                        'focus_product_name'   => $focusProductName,
                        'focus_product'        => $focusProduct,
                        'category_list_empty'  => true,
                        'strict_filtered_empty'=> true,
                    ],
                ];
            }

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
                'diet_include'         => $dietInclude,
                'diet_exclude'         => $dietExclude,
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
                'diet_include'  => $dietInclude,
                'diet_exclude'  => $dietExclude,
                'origin'        => $origin,
                'origins'       => $origins,
                'edible_only'   => $edibleOnly,
            ]);
        }

        $preferredProducts = $this->deduplicateCatalogProducts($preferredProducts);
        $fallbackProducts = $this->deduplicateCatalogProducts($fallbackProducts);

        $finalProducts = $preferredProducts->take($limit)->values();
        $fallbackReason = null;
        $focusProduct = $focusProductName !== '' ? $this->findFocusedProductForMeta($focusProductName) : null;

        if ($focusProduct !== null) {
            $finalProducts = $this->mergeFocusProductIntoList($finalProducts, $focusProduct, $limit);
        }

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
                'diet_include'   => $dietInclude,
                'diet_exclude'   => $dietExclude,
            ]);
        }

        $finalProducts = $finalProducts->map(function ($product) use ($ingredientsInclude, $ingredientsExclude, $matchMode) {
            unset($product['__score']);
            return $this->attachIngredientCardNote($product, $ingredientsInclude, $ingredientsExclude, $matchMode);
        })->values();

        $finalProducts = collect($this->deduplicateProductArray($finalProducts->all()))->values();

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
                'diet_include'          => $dietInclude,
                'diet_exclude'          => $dietExclude,
                'ingredients_include'   => $ingredientsInclude,
                'ingredients_exclude'   => $ingredientsExclude,
                'focus_product_name'    => $focusProductName,
                'focus_product'         => $focusProduct,
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


    protected function attachIngredientCardNote(array $product, array $ingredientsInclude, array $ingredientsExclude, string $matchMode): array
    {
        $ingredientsText = strtolower((string) ($product['ingredients'] ?? ''));
        $notes = [];

        $includedHits = [];
        foreach ($ingredientsInclude as $ingredient) {
            $ingredient = $this->normalizeKeyword((string) $ingredient);
            if ($ingredient !== '' && $this->ingredientTextContains($ingredientsText, $ingredient)) {
                $includedHits[] = $ingredient;
            }
        }
        if (! empty($includedHits)) {
            $notes[] = 'contains ' . implode(', ', array_values(array_unique($includedHits)));
        }

        foreach ($ingredientsExclude as $ingredient) {
            $ingredient = $this->normalizeKeyword((string) $ingredient);
            if ($ingredient === '') {
                continue;
            }
            if ($ingredientsText !== '' && ! $this->ingredientTextContains($ingredientsText, $ingredient)) {
                $notes[] = 'no ' . $ingredient . ' found';
            }
        }

        if (! empty($notes)) {
            $product['ingredient_note'] = implode('; ', array_values(array_unique($notes)));
        }

        return $product;
    }

    protected function resolveExplicitIngredientMatchMode(string $queryText, array $ingredients): ?string
    {
        $queryText = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $queryText)));
        $ingredients = array_values(array_unique(array_filter(array_map(fn ($item) => $this->normalizeKeyword((string) $item), $ingredients))));

        if ($queryText === '' || count($ingredients) < 2) {
            return null;
        }

        if ($this->queryHasIngredientConnector($queryText, $ingredients, 'and')) {
            return 'all';
        }

        if ($this->queryHasIngredientConnector($queryText, $ingredients, 'or')) {
            return 'any';
        }

        if (preg_match('/\b(?:must\s+have|must\s+be\s+having|required|required\s+with|all\s+of|both|together|same\s+product)\b/iu', $queryText) === 1) {
            return 'all';
        }

        return null;
    }

    protected function queryHasIngredientConnector(string $queryText, array $ingredients, string $connector): bool
    {
        $connectorPattern = $connector === 'or' ? '(?:or|either|any\s+of)' : '(?:and|plus|&|\+)';

        for ($i = 0; $i < count($ingredients); $i++) {
            for ($j = $i + 1; $j < count($ingredients); $j++) {
                foreach ($this->expandIngredientAliases($ingredients[$i]) as $leftAlias) {
                    foreach ($this->expandIngredientAliases($ingredients[$j]) as $rightAlias) {
                        $left = preg_quote(mb_strtolower((string) $leftAlias), '/');
                        $right = preg_quote(mb_strtolower((string) $rightAlias), '/');

                        if ($left === '' || $right === '') {
                            continue;
                        }

                        if (preg_match('/(?<![\pL\pN])' . $left . '(?![\pL\pN])\s+' . $connectorPattern . '\s+(?<![\pL\pN])' . $right . '(?![\pL\pN])/iu', $queryText) === 1
                            || preg_match('/(?<![\pL\pN])' . $right . '(?![\pL\pN])\s+' . $connectorPattern . '\s+(?<![\pL\pN])' . $left . '(?![\pL\pN])/iu', $queryText) === 1) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }


    protected function resolveIngredientBooleanGroups(string $queryText, array $ingredients): array
    {
        $queryText = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $queryText)));
        $ingredients = array_values(array_unique(array_filter(array_map(fn ($item) => $this->normalizeKeyword((string) $item), $ingredients))));

        if ($queryText === '' || count($ingredients) < 2) {
            return [];
        }

        $mentions = [];
        foreach ($ingredients as $ingredient) {
            foreach ($this->expandIngredientAliases($ingredient) as $alias) {
                $alias = mb_strtolower(trim((string) $alias));
                if ($alias === '') {
                    continue;
                }

                if (preg_match('/(?<![\pL\pN])' . preg_quote($alias, '/') . '(?![\pL\pN])/iu', $queryText, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $mentions[] = [
                        'ingredient' => $ingredient,
                        'start' => (int) $match[0][1],
                        'end' => (int) $match[0][1] + strlen((string) $match[0][0]),
                    ];
                    break;
                }
            }
        }

        usort($mentions, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $sequence = [];
        $seen = [];
        foreach ($mentions as $mention) {
            $ingredient = (string) $mention['ingredient'];
            if (isset($seen[$ingredient])) {
                continue;
            }
            $seen[$ingredient] = true;
            $sequence[] = $mention;
        }

        if (count($sequence) < 2) {
            return [];
        }

        $groups = [];
        $currentGroup = [(string) $sequence[0]['ingredient']];
        $sawConnector = false;

        for ($i = 0; $i < count($sequence) - 1; $i++) {
            $between = trim(substr($queryText, (int) $sequence[$i]['end'], (int) $sequence[$i + 1]['start'] - (int) $sequence[$i]['end']));
            $nextIngredient = (string) $sequence[$i + 1]['ingredient'];

            if (preg_match('/\b(?:or|either|any\s+of)\b/iu', $between) === 1) {
                $currentGroup[] = $nextIngredient;
                $sawConnector = true;
                continue;
            }

            if (preg_match('/(?:\b(?:and|plus)\b|&|\+)/iu', $between) === 1) {
                $groups[] = array_values(array_unique($currentGroup));
                $currentGroup = [$nextIngredient];
                $sawConnector = true;
                continue;
            }

            return [];
        }

        $groups[] = array_values(array_unique($currentGroup));

        return $sawConnector ? array_values(array_filter($groups, fn (array $group): bool => ! empty($group))) : [];
    }

    protected function ingredientBooleanGroupsNeedBroadDbMatch(array $groups): bool
    {
        foreach ($groups as $group) {
            if (is_array($group) && count($group) > 1) {
                return true;
            }
        }

        return false;
    }


    // ─────────────────────────────────────────────────────────────
    // QUERY BUILDER
    // ─────────────────────────────────────────────────────────────

    /**
     * Builds the database query for catalog searches using normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildSearchQuery(
        string $queryText,
        string $brand,
        string $category,
        string $origin,
        array $origins,
        array $productNames,
        array $ingredientsInclude,
        array $ingredientsExclude,
        array $statusInclude,
        array $statusExclude,
        array $dietInclude,
        array $dietExclude,
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

        if (! empty($statusInclude) || ! empty($statusExclude)) {
            $this->applyStatusFilter($query, $statusInclude, $statusExclude);
        }

        if (! empty($dietInclude) || ! empty($dietExclude)) {
            $this->applyDietFilter($query, $dietInclude, $dietExclude);
        }

        if ($edibleOnly) {
            $this->applyEdibleFilter($query);
        }

        if ($queryText !== '') {
            $tokens       = $this->tokenize($queryText);
            $strongTokens = array_values(array_filter($tokens, fn ($token) => strlen($token) >= 4));

            $query->where(function (Builder $builder) use ($queryText, $tokens, $strongTokens, $broad): void {
                $columns = ['name_normalized', 'name', 'brand_normalized', 'brand', 'description', 'ingredients', 'category', 'main_category', 'main_category1', 'main_category_1', 'categories', 'notes', 'origin'];

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
    // SIMILAR PRODUCTS
    // ─────────────────────────────────────────────────────────────

    /**
     * Finds "similar by category" from database records or in-memory product data.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    public function findSimilarByCategory(array $arguments = []): array
    {
        $imageContext = is_array($arguments['image_context'] ?? null) ? $arguments['image_context'] : [];
        $seedName = $this->normalizeText((string) ($arguments['seed_name'] ?? ($arguments['name'] ?? ($imageContext['product_name'] ?? ''))));
        $seedBarcode = $this->normalizeBarcode((string) ($arguments['seed_barcode'] ?? ($arguments['barcode'] ?? ($imageContext['barcode'] ?? ''))));
        $category = $this->normalizeCategory((string) ($arguments['category'] ?? ($imageContext['category'] ?? '')));
        $brand = $this->normalizeBrand((string) ($arguments['brand'] ?? ($imageContext['brand'] ?? '')));
        $limit = max(1, min((int) ($arguments['limit'] ?? 12), 30));

        if ($category === '' && $seedName !== '') {
            $seedLookup = $this->findProductByName($seedName, ['image_context' => $imageContext]);
            $seedProduct = $seedLookup['products'][0] ?? null;
            if (is_array($seedProduct)) {
                $category = $this->normalizeCategory((string) ($seedProduct['category'] ?? ($seedProduct['main_category'] ?? ($seedProduct['main_category1'] ?? ($seedProduct['main_category_1'] ?? '')))));
                $brand = $brand !== '' ? $brand : $this->normalizeBrand((string) ($seedProduct['brand'] ?? ''));
            }
        }

        if ($category === '') {
            return $this->notFound('I need a category or a matched product before I can find similar products.', [
                'tool' => 'find_similar_by_category',
                'seed_name' => $seedName,
                'seed_barcode' => $seedBarcode,
                'image_context' => $imageContext,
            ]);
        }

        $lookup = $this->searchProducts([
            'query' => '',
            'category' => $category,
            'brand' => '',
            'origin' => $arguments['origin'] ?? null,
            'origins' => $arguments['origins'] ?? [],
            'status_include' => $arguments['status_include'] ?? [],
            'status_exclude' => $arguments['status_exclude'] ?? [],
            'limit' => $limit + 3,
        ]);

        $products = collect(is_array($lookup['products'] ?? null) ? $lookup['products'] : [])
            ->reject(function (array $product) use ($seedBarcode, $seedName): bool {
                $barcode = $this->normalizeBarcode((string) ($product['barcode'] ?? ''));
                if ($seedBarcode !== '' && $barcode !== '' && $barcode === $seedBarcode) {
                    return true;
                }

                $name = $this->normalizeText((string) ($product['name'] ?? ''));
                return $seedName !== '' && mb_strtolower($name) === mb_strtolower($seedName);
            })
            ->take($limit)
            ->values()
            ->all();

        if (empty($products)) {
            return $this->notFound('No similar products matched your database filters.', [
                'tool' => 'find_similar_by_category',
                'category' => $category,
                'brand' => $brand,
                'seed_name' => $seedName,
                'seed_barcode' => $seedBarcode,
            ]);
        }

        return [
            'status' => 'found',
            'message' => 'Similar products found by category.',
            'products' => $products,
            'meta' => array_merge(is_array($lookup['meta'] ?? null) ? $lookup['meta'] : [], [
                'tool' => 'find_similar_by_category',
                'category' => $category,
                'brand' => $brand,
                'seed_name' => $seedName,
                'seed_barcode' => $seedBarcode,
                'result_count' => count($products),
            ]),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // EXPLAIN INGREDIENT
    // ─────────────────────────────────────────────────────────────

    /**
     * Helper method for "explain ingredient".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Applies halal/haram/unknown include/exclude filters to the query.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function applyDietFilter(Builder $query, array $dietInclude, array $dietExclude): void
    {
        foreach ($dietInclude as $diet) {
            $column = $this->dietColumnName($diet);
            if ($column !== null && $this->hasColumn($column)) {
                $query->where($column, 1);
            }
        }

        foreach ($dietExclude as $diet) {
            $column = $this->dietColumnName($diet);
            if ($column !== null && $this->hasColumn($column)) {
                $query->where(function (Builder $builder) use ($column): void {
                    $builder->whereNull($column)->orWhere($column, 0);
                });
            }
        }
    }

    protected function dietColumnName(string $diet): ?string
    {
        return match ($this->normalizeDietValue($diet)) {
            'kosher' => 'is_kosher',
            'vegetarian' => 'is_vegetarian',
            'vegan' => 'is_vegan',
            'gluten_free' => 'is_gluten_free',
            default => null,
        };
    }

    protected function productMatchesDietFlags(array $product, array $dietFilters, bool $expected): bool
    {
        foreach ($dietFilters as $diet) {
            $column = $this->dietColumnName((string) $diet);
            if ($column === null) {
                continue;
            }

            $value = (bool) ($product[$column] ?? false);
            if ($expected && ! $value) {
                return false;
            }
            if (! $expected && $value) {
                return false;
            }
        }

        return true;
    }

    protected function applyStatusFilter(Builder $query, array $statusInclude, array $statusExclude): void
    {
        $statusColumns = array_values(array_filter(['type', 'decision', 'status'], fn ($column) => $this->hasColumn($column)));

        if (empty($statusColumns)) {
            return;
        }

        $statusInclude = array_values(array_unique(array_filter(array_map(fn ($status) => $this->normalizeStatusValue((string) $status), $statusInclude))));
        $statusExclude = array_values(array_unique(array_filter(array_map(fn ($status) => $this->normalizeStatusValue((string) $status), $statusExclude))));

        if (! empty($statusInclude)) {
            $query->where(function (Builder $statusBuilder) use ($statusInclude, $statusColumns): void {
                foreach ($statusInclude as $status) {
                    foreach ($this->expandStatusAliases($status) as $alias) {
                        foreach ($statusColumns as $column) {
                            $statusBuilder->orWhereRaw('LOWER(' . $column . ') = ?', [Str::lower($alias)]);
                        }
                    }
                }
            });
        }

        if (! empty($statusExclude)) {
            foreach ($statusExclude as $status) {
                $aliases = $this->expandStatusAliases($status);
                $query->where(function (Builder $statusBuilder) use ($aliases, $statusColumns): void {
                    foreach ($aliases as $alias) {
                        foreach ($statusColumns as $column) {
                            $statusBuilder->whereRaw('(LOWER(' . $column . ') IS NULL OR LOWER(' . $column . ') != ?)', [Str::lower($alias)]);
                        }
                    }
                });
            }
        }
    }

    /**
     * Applies "product names filter" to a database query or product collection.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Applies "origin list filter" to a database query or product collection.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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
                $this->orWhereOriginAlias($builder, (string) $alias);
            }
        });
    }

    /**
     * Applies "brand filter" to a database query or product collection.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function applyBrandFilter(Builder $query, string $brand): void
    {
        $aliases = $this->expandBrandAliases($brand);

        $query->where(function (Builder $builder) use ($aliases): void {
            foreach ($aliases as $alias) {
                foreach (['brand', 'brand_normalized', 'name', 'name_normalized', 'manufactured_by'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $alias . '%']);
                    }
                }
            }
        });
    }

    /**
     * Applies "origin filter" to a database query or product collection.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function applyOriginFilter(Builder $query, string $origin): void
    {
        if (! $this->hasColumn('origin')) {
            return;
        }

        $aliases = $this->expandOriginAliases($origin);

        $query->where(function (Builder $builder) use ($aliases): void {
            foreach ($aliases as $alias) {
                $this->orWhereOriginAlias($builder, (string) $alias);
            }
        });
    }

    /**
     * Helper method for "or where origin alias".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function orWhereOriginAlias(Builder $builder, string $alias): void
    {
        $forms = $this->originComparableForms($alias);
        if (empty($forms)) {
            return;
        }

        foreach ($forms as $form) {
            $form = Str::lower(trim((string) $form));
            if ($form === '') {
                continue;
            }

            // Short aliases like "us", "uk", "pk" must not match inside longer words
            // such as "Australia". Use separator-aware matching for short aliases.
            if (mb_strlen($form) <= 2 || str_contains($form, '.')) {
                $builder
                    ->orWhereRaw('LOWER(origin) = ?', [$form])
                    ->orWhereRaw('LOWER(origin) like ?', [$form . ',%'])
                    ->orWhereRaw('LOWER(origin) like ?', ['%,' . $form])
                    ->orWhereRaw('LOWER(origin) like ?', ['%,' . $form . ',%'])
                    ->orWhereRaw('LOWER(origin) like ?', [$form . ' %'])
                    ->orWhereRaw('LOWER(origin) like ?', ['% ' . $form])
                    ->orWhereRaw('LOWER(origin) like ?', ['% ' . $form . ' %'])
                    ->orWhereRaw('LOWER(origin) like ?', ['%(' . $form . ')%'])
                    ->orWhereRaw('LOWER(origin) like ?', ['%-' . $form . '-%'])
                    ->orWhereRaw('LOWER(origin) like ?', ['%_' . $form . '_%']);
                continue;
            }

            $builder->orWhereRaw('LOWER(origin) like ?', ['%' . $form . '%']);
        }

        // Generic DB-driven comparison:
        // Czech-republic == Czech Republic == czech_republic == czechrepublic.
        // Uses single-quoted SQL string literals for MySQL/ANSI_QUOTES compatibility.
        foreach ($this->compactOriginForms($forms) as $compact) {
            $builder->orWhereRaw(
                "REPLACE(REPLACE(REPLACE(LOWER(origin), '-', ''), '_', ''), ' ', '') like ?",
                ['%' . $compact . '%']
            );
        }
    }

    /**
     * Helper method for "origin text matches alias".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function originTextMatchesAlias(string $originText, string $alias): bool
    {
        $originText = Str::lower(trim($originText));
        $alias = Str::lower(trim($alias));

        if ($originText === '' || $alias === '') {
            return false;
        }

        $originForms = $this->originComparableForms($originText);
        $aliasForms = $this->originComparableForms($alias);

        foreach ($aliasForms as $aliasForm) {
            $aliasForm = Str::lower(trim((string) $aliasForm));
            if ($aliasForm === '') {
                continue;
            }

            if (mb_strlen($aliasForm) <= 2 || str_contains($aliasForm, '.')) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($aliasForm, '/') . '(?![a-z0-9])/iu', $originText) === 1) {
                    return true;
                }
                continue;
            }

            foreach ($originForms as $originForm) {
                $originForm = Str::lower(trim((string) $originForm));
                if ($originForm !== '' && (str_contains($originForm, $aliasForm) || str_contains($aliasForm, $originForm))) {
                    return true;
                }
            }
        }

        $originCompactForms = $this->compactOriginForms($originForms);
        $aliasCompactForms = $this->compactOriginForms($aliasForms);
        foreach ($originCompactForms as $originCompact) {
            foreach ($aliasCompactForms as $aliasCompact) {
                if ($originCompact !== '' && $aliasCompact !== '' && (str_contains($originCompact, $aliasCompact) || str_contains($aliasCompact, $originCompact))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Applies category filters using aliases and singular/plural variations.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function applyCategoryFilter(Builder $query, string $category): void
    {
        $aliases = $this->expandCategoryAliases($category);

        // Category fields are often empty in imported product data.
        // Search category columns plus product identity text for generic category requests.
        $query->where(function (Builder $builder) use ($aliases): void {
            foreach ($aliases as $alias) {
                $alias = Str::lower(trim((string) $alias));
                if ($alias === '') {
                    continue;
                }

                foreach ([
                    'category',
                    'main_category',
                    'main_category1',
                    'main_category_1',
                    'categories',
                    'name_normalized',
                    'name',
                    'brand_normalized',
                    'brand',
                    'description',
                    'notes',
                ] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $alias . '%']);
                    }
                }
            }
        });
    }
    /**
     * Applies ingredient include/exclude filters to ingredient-related columns.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Applies "edible filter" to a database query or product collection.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function applyEdibleFilter(Builder $query): void
    {
        $positive = ['chocolate', 'chocolates', 'snack', 'snacks', 'drink', 'drinks', 'beverage', 'juice', 'biscuit', 'biscuits', 'cookie', 'cookies', 'cake', 'candy', 'bar', 'nougat', 'food', 'eat', 'edible', 'confectionery', 'dairy', 'sauce', 'mayonnaise', 'ketchup', 'chips', 'crisps', 'noodles', 'bread', 'meat', 'beef', 'chicken', 'poultry', 'mutton', 'lamb', 'sausage', 'bakery', 'cereal', 'pasta'];
        $negative = ['soap', 'shampoo', 'detergent', 'cleaner', 'cleaning', 'household', 'toothpaste', 'cosmetic', 'lotion', 'hand wash', 'handwash', 'antiseptic', 'disinfectant', 'sanitizer', 'sanitiser', 'bleach', 'dettol', 'carex'];

        $query->where(function (Builder $builder) use ($positive): void {
            foreach ($positive as $term) {
                foreach (['name', 'description', 'category', 'main_category', 'main_category1', 'main_category_1', 'categories', 'notes'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->orWhereRaw('LOWER(' . $column . ') like ?', ['%' . $term . '%']);
                    }
                }
            }
        });

        $query->where(function (Builder $builder) use ($negative): void {
            foreach ($negative as $term) {
                foreach (['name', 'description', 'category', 'main_category', 'main_category1', 'main_category_1', 'categories', 'notes'] as $column) {
                    if ($this->hasColumn($column)) {
                        $builder->whereRaw('LOWER(' . $column . ') not like ?', ['%' . $term . '%']);
                    }
                }
            }
        });
    }

    /**
     * Boolean helper that checks whether the current request or product matches "wants edible products".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function wantsEdibleProducts(string $queryText): bool
    {
        return (bool) preg_match('/\beat\b|\bdrink\b|\bto eat\b|\bto drink\b|\bhungry\b|\bafter\s+gym\b|\bworkout\b|\bsnack\b|\bfood\b|\bgrocery\b/i', strtolower($queryText))
            && preg_match('/\b(household|cleaning|washroom|bathroom|kitchen|hand\s*washes?|handwash(?:es)?|hand\s*soap|soap|antiseptic|dettol|detol|carex)\b/i', strtolower($queryText)) !== 1;
    }

    // ─────────────────────────────────────────────────────────────
    // RANKING
    // ─────────────────────────────────────────────────────────────

    /**
     * Builds "ranking seed" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Scores or ranks data for "rank by phrase".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Helper method for "filter products by image context".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Helper method for "expand ingredient aliases".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function expandIngredientAliases(string $ingredient): array
    {
        $ingredient = $this->normalizeKeyword($ingredient);

        $aliases = match ($ingredient) {
            'sugar' => ['sugar', 'suger', 'sugars', 'sugers', 'sucrose'],
            'salt' => ['salt', 'sodium chloride', 'sodium'],
            'palm oil' => ['palm oil', 'palmolein', 'palm olein'],
            'vegetable oil' => ['vegetable oil', 'vegetable oils', 'vegitable oil', 'vegitable oils'],
            'olive oil' => ['olive oil'],
            'sunflower oil' => ['sunflower oil'],
            'canola oil' => ['canola oil', 'rapeseed oil'],
            'coconut oil' => ['coconut oil'],
            'mayonnaise' => ['mayonnaise', 'mayo', 'mayonese', 'mayounese'],
            'ketchup' => ['ketchup', 'tomato ketchup'],
            'soy sauce' => ['soy sauce', 'soya sauce'],
            'folic acid' => ['folic acid', 'folate'],
            'vitamin b' => ['vitamin b', 'vitamin b1', 'vitamin b2', 'vitamin b3', 'vitamin b6', 'vitamin b12', 'thiamine', 'riboflavin', 'niacin', 'cyanocobalamin', 'pyridoxine', 'folic acid', 'folate'],
            'vitamin b1' => ['vitamin b1', 'thiamine', 'thiamin'],
            'vitamin b2' => ['vitamin b2', 'riboflavin'],
            'vitamin b3' => ['vitamin b3', 'niacin', 'niacinamide'],
            'vitamin b6' => ['vitamin b6', 'pyridoxine'],
            'vitamin b12' => ['vitamin b12', 'cyanocobalamin'],
            'wheat' => ['wheat', 'wheat flour'],
            'flour' => ['flour', 'wheat flour'],
            'wheat flour' => ['wheat flour', 'wheat'],
            'rice powder' => ['rice powder'],
            'rice flour' => ['rice flour'],
            'rice starch' => ['rice starch'],
            'cocoa butter' => ['cocoa butter'],
            // Keep plain milk strict. Whey/casein are animal/dairy-derived, but
            // treating them as plain "milk" makes searches like "milk and sugar"
            // return products that only mention whey/casein. Broader animal-derived
            // checks still include whey and casein separately.
            'milk' => ['milk', 'milk powder', 'milk solids', 'whole milk', 'skimmed milk', 'skim milk', 'milk chocolate', 'condensed milk', 'evaporated milk'],
            'whey' => ['whey', 'whey powder'],
            'casein' => ['casein'],
            'butter' => ['butter'],
            'cheese' => ['cheese', 'cheddar'],
            'egg' => ['egg', 'eggs'],
            'honey' => ['honey'],
            'gelatin' => ['gelatin', 'gelatine'],
            'pork' => ['pork', 'lard'],
            'lard' => ['lard', 'pork'],
            'animal fat' => ['animal fat'],
            'carmine' => ['carmine', 'e120', 'cochineal'],
            'rennet' => ['rennet'],
            'enzymes' => ['enzymes', 'enzyme'],
            'alcohol' => ['alcohol', 'alcoholic', 'ethanol', 'wine', 'beer', 'rum', 'brandy', 'liqueur', 'spirit', 'isopropyl alcohol'],
            'chocolate' => ['chocolate', 'cocoa'],
            'rice' => ['rice', 'rice powder', 'rice starch', 'rice flour', 'dried rice syrup'],
            'corn' => ['corn', 'corn flour', 'corn starch', 'corn syrup', 'maize'],
            'spices' => ['spice', 'spices', 'spicy', 'masala', 'seasoning', 'seasonings', 'curry', 'curry spice', 'curry spice mix', 'chili', 'chilli', 'red chilli', 'paprika', 'pepper', 'black pepper', 'garlic powder', 'onion powder', 'turmeric', 'ginger'],
            'vitamin' => ['vitamin', 'vitamins', 'vitamin a', 'vitamin c', 'vitamin d', 'vitamin e', 'vitamin k'],
            'nutrient' => ['vitamin', 'vitamins', 'protein', 'fiber', 'fibre', 'calcium', 'iron', 'zinc', 'folic acid', 'folate'],
            'nutrients' => ['vitamin', 'vitamins', 'protein', 'fiber', 'fibre', 'calcium', 'iron', 'zinc', 'folic acid', 'folate'],
            'protein' => ['protein', 'proteins', 'whey protein', 'pea protein', 'milk protein'],
            'fiber' => ['fiber', 'fibers', 'fibre', 'soluble corn fiber', 'corn fiber'],
            'calcium' => ['calcium', 'calcium carbonate', 'calcium silicate'],
            'iron' => ['iron', 'ferrous sulfate'],
            'zinc' => ['zinc', 'zinc gluconate'],
            'fiber' => ['fiber', 'fibers', 'fibre'],
            'carbohydrate' => ['carbohydrate', 'carbohydrates', 'carbs'],
            'lemon juice' => ['lemon juice'],
            'lime juice' => ['lime juice'],
            'spices' => ['spices', 'spice', 'spicy', 'spiced', 'masala', 'seasoning', 'seasonings', 'curry', 'curry spice', 'curry spice mix', 'chili', 'chilli', 'red chilli', 'paprika', 'pepper', 'black pepper', 'garlic powder', 'onion powder', 'turmeric', 'ginger'],
            'spice' => ['spices', 'spice', 'spicy', 'spiced', 'masala', 'seasoning', 'seasonings', 'curry', 'curry spice', 'curry spice mix', 'chili', 'chilli', 'red chilli', 'paprika', 'pepper', 'black pepper', 'garlic powder', 'onion powder', 'turmeric', 'ginger'],
            'animal derived' => $this->animalDerivedIngredientTerms(),
            default => [$ingredient],
        };

        return array_values(array_unique(array_filter($aliases)));
    }

    /**
     * Helper method for "expand broad ingredient concepts".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function expandBroadIngredientConcepts(array $ingredients): array
    {
        $expanded = [];

        foreach ($ingredients as $ingredient) {
            $ingredient = $this->normalizeKeyword((string) $ingredient);
            if ($ingredient === '') {
                continue;
            }

            if ($ingredient === 'animal derived') {
                $expanded = array_merge($expanded, $this->animalDerivedIngredientTerms());
            } elseif ($ingredient === 'vitamin b') {
                $expanded = array_merge($expanded, $this->expandIngredientAliases('vitamin b'));
            } else {
                $expanded[] = $ingredient;
            }
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    /**
     * Helper method for "animal derived ingredient terms".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function animalDerivedIngredientTerms(): array
    {
        return [
            'gelatin', 'pork', 'lard', 'animal fat', 'carmine', 'rennet', 'enzymes',
            'whey', 'casein', 'milk', 'butter', 'cheese', 'egg', 'honey', 'yogurt', 'yoghurt',
        ];
    }


    /**
     * Removes placeholder/import rows that should never appear in catalog suggestions.
     * The supplied database contains many rows named exactly "Product" with N/A fields;
     * they are useful for raw imports but harmful for user-facing recommendations.
     */
    protected function filterUsableCatalogProducts(Collection $products): Collection
    {
        return $products->filter(function (array $product): bool {
            $name = trim((string) ($product['name'] ?? ''));
            $barcode = trim((string) ($product['barcode'] ?? ''));
            $ingredients = trim((string) ($product['ingredients'] ?? ''));
            $description = trim((string) ($product['description'] ?? ''));
            $category = trim((string) ($product['category'] ?? ($product['main_category'] ?? '')));
            $origin = trim((string) ($product['origin'] ?? ''));

            if ($name === '') {
                return false;
            }

            if (preg_match('/^product$/iu', $name) === 1
                && preg_match('/^(?:n\/?a|na)?$/iu', $category) === 1
                && preg_match('/^(?:n\/?a|na)?$/iu', $origin) === 1
                && $ingredients === ''
                && $description === '') {
                return false;
            }

            return $barcode !== '' || $ingredients !== '' || $description !== '' || preg_match('/^product$/iu', $name) !== 1;
        })->values();
    }

    /**
     * Deduplicates catalog results by normalized name and by normalized barcode.
     * This hides duplicate imports such as normal barcodes vs scientific-notation rows.
     */
    protected function deduplicateCatalogProducts(Collection $products): Collection
    {
        $seen = [];

        return $products->filter(function (array $product) use (&$seen): bool {
            $name = $this->normalizeText((string) ($product['name'] ?? ''));
            $barcode = $this->normalizeBarcode((string) ($product['barcode'] ?? ''));
            $identity = strtolower(trim((string) ($product['brand'] ?? '') . ' ' . $name));

            $keys = [];
            if ($barcode !== '') {
                $keys[] = 'barcode:' . $barcode;
            }
            if ($identity !== '') {
                $keys[] = 'name:' . preg_replace('/[^a-z0-9]+/i', '', $identity);
            }

            foreach ($keys as $key) {
                if ($key !== '' && isset($seen[$key])) {
                    return false;
                }
            }

            foreach ($keys as $key) {
                if ($key !== '') {
                    $seen[$key] = true;
                }
            }

            return true;
        })->values();
    }

    /**
     * Enforce explicit filters using strict AND logic after all DB/ranking attempts.
     * This is the safety net that stops unrelated fallback results.
     */
    /**
     * Final in-memory guard that prevents unrelated country/category/status/ingredient results from leaking into the answer.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function applyStrictResultFilters(
        Collection $products,
        string $category,
        array $origins,
        string $origin,
        array $ingredientsInclude,
        array $ingredientsExclude,
        array $statusInclude,
        array $statusExclude,
        array $dietInclude,
        array $dietExclude,
        string $matchMode = 'all',
        array $ingredientBooleanGroups = []
    ): Collection {
        return $products->filter(function (array $product) use ($category, $origins, $origin, $ingredientsInclude, $ingredientsExclude, $statusInclude, $statusExclude, $dietInclude, $dietExclude, $matchMode, $ingredientBooleanGroups) {
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

            if (! empty($dietInclude) && ! $this->productMatchesDietFlags($product, $dietInclude, true)) {
                return false;
            }

            if (! empty($dietExclude) && ! $this->productMatchesDietFlags($product, $dietExclude, false)) {
                return false;
            }

            if (! empty($ingredientsInclude)) {
                $matchesIncludedIngredients = ! empty($ingredientBooleanGroups)
                    ? $this->productMatchesIngredientBooleanGroups($product, $ingredientBooleanGroups)
                    : $this->productMatchesIncludedIngredients($product, $ingredientsInclude, $matchMode === 'any');

                if (! $matchesIncludedIngredients) {
                    return false;
                }
            }

            if (! empty($ingredientsExclude) && $this->productMatchesExcludedIngredients($product, $ingredientsExclude)) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * Product helper used for "product matches category".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function productMatchesCategory(array $product, string $category): bool
    {
        $categoryFields = [
            (string) ($product['category'] ?? ''),
            (string) ($product['main_category'] ?? ''),
            (string) ($product['main_category1'] ?? ''),
            (string) ($product['main_category_1'] ?? ''),
            (string) ($product['categories'] ?? ''),
        ];

        $identityFields = [
            (string) ($product['name'] ?? ''),
            (string) ($product['brand'] ?? ''),
            (string) ($product['description'] ?? ''),
            (string) ($product['notes'] ?? ''),
            (string) ($product['ingredients'] ?? ''),
        ];

        return $this->productTextMatchesCategory($categoryFields, $identityFields, $category);
    }

    /**
     * Product helper used for "product text matches category".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function productTextMatchesCategory(array $categoryFields, array $identityFields, string $category): bool
    {
        $category = $this->normalizeCategory($category);
        $categoryText = strtolower(trim(implode(' ', array_filter($categoryFields))));
        $identityText = strtolower(trim(implode(' ', array_filter($identityFields))));
        $allText = trim($categoryText . ' ' . $identityText);
        $identityWithoutIngredients = strtolower(trim(implode(' ', array_filter(array_slice($identityFields, 0, 4)))));

        if ($category === '') {
            return true;
        }

        // Drinks must be actual drink/beverage products. Do not let chocolate,
        // nougat, biscuits, cakes, or snacks leak into drink results just because
        // broad SQL fallback fetched them.
        if ($category === 'drinks') {
            if (preg_match('/\b(chocolate|chocolates|cocoa|confectionery|nougat|biscuit|biscuits|cookie|cookies|cake|cakes|snack|snacks|chips|crisps|bar|lays|oreo|orio|mcvitie)\b/iu', $identityWithoutIngredients) === 1) {
                return false;
            }

            return preg_match('/\b(drinks?|beverages?|soft\s+drink|fizzy\s+drink|soda|pop|cola|sprite|coke|pepsi|7up|juice|water|almond\s+milk|plant[-\s]*based\s+drink|energy\s+drink|bottle|ml)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'juices') {
            if (preg_match('/\b(chocolate|chocolates|cocoa|confectionery|nougat|biscuit|biscuits|cookie|cookies|cake|cakes|snack|snacks|chips|crisps|bar|lays|oreo|orio|mcvitie)\b/iu', $identityWithoutIngredients) === 1) {
                return false;
            }

            return preg_match('/\b(juices?|fruit\s+juice|apple\s+juice|orange\s+juice|mango\s+juice)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'pasta') {
            return preg_match('/\b(pasta|pastas|noodles?|instant\s+noodles?|spaghetti|macaroni)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        // Cakes must be actual cake/cupcake products. Do not match words like
        // "pancakes" or generic syrup descriptions just because they contain
        // the substring "cake".
        if ($category === 'cakes') {
            return preg_match('/\b(cakes?|cupcakes?|super\s+cake|cake\s+bar)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'snacks') {
            return preg_match('/\b(snacks?|chips?|crisps?|potato\s+snacks?|cheetos|doritos|lays|nibb[-\s]?it|truffle\s+potato|thai\s+sweet\s+chilli)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'biscuits') {
            return preg_match('/\b(biscuits?|cookies?|crackers?|wafers?|oreo|orio|prince\s+chocolat|tuck\s+biscuit|candy\s+biscuit|sooper|mcvitie)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'chocolates') {
            return preg_match('/\b(chocolates?|cocoa|confectionery|nougat|toblerone|ferrero|rocher|kinder|dairy\s*milk|lindt|dark\s+chocolate|milk\s+chocolate|buttermilk\s+chocolate|coconut\s+milk\s+dark\s+chocolate)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'candies') {
            return preg_match('/\b(cand(?:y|ies)|sweets?|gumm(?:y|ies)|caramelos|hi[-\s]?chew|masticables|jelly|gelatina)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'sauces') {
            return preg_match('/\b(sauces?|condiments?|mayonnaise|mayo|ketchup|soy\s+sauce|dressing)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'spices') {
            return preg_match('/\b(spices?|masala|seasonings?|tikka\s+mix|chilli|chili|paprika|turmeric|ginger)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'breakfast') {
            return preg_match('/\b(breakfast|cereals?|oats?|granola)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'tea') {
            return preg_match('/\b(tea\s*bags?|green\s+tea|black\s+tea|tea)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'pantry') {
            return preg_match('/\b(pantry|pasta|spaghetti|noodles?|ketchup|stock\s+cubes?|yeast\s+extract|breadcrumbs?)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'canned_goods') {
            return preg_match('/\b(canned|beans?|chickpeas?|tin|tinned)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        // Dairy category must mean actual dairy shelf/products, not the brand phrase
        // "Dairy Milk Chocolate". Prefer explicit category columns and allow common
        // dairy item names only when they are not clearly chocolate/confectionery.
        if ($category === 'dairy') {
            if (preg_match('/(?<![a-z0-9])dairy(?![a-z0-9])/iu', $categoryText) === 1) {
                return true;
            }

            if (preg_match('/\b(milk|cheese|cheddar|butter|yogurt|yoghurt|cream)\b/iu', $identityText) === 1
                && preg_match('/\b(chocolate|chocolates|cocoa|confectionery|nougat|rocher|ferrero|kinder|candy|sweet|bar)\b/iu', $allText) !== 1) {
                return true;
            }

            return false;
        }

        // Plant-based / dairy alternative products should not be mixed with normal
        // dairy chocolate just because "milk" appears in ingredients.
        if ($category === 'dairy_alternatives') {
            return preg_match('/\b(dairy\s*alternatives?|plant\s*based|non\s*dairy|dairy\s*free|vegan\s+milk|almond\s+milk|soy\s+milk|soya\s+milk|oat\s+milk|rice\s+milk|coconut\s+milk)\b/iu', $allText) === 1;
        }

        // Household must stay non-food. This powers Dettol/Carex/hand wash/cleaning
        // queries and prevents snacks/chocolates from appearing in bathroom requests.
        if ($category === 'household') {
            return preg_match('/\b(household|clean(?:er|ing)?|washroom|bathroom|kitchen|soap|hand\s*wash|handwash|hand\s*soap|antiseptic|disinfect(?:ant|ing)|detergent|bleach|sanitizer|sanitiser|dettol|carex)\b/iu', $allText) === 1;
        }

        // Product-category "oils" should match oil products, not any food that has
        // palm oil buried in the ingredient label. Ingredient searches use ingredient
        // filters separately.
        if ($category === 'oils') {
            return preg_match('/\b(cooking\s+oil|edible\s+oil|oil|oils|olive\s+oil|sunflower\s+oil|canola\s+oil|palm\s+oil|coconut\s+oil|corn\s+oil)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        if ($category === 'sweeteners') {
            return preg_match('/\b(sweetener|sweeteners|sugar|honey|syrup|molasses|stevia|aspartame|sucralose)\b/iu', $categoryText . ' ' . $identityWithoutIngredients) === 1;
        }

        // Keep "beef products" narrow. Generic "meats" can include chicken/lamb,
        // but beef-specific queries should not return chicken soup or tikka mix.
        if ($category === 'beef') {
            return preg_match('/(?<![a-z0-9])beef(?![a-z0-9])/iu', $allText) === 1;
        }

        if ($category === 'chicken') {
            return preg_match('/\b(chicken|poultry)\b/iu', $allText) === 1;
        }

        $aliases = $this->expandCategoryAliases($category);
        foreach ($aliases as $alias) {
            $alias = strtolower(trim((string) $alias));
            if ($alias !== '' && str_contains($allText, $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Product helper used for "product matches any origin".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function productMatchesAnyOrigin(array $product, array $origins): bool
    {
        $originText = strtolower((string) ($product['origin'] ?? ''));
        if ($originText === '') {
            return false;
        }

        foreach ($origins as $origin) {
            foreach ($this->expandOriginAliases((string) $origin) as $alias) {
                if ($this->originTextMatchesAlias($originText, (string) $alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function productMatchesIngredientBooleanGroups(array $product, array $groups): bool
    {
        $ingredientsText = strtolower((string) ($product['ingredients'] ?? ''));
        if ($ingredientsText === '') {
            return false;
        }

        foreach ($groups as $group) {
            $group = is_array($group) ? $group : [];
            $group = array_values(array_unique(array_filter(array_map(fn ($item) => $this->normalizeKeyword((string) $item), $group))));
            if (empty($group)) {
                continue;
            }

            $matchedGroup = false;
            foreach ($group as $ingredient) {
                if ($this->ingredientTextContains($ingredientsText, $ingredient)) {
                    $matchedGroup = true;
                    break;
                }
            }

            if (! $matchedGroup) {
                return false;
            }
        }

        return true;
    }

    /**
     * Product helper used for "product matches included ingredients".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function productMatchesIncludedIngredients(array $product, array $ingredients, bool $any = false): bool
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

        if ($any) {
            return $hits >= 1;
        }

        // For 1-2 requested ingredients, require all.
        // For 3+ requested ingredients, return products that contain at least two requested ingredients,
        // as requested for broader ingredient-list searches.
        $requiredHits = count($ingredients) <= 2 ? count($ingredients) : 2;

        return $hits >= $requiredHits;
    }

    /**
     * Product helper used for "product matches excluded ingredients".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Helper method for "ingredient text contains".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function ingredientTextContains(string $ingredientsText, string $ingredient): bool
    {
        $ingredientsText = strtolower(trim($ingredientsText));
        $ingredient = $this->normalizeKeyword($ingredient);

        foreach ($this->expandIngredientAliases($ingredient) as $alias) {
            if ($this->ingredientAliasMatches($ingredientsText, (string) $alias, $ingredient)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Helper method for "ingredient alias matches".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function ingredientAliasMatches(string $ingredientsText, string $alias, string $requestedIngredient): bool
    {
        $alias = strtolower(trim($alias));
        if ($alias === '' || $ingredientsText === '') {
            return false;
        }

        // Special case for direct "milk" requests. A label like "whey powder (from milk)"
        // should count for animal-derived checks via whey, but not for a direct catalog
        // search asking specifically for milk + sugar.
        if ($requestedIngredient === 'milk' && $alias === 'milk') {
            return preg_match('/(?<![a-z0-9])(?:milk\s+powder|milk\s+solids|whole\s+milk|skim(?:med)?\s+milk|full\s+cream\s+milk|milk\s+chocolate|condensed\s+milk|evaporated\s+milk|milk(?!\s*\)))(?![a-z0-9])/iu', $ingredientsText) === 1;
        }

        return preg_match('/(?<![a-z0-9])' . preg_quote($alias, '/') . '(?![a-z0-9])/iu', $ingredientsText) === 1;
    }

    /**
     * Product helper used for "product status value".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function productStatusValue(array $product): string
    {
        // Product type is the strongest halal/haram/mushbooh field in this project.
        // Some imported rows have status as "unknown" while type/decision contains
        // the real halal status, so type/decision must be checked before status.
        foreach (['type', 'decision', 'status'] as $key) {
            $status = $this->normalizeStatusValue((string) ($product[$key] ?? ''));
            if ($status !== '') {
                return $status;
            }
        }

        return '';
    }

    /**
     * Normalizes "status value" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeStatusValue(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);

        return match ($value) {
            'approved', 'approve', 'halal certified', 'halal', 'permissible', 'permitted', 'safe', 'muslim friendly', 'muslim-friendly' => 'halal',
            'haram', 'not halal', 'non halal', 'forbidden', 'prohibited' => 'haram',
            'mushbooh', 'mashbooh', 'doubtful', 'suspect', 'questionable' => 'mushbooh',
            'unknown', 'pending', 'decision pending', 'not found', 'unverified', 'needs review', 'needs verification' => 'unknown',
            'out of scope', 'non food', 'non-food', 'not food' => 'out_of_scope',
            default => '',
        };
    }

    /**
     * Helper method for "expand status aliases".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function expandStatusAliases(string $status): array
    {
        $status = $this->normalizeStatusValue($status);

        return match ($status) {
            'halal' => ['halal', 'approved', 'approve', 'halal certified', 'permissible', 'permitted', 'safe', 'muslim friendly', 'muslim-friendly'],
            'haram' => ['haram', 'not halal', 'non halal', 'forbidden', 'prohibited'],
            'mushbooh' => ['mushbooh', 'mashbooh', 'doubtful', 'suspect', 'questionable'],
            'unknown' => ['unknown', 'pending', 'decision pending', 'not found', 'unverified', 'needs review', 'needs verification'],
            'out_of_scope' => ['out of scope', 'non food', 'non-food', 'not food'],
            default => array_values(array_filter([$status])),
        };
    }

    /**
     * Helper method for "select best product name matches".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Scores or ranks data for "score product name array".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Helper method for "filter products by category intent".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function filterProductsByCategoryIntent(Collection $products, string $category): Collection
    {
        return $products->filter(function ($product) use ($category) {
            $categoryFields = [
                (string) ($product->category ?? ''),
                (string) ($product->main_category ?? ''),
                (string) ($product->main_category1 ?? ''),
                (string) ($product->main_category_1 ?? ''),
                (string) ($product->categories ?? ''),
            ];

            $identityFields = [
                (string) ($product->name ?? ''),
                (string) ($product->brand ?? ''),
                (string) ($product->description ?? ''),
                (string) ($product->notes ?? ''),
                (string) ($product->ingredients ?? ''),
            ];

            return $this->productTextMatchesCategory($categoryFields, $identityFields, $category);
        })->values();
    }
    /**
     * Keeps subtype words like soda/fizzy drink/soft drink in the search text.
     * Without this, a request for "soda from USA" becomes broad "drinks from USA"
     * and can return juice. Generic "drinks from USA" still clears query text.
     */
    protected function shouldPreserveSubtypeQueryText(string $rawQueryText, string $category): bool
    {
        $raw = strtolower(trim($rawQueryText));
        $category = $this->normalizeCategory($category);

        if ($category === 'drinks') {
            return preg_match('/\b(soda|soft\s+drinks?|fizzy\s+drinks?|pop|cola|coke|pepsi|sprite|energy\s+drink)\b/iu', $raw) === 1
                && preg_match('/\b(drinks?|beverages?|juices?)\b/iu', $raw) !== 1;
        }

        return false;
    }

    /**
     * Boolean helper that checks whether the current request or product matches "is strict category query".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function isStrictCategoryQuery(string $rawQueryText, string $category): bool
    {
        if ($category === '') {
            return false;
        }

        return (bool) preg_match('/\\b(suggest|show|list|give|find|available|have|fetch|bring|need|want)\\b/i', strtolower($rawQueryText))
            || (bool) preg_match('/\b(breads?|loaves|loaf|toast|biscuits?|cookies?|crackers?|wafers?|chocolates?|juices?|drinks?|snacks?|cakes?|cupcakes?|beverages?|candies?|sweets?|dairy|dairy\s+alternatives?|cheese|butter|yogurts?|yoghurts?|sauces?|condiments?|mayonnaise|mayonese|mayounese|mayo|ketchup|spices?|beef|meats?|chicken|poultry|household|cleaning|hand\s*washes?|handwash(?:es)?|hand\s*soap|soap|antiseptic|dettol|detol|carex|oils?|sweeteners?|bakery|pasta|pastas|noodles?|spaghetti|macaroni)\b/i', strtolower($rawQueryText));
    }
    /**
     * Boolean helper that checks whether the current request or product matches "is non ingredient check phrase".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function isNonIngredientCheckPhrase(string $value): bool
    {
        $value = strtolower(trim($value));

        return $value === ''
            || preg_match('/\b(animal\s*(?:derived|driven|based)?|derived|driven|substances?|inside|anything|any|any\s+of\s+them|these|those|products?|items?)\b/iu', $value) === 1;
    }

    /**
     * Helper method for "remove phrase from text".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function removePhraseFromText(string $text, string $phrase): string
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return $text;
        }

        return trim((string) preg_replace('/' . preg_quote($phrase, '/') . '/iu', ' ', $text));
    }

    /**
     * Helper method for "remove ingredients that are part of focus name".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function removeIngredientsThatArePartOfFocusName(array $ingredients, string $focusProductName): array
    {
        $focus = strtolower(trim($focusProductName));
        if ($focus === '') {
            return $ingredients;
        }

        return array_values(array_filter($ingredients, function ($ingredient) use ($focus): bool {
            $ingredient = strtolower(trim((string) $ingredient));
            if ($ingredient === '') {
                return false;
            }

            return ! preg_match('/(?<![a-z0-9])' . preg_quote($ingredient, '/') . '(?![a-z0-9])/iu', $focus);
        }));
    }

    /**
     * True when the user is browsing a category/list, so long query text should
     * not narrow the SQL search beyond explicit filters.
     */
    /**
     * Boolean helper that checks whether the current request or product matches "is category browse query".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function isCategoryBrowseQuery(string $rawQueryText): bool
    {
        $raw = strtolower(trim($rawQueryText));

        return (bool) preg_match('/\\b(show|list|give|find|suggest|recommend|available|have|fetch|bring|need|want)\\b/iu', $raw)
            || (bool) preg_match('/\b(breads?|loaves|loaf|toast|chocolates?|biscuits?|cookies?|crackers?|wafers?|cakes?|cupcakes?|juices?|drinks?|beverages?|snacks?|candies?|sweets?|beef|meats?|chicken|poultry|dairy|dairy\s+alternatives?|household|cleaning|hand\s*washes?|handwash(?:es)?|hand\s*soap|soap|antiseptic|dettol|detol|carex|oils?|sweeteners?|bakery|condiments?|sauces?|mayonnaise|mayonese|mayounese|mayo|ketchup|spices?|spicy|masala|seasonings?)\b/iu', $raw);
    }
    /**
     * Finds "focused product for meta" from database records or in-memory product data.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function findFocusedProductForMeta(string $name): ?array
    {
        $name = $this->normalizeText($name);
        if ($name === '') {
            return null;
        }

        $lookup = $this->findProductByName($name);
        $products = is_array($lookup['products'] ?? null) ? $lookup['products'] : [];

        return $products[0] ?? null;
    }

    /**
     * Keep the focused product visible, but preserve the category list.
     */
    /**
     * Helper method for "merge focus product into list".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function mergeFocusProductIntoList(Collection $products, array $focusProduct, int $limit): Collection
    {
        $key = $this->productUniqueKey($focusProduct);
        $existing = $products->filter(fn (array $product) => $this->productUniqueKey($product) !== $key)->values();

        return collect([$focusProduct])->merge($existing)->take($limit)->values();
    }

    /**
     * Product helper used for "product unique key".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function productUniqueKey(array $product): string
    {
        $id = trim((string) ($product['id'] ?? ''));
        if ($id !== '') {
            return 'id:' . $id;
        }

        $barcode = trim((string) ($product['barcode'] ?? ''));
        if ($barcode !== '') {
            return 'barcode:' . strtolower($barcode);
        }

        return 'name:' . strtolower(trim((string) ($product['name'] ?? '')));
    }


    /**
     * Removes duplicate imported rows before response formatting. Some CSV/Excel imports
     * store the same barcode in scientific notation, so name + origin + ingredients is
     * used as a secondary stable key.
     */
    protected function deduplicateProductArray(array $products): array
    {
        $seen = [];
        $unique = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $barcode = $this->normalizeBarcode((string) ($product['barcode'] ?? ''));
            $name = Str::lower($this->normalizeText((string) ($product['name'] ?? '')));
            $origin = Str::lower($this->normalizeText((string) ($product['origin'] ?? '')));
            $ingredients = Str::lower($this->normalizeText((string) ($product['ingredients'] ?? '')));

            $key = $barcode !== '' && strlen($barcode) >= 8
                ? 'barcode:' . $barcode
                : 'signature:' . md5($name . '|' . $origin . '|' . $ingredients);

            if ($name !== '' && $ingredients !== '') {
                $signatureKey = 'signature:' . md5($name . '|' . $origin . '|' . $ingredients);
                if (isset($seen[$signatureKey])) {
                    continue;
                }
                $seen[$signatureKey] = true;
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $product;
        }

        return array_values($unique);
    }

    // ─────────────────────────────────────────────────────────────
    // FORMAT / UTILITY
    // ─────────────────────────────────────────────────────────────

    /**
     * Helper method for "count token hits".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Helper method for "meaningful tokens".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function meaningfulTokens(string $text): array
    {
        $tokens = $this->tokenize($text);

        return array_values(array_filter($tokens, function ($token) {
            return mb_strlen($token) >= 3 && ! in_array($token, ['plain', 'soft', 'bakes', 'product', 'item'], true);
        }));
    }

    /**
     * Converts a Product model into the array shape used by the response layer.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function formatProduct(Product $product): array
    {
        $effectiveStatus = $this->productStatusValue([
            'type' => $product->type,
            'decision' => $product->decision,
            'status' => $product->status,
        ]);

        return [
            'id'            => $product->id,
            'name'          => $product->name,
            'barcode'       => $product->barcode,
            'brand'         => $product->brand,
            'origin'        => $product->origin,
            'category'      => $product->category,
            'main_category' => $product->main_category,
            'main_category1'=> $product->main_category1 ?? ($product->main_category_1 ?? null),
            'main_category_1'=> $product->main_category_1 ?? ($product->main_category1 ?? null),
            'description'   => $product->description,
            'ingredients'   => $product->ingredients,
            'allergens'     => $product->allergens,
            'notes'         => $product->notes,
            'image'         => $product->image,
            'decision'      => $product->decision,
            'status'        => $effectiveStatus !== '' ? $effectiveStatus : $product->status,
            'type'          => $product->type,
            'raw_status'    => $product->status,
            'is_kosher'     => (bool) ($product->is_kosher ?? false),
            'is_vegetarian' => (bool) ($product->is_vegetarian ?? false),
            'is_vegan'      => (bool) ($product->is_vegan ?? false),
            'is_gluten_free'=> (bool) ($product->is_gluten_free ?? false),
        ];
    }

    /**
     * Helper method for "not found".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Helper method for "compact".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function compact(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', '', $value) ?? $value;
        return trim($value);
    }

    /**
     * Normalizes "barcode" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeBarcode(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/u', '', $value) ?? '';
        return trim($value);
    }

    protected function barcodeLookupCandidates(string $value): array
    {
        $raw = trim($value);
        $compact = $this->normalizeBarcode($raw);
        $digitOnly = preg_replace('/\D+/', '', $raw) ?? '';
        $ocr = strtoupper(trim($raw));
        $ocr = strtr($ocr, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'S' => '5', 'B' => '8', 'G' => '6', 'Z' => '2']);
        $ocr = preg_replace('/[^A-Z0-9]+/u', '', $ocr) ?? '';

        return array_values(array_unique(array_filter([
            strtolower($raw),
            strtolower($compact),
            strtolower($digitOnly),
            strtolower($ocr),
        ], fn ($item) => trim((string) $item) !== '')));
    }

    /**
     * Normalizes product names and known typos before searching.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeProductNameInput(string $value): string
    {
        $value = strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
        if ($value === '') {
            return '';
        }

        $replacements = [
            '/(?<![a-z0-9])penut(?![a-z0-9])/iu' => 'peanut',
            '/(?<![a-z0-9])peanutt?(?![a-z0-9])/iu' => 'peanut',
            '/(?<![a-z0-9])pennut(?![a-z0-9])/iu' => 'peanut',
            '/(?<![a-z0-9])sprit(?![a-z0-9])/iu' => 'sprite',
            '/(?<![a-z0-9])suger(?![a-z0-9])/iu' => 'sugar',
            '/(?<![a-z0-9])solt(?![a-z0-9])/iu' => 'salt',
            '/(?<![a-z0-9])detol(?![a-z0-9])/iu' => 'dettol',
            '/(?<![a-z0-9])dairymilk(?![a-z0-9])/iu' => 'dairy milk',
            '/(?<![a-z0-9])mayonese(?![a-z0-9])/iu' => 'mayonnaise',
            '/(?<![a-z0-9])mayounese(?![a-z0-9])/iu' => 'mayonnaise',
            '/(?<![a-z0-9])mayo(?![a-z0-9])/iu' => 'mayonnaise',
            '/(?<![a-z0-9])handwashes(?![a-z0-9])/iu' => 'hand wash',
            '/(?<![a-z0-9])handwash(?:es)?(?![a-z0-9])/iu' => 'hand wash',
            '/(?<![a-z0-9])ingridents?(?![a-z0-9])/iu' => 'ingredients',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Normalizes "text" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        // Repair accidental spaces inserted inside high-value query tokens.
        // Example: "brand p roducts" should still route as "brand products".
        $repairs = [
            '/(?<![a-z0-9])p\s+roducts?(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])pro\s+ducts?(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])prod\s+ucts?(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])i\s+tems?(?![a-z0-9])/iu' => 'items',
            '/(?<![a-z0-9])br\s+and(?![a-z0-9])/iu' => 'brand',
            '/(?<![a-z0-9])groc\s+ery(?![a-z0-9])/iu' => 'grocery',
            '/(?<![a-z0-9])wool\s*worths?(?![a-z0-9])/iu' => 'woolworths',
        ];

        foreach ($repairs as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Normalizes "string array" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Normalizes "origin array" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Cleans "noisy search text" before matching, resolving, or replying.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function cleanNoisySearchText(string $queryText, bool $ingredientFilter = false): string
    {
        $queryText = trim($queryText);
        if ($queryText === '') {
            return '';
        }

        $noise = ['show', 'products', 'product', 'that', 'which', 'contain', 'contains', 'containing', 'with', 'without', 'do', 'does', 'not', 'but', 'no', 'give', 'me', 'list', 'items', 'item', 'some', 'any', 'options', 'option', 'available', 'from', 'and', 'or', 'are', 'these', 'healthy', 'health', 'compare'];
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
    /**
     * Extracts "ingredient filters from query" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractIngredientFiltersFromQuery(string $query, bool $exclude): array
    {
        $query = strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $pattern = $exclude
            ? '/\b(?:without|excluding|exclude|avoid|free from|no|do\s+not\s+contain|does\s+not\s+contain|don\'t\s+contain|dont\s+contain|not\s+contain|not\s+containing|must\s+not\s+have|should\s+not\s+contain|should\s+not\s+have)\s+(.+?)(?=\s+(?:but|without|avoid|exclude|excluding|free\s+from|from|made in|inside|in it|in them|for me|please|that are|which are|category|products?$)|[.?!;]|$)/iu'
            : '/\b(?:with|contain|contains|containing|include|includes|including|have|has|having|must\s+(?:be\s+)?having|rich in|high in)\s+(.+?)(?=\s+(?:but|without|avoid|exclude|excluding|free\s+from|from|made in|inside|in it|in them|for me|please|that are|which are|category|products?$)|[.?!;]|$)/iu';

        $found = [];
        $dependentCheckOnly = preg_match('/\b(?:any\s+of\s+)?(?:them|these|those|the\s+products?|the\s+items?|results?)\b[^.?!;]{0,120}\b(?:animal|derived|driven|gelatin|gelatine|alcohol|alcoholic|pork|carmine|rennet)\b/iu', $query) === 1;
        $ingredientParseText = $dependentCheckOnly
            ? (preg_replace('/(?:,?\s*(?:and\s+also|also|plus|and)?\s*(?:tell|check|show|explain)\s+(?:me\s+)?(?:if|whether)\s+)?(?:any\s+of\s+)?(?:them|these|those|the\s+products?|the\s+items?|results?)\b.*$/iu', '', $query) ?? $query)
            : $query;

        if (! $exclude && preg_match('/\b(?:spicy|spiced|spice|spices|masala|seasoned|seasoning|seasonings|curry\s+flavou?r|hot\s+flavou?r|chilli?|chili|paprika|pepper)\b/iu', $ingredientParseText) === 1) {
            $found[] = 'spices';
        }

        if (preg_match_all($pattern, $ingredientParseText, $matches)) {
            foreach ($matches[1] as $group) {
                foreach ($this->splitIngredientTerms($group) as $term) {
                    if ($term !== '') {
                        $found[] = $term;
                    }
                }
            }
        }

        // Additive recovery for natural catalog wording such as
        // "items that include rice powder" and "something that has cocoa inside".
        if (! $exclude && preg_match_all(
            '/\b(?:products?|items?|options?|things?|something|anything|foods?|snacks?|chocolates?|biscuits?|drinks?|juices?)\b[^.?!;]{0,80}\b(?:that\s+)?(?:contain|contains|containing|with|having|have|has|include|includes|including|inside|rich\s+in|high\s+in)\s+(.+?)(?=\s+(?:but|without|avoid|exclude|excluding|free\s+from|from|made\s+in|inside|in\s+it|in\s+them|for\s+me|please|that\s+are|which\s+are|category|products?$)|[.?!;]|$)/iu',
            $ingredientParseText,
            $catalogIngredientMatches
        )) {
            foreach ($catalogIngredientMatches[1] as $group) {
                foreach ($this->splitIngredientTerms($group) as $term) {
                    if ($term !== '') {
                        $found[] = $term;
                    }
                }
            }
        }

        $known = [
            'lemon juice', 'lime juice', 'vegetable oil', 'vegitable oil', 'palm oil', 'olive oil', 'sunflower oil', 'canola oil', 'coconut oil',
            'mayonnaise', 'mayonese', 'mayounese', 'mayo', 'ketchup', 'soy sauce',
            'folic acid', 'folate', 'vitamin b', 'vitamin b1', 'vitamin b2', 'vitamin b3', 'vitamin b6', 'vitamin b12',
            'thiamine', 'riboflavin', 'niacin', 'cyanocobalamin', 'pyridoxine',
            'wheat flour', 'cocoa butter', 'whey powder', 'milk solids', 'carbonated water', 'caramel color',
            'phosphoric acid', 'aspartame', 'carbohydrate', 'carbohydrates', 'protein', 'fiber',
            'rice', 'rice powder', 'rice starch', 'rice flour', 'corn', 'corn flour', 'corn starch', 'maize',
            'sugar', 'salt', 'sodium', 'sodium chloride', 'water', 'milk', 'whey', 'casein', 'soy', 'cocoa', 'glucose', 'fructose',
            'preservative', 'preservatives', 'additive', 'additives', 'gelatin', 'gelatine', 'alcohol', 'alcoholic', 'ethanol',
            'spice', 'spices', 'spicy', 'masala', 'seasoning', 'seasonings', 'curry', 'curry spice', 'curry spice mix', 'chili', 'chilli', 'red chilli', 'paprika', 'pepper', 'black pepper', 'garlic powder', 'onion powder', 'turmeric', 'ginger',
            'pork', 'lard', 'carmine', 'rennet', 'enzymes', 'animal fat', 'animal derived', 'animal-driven', 'animal driven', 'animal based', 'e471'
        ];

        foreach ($known as $term) {
            if (! str_contains($ingredientParseText, $term)) {
                continue;
            }

            $isExcluded = preg_match('/\b(?:without|excluding|exclude|avoid|free from|no|do\s+not\s+contain|does\s+not\s+contain|don\'t\s+contain|dont\s+contain|not\s+contain|not\s+containing|must\s+not\s+have|should\s+not\s+contain|should\s+not\s+have)\b[^.?!;]*\b' . preg_quote($term, '/') . '\b/iu', $ingredientParseText) === 1;
            $isDependentCheckTerm = preg_match('/\b(?:animal\s*(?:derived|driven|based)?|animal-derived|derived|gelatin|gelatine|alcohol|alcoholic|pork|carmine|rennet)\b/iu', $term) === 1;

            if (!$exclude && !$isExcluded && $isDependentCheckTerm && $dependentCheckOnly) {
                continue;
            }

            if (($exclude && $isExcluded) || (! $exclude && ! $isExcluded)) {
                $found[] = $term;
            }
        }

        $found = $this->expandBroadIngredientConcepts(array_map(fn ($item) => $this->normalizeKeyword($item), $found));

        return array_values(array_unique(array_filter($found, fn ($item) => ! $this->isNonIngredientCheckPhrase((string) $item))));
    }

    /**
     * Helper method for "split ingredient terms".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function splitIngredientTerms(string $value): array
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\b(?:that|which)\s+(?:has|have|contains?|includes?)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\b(?:deficiency|deficient|body|nutrients?|nutrition|nutritious|inside|ingredient|ingredients|please|for me|in it|in them|products?|items?|options?|things?|those|that|which)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\b(?:and\s+also\s+)?(?:tell|check|show|explain)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:if|whether)\s+any\s+of\s+(?:them|these|those)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:but|and)?\s*(?:is\s+)?not\s+(?:marked\s+)?haram\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:but|and)?\s*(?:avoid|without|excluding|exclude|free\s+from|do\s+not\s+contain|does\s+not\s+contain|don\'t\s+contain|dont\s+contain|not\s+containing|not\s+contain|must\s+not\s+have|should\s+not\s+contain|should\s+not\s+have|no)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:halal|haram|mushbooh|mashbooh|unknown|pending)\b.*$/iu', '', $value) ?? $value;
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
                $normalized = $this->normalizeKeyword($part);
                if ($normalized === 'animal derived') {
                    $terms[] = $normalized;
                } elseif (! $this->isNonIngredientCheckPhrase($part)) {
                    $terms[] = $normalized;
                }
            }
        }

        return $terms;
    }

    /**
     * Helper method for "prefer reliable category from query".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function preferReliableCategoryFromQuery(string $rawQuery, string $category): string
    {
        $raw = strtolower(trim($rawQuery));
        $category = strtolower(trim($category));

        if (in_array($category, ['juice', 'juices', 'drinks', 'drink'], true)
            && preg_match('/\b(?:contain|contains|containing|with|include|includes|having|has|have)\b[^.?!,;]*\bjuice\b/iu', $raw)
            && ! preg_match('/\b(drinks?|beverages?|something\s+to\s+drink|to\s+drink|drinkable|soda|soft\s+drink|energy\s+drink)\b/iu', $raw)) {
            return '';
        }

        // Prefer explicit category words in the raw user message over a broad AI guess.
        // Example: Gemini may return "meats" for "beef products", but the user asked
        // for beef only, so this must remain a beef query.
        if (preg_match('/\bbeef\b/iu', $raw)) {
            return 'beef';
        }
        if (preg_match('/\b(chicken|poultry)\b/iu', $raw)) {
            return 'chicken';
        }
        if (preg_match('/\b(dairy\s+products?|dairy\s+items?|cheese|cheeses|butter|yogurts?|yoghurts?)\b/iu', $raw)) {
            return 'dairy';
        }
        if (preg_match('/\b(dairy\s+alternatives?|plant\s*based\s+milk|non\s*dairy|dairy\s*free|almond\s+milk|soy\s+milk|soya\s+milk|oat\s+milk|rice\s+milk|coconut\s+milk)\b/iu', $raw)) {
            return 'dairy_alternatives';
        }
        if (preg_match('/\b(household|clean(?:ing|er)?|washroom|bathroom|kitchen|hand\s*washes?|handwash(?:es)?|hand\s*soap|soap|antiseptic|disinfectant|detergent|bleach|dettol|detol|carex)\b/iu', $raw)) {
            return 'household';
        }
        $palmOilIngredientQuery = preg_match('/\b(?:with|contain|contains|containing|has|have|having|include|includes|including)\b[^.?!;]{0,100}\bpalm\s+oil\b|\bpalm\s+oil\b[^.?!;]{0,100}\b(?:inside|in\s+it|as\s+ingredients?|ingredients?)\b/iu', $raw) === 1;
        $spiceIngredientQuery = preg_match('/\b(?:with|contain|contains|containing|has|have|having|include|includes|including)\b[^.?!;]{0,140}\b(?:spicy|spices?|masala|seasonings?|curry|chilli?|chili|paprika|pepper)\b|\b(?:spicy|spices?|masala|seasonings?|curry|chilli?|chili|paprika|pepper)\b[^.?!;]{0,140}\b(?:inside|inside\s+ingredients?|in\s+ingredients?|as\s+ingredients?|ingredients?)\b/iu', $raw) === 1;
        if (preg_match('/\b(oils?|cooking\s+oil|edible\s+oil|olive\s+oil|sunflower\s+oil|canola\s+oil|palm\s+oil|coconut\s+oil)\b/iu', $raw)
            && preg_match('/\b(show|list|give|find|need|want|products?|items?)\b/iu', $raw)
            && (! $palmOilIngredientQuery || preg_match('/\b(?:show|list|find|need|want|give)\b[^.?!;]{0,60}\b(?:oils|cooking\s+oil|edible\s+oil|olive\s+oil|sunflower\s+oil|canola\s+oil|coconut\s+oil)\b/iu', $raw))) {
            return 'oils';
        }
        if (preg_match('/\b(sweeteners?|sweetner|sweetners|sugar\s+products?|honey|syrup|stevia|aspartame|sucralose)\b/iu', $raw)) {
            return 'sweeteners';
        }
        if (preg_match('/\b(condiments?|sauces?|mayonnaise|mayonese|mayounese|mayo|ketchup|soy\s+sauce)\b/iu', $raw)) {
            return 'sauces';
        }

        if ($category === 'spices' && $spiceIngredientQuery) {
            return '';
        }

        if ($category !== '') {
            return $this->normalizeCategory($category);
        }

        if (preg_match('/\b(pasta|pastas|noodles?|instant\s+noodles?|spaghetti|macaroni)\b/iu', $raw)) {
            return 'pasta';
        }

        if (preg_match('/\bjuices?\b|\b(?:apple|orange|mango|fruit)\s+juice\b/iu', $raw)) {
            return 'juices';
        }

        if (preg_match('/\b(drinks?|beverages?|something\s+to\s+drink|to\s+drink|drinkable|soda|soft\s+drink|fizzy\s+drink|energy\s+drink|pop)\b/iu', $raw)) {
            return 'drinks';
        }
        if (preg_match('/\b(breads?|loaves|loaf|toast)\b/iu', $raw)) {
            return 'bread';
        }
        if (preg_match('/\b(cakes?|cupcakes?)\b/iu', $raw)) {
            return 'cakes';
        }
        if (preg_match('/\b(biscuits?|cookies?|crackers?|wafers?)\b/iu', $raw)) {
            return 'biscuits';
        }
        if (preg_match('/\b(chocolates?|confectionery)\b/iu', $raw)) {
            return 'chocolates';
        }
        if (preg_match('/\b(snacks?|chips|crisps)\b/iu', $raw)) {
            return 'snacks';
        }
        if (preg_match('/\b(meat|meats|mutton|lamb|sausage|sausages)\b/iu', $raw)) {
            return 'meats';
        }
        if (preg_match('/\b(spices?|masala|seasoning|seasonings|mixes?)\b/iu', $raw)) {
            return 'spices';
        }
        if (preg_match('/\b(bakery|pastry|baked)\b/iu', $raw)) {
            return 'bakery';
        }

        return '';
    }

    /**
     * Extracts "origin filters from query" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractOriginFiltersFromQuery(string $query): array
    {
        $rawQuery = Str::lower($this->normalizeText($query));
        $query = str_replace(['_', '-'], ' ', $rawQuery);
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        if ($query === '') {
            return [];
        }

        $found = [];

        // Generic DB-driven origin extraction. Any phrase after "from", "made in",
        // "origin", or "country" is allowed, so future DB origins do not need code edits.
        foreach ($this->extractLooseOriginPhrases($rawQuery) as $candidate) {
            $normalizedCandidate = $this->normalizeOrigin($candidate);
            if ($normalizedCandidate !== '') {
                $found[] = $normalizedCandidate;
            }
        }

        // Keep aliases only for common shortcuts/demonyms. Normal country/origin names
        // are matched generically by extractLooseOriginPhrases() + originComparableForms().
        $countryMap = method_exists($this, 'originAliasMap') ? $this->originAliasMap() : [];
        foreach ($countryMap as $normalized => $aliases) {
            foreach ((array) $aliases as $alias) {
                $alias = Str::lower((string) $alias);
                $alias = str_replace(['_', '-'], ' ', $alias);
                $alias = trim((string) preg_replace('/\s+/u', ' ', $alias));
                if ($alias === '') {
                    continue;
                }

                if (preg_match('/(?<![\pL\pN])' . preg_quote($alias, '/') . '(?![\pL\pN])/iu', $query) === 1) {
                    $found[] = (string) $normalized;
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($found)));
    }

    /**
     * Extracts free-form origin phrase after natural language markers.
     * Examples: "from Czech-republic", "from Democratic-republic-of-the-congo",
     * "made in South-africa", "origin Netherlands".
     */
    protected function extractLooseOriginPhrases(string $query): array
    {
        $query = Str::lower($this->normalizeText($query));
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));
        if ($query === '') {
            return [];
        }

        $phrases = [];
        $patterns = [
            '/\bfrom\s+([\pL\pN][\pL\pN\s._\-\'’]{1,90}?)(?=\s*(?:$|[,.?!;]|\b(?:only|with|without|that|which|where|and\s+(?:show|list|find|check|tell|give|also|from)|but|like|for\s+(?:halal|haram|ingredients?|barcode|alcohol|gelatin|gelatine))\b))/iu',
            '/\b(?:made\s+in|origin(?:\s+is|\s+from)?|country(?:\s+is|\s+from)?)\s+([\pL\pN][\pL\pN\s._\-\'’]{1,90}?)(?=\s*(?:$|[,.?!;]|\b(?:only|with|without|that|which|where|and\s+(?:show|list|find|check|tell|give|also|from)|but|like|for\s+(?:halal|haram|ingredients?|barcode|alcohol|gelatin|gelatine))\b))/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $query, $matches)) {
                foreach ($matches[1] ?? [] as $match) {
                    $candidate = $this->cleanLooseOriginPhrase((string) $match);
                    if ($candidate !== '') {
                        $phrases[] = $candidate;
                    }
                }
            }
        }

        // Hyphenated future origins may be typed before the noun too:
        // "Czech-republic products", "Democratic-republic-of-the-congo items".
        if (preg_match('/^([\pL\pN]+(?:[-_][\pL\pN]+)+)\s+(?:products?|items?|options?)$/iu', $query, $m) === 1) {
            $candidate = $this->cleanLooseOriginPhrase((string) $m[1]);
            if ($candidate !== '') {
                $phrases[] = $candidate;
            }
        } elseif (preg_match('/^[\pL\pN]+(?:[-_][\pL\pN]+)+$/iu', $query) === 1) {
            $candidate = $this->cleanLooseOriginPhrase($query);
            if ($candidate !== '') {
                $phrases[] = $candidate;
            }
        }

        return array_values(array_unique($phrases));
    }

    protected function cleanLooseOriginPhrase(string $value): string
    {
        $value = Str::lower($this->normalizeText($value));
        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\b(?:only|products?|items?|foods?|options?|available|origin|country|made)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value, " \t\n\r\0\x0B,.;:!?؟");
    }

    /**
     * Extracts "brand hint from query" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function looksLikeNutritionQueryText(string $query): bool
    {
        $lower = strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeText($query))));

        if ($lower === '') {
            return false;
        }

        return preg_match('/\b(?:nutrients?|nutrition|nutritious|healthy|vitamins?|vitamin\s*[a-z0-9]*|minerals?|protein|fiber|fibre|folic\s+acid|folate|iron|calcium|zinc|after\s+(?:gym|workout)|gym\s+session|workout|low\s+energy|rich\s+in|high\s+in|must\s+be\s+having)\b/iu', $lower) === 1;
    }

    protected function isBlockedBrandToken(string $brand): bool
    {
        $brand = strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeText($brand))));

        if ($brand === '') {
            return false;
        }

        $blocked = [
            'you', 'your', 'yours', 'me', 'my', 'mine', 'i', 'we', 'our', 'ours',
            'can you', 'could you', 'would you', 'please', 'pls', 'some', 'any', 'all',
            'item', 'items', 'product', 'products', 'option', 'options', 'thing', 'things',
            'nutrient', 'nutrients', 'nutrition', 'vitamin', 'vitamins', 'mineral', 'minerals',
            'healthy', 'hungry', 'gym', 'workout', 'session', 'database',
        ];

        return in_array($brand, $blocked, true);
    }

    protected function brandLooksLikeOriginFilter(string $brand, array $origins): bool
    {
        $brandCompact = $this->compactOriginCompareText($brand);
        if ($brandCompact === '' || empty($origins)) {
            return false;
        }

        foreach ($origins as $origin) {
            foreach ($this->originComparableForms((string) $origin) as $form) {
                $originCompact = $this->compactOriginCompareText((string) $form);
                if ($originCompact !== '' && ($brandCompact === $originCompact || str_contains($originCompact, $brandCompact) || str_contains($brandCompact, $originCompact))) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function compactOriginCompareText(string $value): string
    {
        return preg_replace('/[^\pL\pN]+/u', '', Str::lower(trim((string) $value))) ?? '';
    }

    protected function extractBrandHintFromQuery(string $query): ?string
    {
        $lower = strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeText($query))));
        if ($lower === '') {
            return null;
        }

        $patterns = [
            '/\b(?:fan\s+of|huge\s+fan\s+of|love|like|prefer|interested\s+in|looking\s+for)\s+([a-z0-9][a-z0-9\s&\-\'’]{1,60})\s+(?:brand\s+)?(?:products?|items?)\b/iu',
            '/\b([a-z0-9][a-z0-9\s&\-\'’]{1,60})\s+(?:brand\s+)?(?:products?|items?)\b/iu',
            '/\b(?:show|list|give|find|search|fetch|bring)\s+(?:me\s+)?(?:all\s+|some\s+|available\s+)?(?:the\s+)?(?:halal\s+|haram\s+|mushbooh\s+|unknown\s+|safe\s+|not[-\s]*haram\s+)?(?:products?|items?)\s*(?:of|by|from)\s*([a-z0-9][a-z0-9\s&\-\'’]{1,60})(?:\b|$)/iu',
            '/\b(?:show|list|give|find|search|fetch|bring)\s+(?:me\s+)?(?:all\s+|some\s+|available\s+)?(?:the\s+)?(?:halal\s+|haram\s+|mushbooh\s+|unknown\s+|safe\s+|not[-\s]*haram\s+)?(?:products?|items?)\s+of([a-z0-9][a-z0-9\s&\-\'’]{1,60})(?:\b|$)/iu',
            '/\b(?:products?|items?)\s+(?:of|by|from)\s*([a-z0-9][a-z0-9\s&\-\'’]{1,60})(?:\b|$)/iu',
            '/^(?:all\s+|some\s+|available\s+)?([a-z0-9][a-z0-9\s&\-\'’]{1,60})\s+(?:brand\s+)?(?:products?|items?)$/iu',
            '/^([a-z0-9][a-z0-9\s&\-\'’]{1,60})\s+brand$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower, $matches) === 1) {
                $brand = $this->cleanupBrandHintFromQuery((string) ($matches[1] ?? ''));
                if ($brand !== null) {
                    return $brand;
                }
            }
        }

        $map = [
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
            'dettol' => '/(?<![a-z0-9])detto?l(?![a-z0-9])/iu',
            'carex' => '/(?<![a-z0-9])carex(?![a-z0-9])/iu',
            'sprite' => '/(?<![a-z0-9])sprit(?:e)?(?![a-z0-9])/iu',
            'dairy milk' => '/(?<![a-z0-9])(?:dairy\s*milk|dairymilk)(?![a-z0-9])/iu',
            'kinder' => '/(?<![a-z0-9])kinder(?![a-z0-9])/iu',
            'peanut butter' => '/(?<![a-z0-9])pe?nut\s+butter(?![a-z0-9])/iu',
        ];

        foreach ($map as $brand => $pattern) {
            if (preg_match($pattern, $lower) === 1 && $this->isBrandCatalogQuery($lower)) {
                return $brand;
            }
        }

        return null;
    }

    protected function isBrandCatalogQuery(string $query): bool
    {
        $lower = strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeText($query))));

        return $lower !== ''
            && preg_match('/\b(?:brand\s+(?:products?|items?)|(?:products?|items?)\s*(?:of|by|from)|(?:products?|items?)\s+of[a-z0-9]|all\s+[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)|show\s+(?:me\s+)?(?:all\s+)?[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)|(?:fan\s+of|huge\s+fan\s+of|love|like|prefer|interested\s+in|looking\s+for)\s+[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)|[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)\s+(?:are\s+|is\s+)?(?:halal|haram|mushbooh|unknown|safe|available|show|list|give|find|search))\b/iu', $lower) === 1;
    }

    protected function cleanupBrandHintFromQuery(string $candidate): ?string
    {
        $candidate = strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeText($candidate))));
        $candidate = preg_replace('/^.*\b(?:fan\s+of|huge\s+fan\s+of|love|like|prefer|interested\s+in|looking\s+for)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:so\s+that|because|for\s+my|to\s+add|add\s+it|add\s+them|grocery\s+list|groccry\s+list).*$/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:its|their|all\s+the|all|some|available|brand|brands|product|products|items|item|show|list|give|find|search|fetch|bring|me|of|by|from|the|a|an|is|are|do|does|can|could|would)\b/iu', ' ', $candidate) ?? $candidate;
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));
        $candidate = trim($candidate, " \t\n\r\0\x0B,.;:!?&");

        if ($candidate === '' || mb_strlen($candidate) < 2) {
            return null;
        }

        $blocked = [
            'you', 'your', 'yours', 'me', 'my', 'mine', 'i', 'we', 'our', 'ours',
            'can you', 'could you', 'would you', 'please', 'pls', 'some', 'any', 'all',
            'item', 'items', 'product', 'products', 'option', 'options', 'thing', 'things',
            'nutrient', 'nutrients', 'nutrition', 'vitamin', 'vitamins', 'mineral', 'minerals',
            'healthy', 'hungry', 'gym', 'workout', 'session', 'database',
            'halal', 'haram', 'mushbooh', 'unknown', 'safe', 'muslim friendly',
            'grocery', 'groceries', 'groccry', 'shopping', 'store', 'supermarket', 'food',
            'drink', 'drinks', 'beverage', 'beverages', 'snack', 'snacks', 'chips', 'crisps',
            'biscuits', 'cookies', 'cakes', 'chocolates', 'chocolate', 'candy', 'candies', 'sweets',
            'pasta', 'noodles', 'spaghetti', 'sauces', 'spices', 'pantry', 'household',
            'usa', 'us', 'uk', 'pakistan', 'australia', 'italy', 'india', 'spain', 'france',
        ];

        return in_array($candidate, $blocked, true) ? null : $candidate;
    }

    /**
     * Normalizes "brand" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeBrand(string $value): string
    {
        $value = str_replace('-', ' ', Str::lower($this->normalizeText($value)));
        $value = preg_replace('/(?<![a-z0-9])detol(?![a-z0-9])/iu', 'dettol', $value) ?? $value;
        $value = preg_replace('/(?<![a-z0-9])dairymilk(?![a-z0-9])/iu', 'dairy milk', $value) ?? $value;
        $value = preg_replace('/(?<![a-z0-9])penut(?![a-z0-9])/iu', 'peanut', $value) ?? $value;
        $value = preg_replace('/(?<![a-z0-9])(?:items?\s+)?in\s+woolworths?(?![a-z0-9])/iu', 'woolworths', $value) ?? $value;
        $value = preg_replace('/(?<![a-z0-9])wool\s*worths?(?![a-z0-9])/iu', 'woolworths', $value) ?? $value;
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Normalizes "origin" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeOrigin(string $value): string
    {
        $value = Str::lower($this->normalizeText($value));

        return match ($value) {
            'america', 'american', 'united states', 'us', 'u.s.'     => 'usa',
            'newzealand', 'new zealand', 'nz', 'n.z.', 'kiwi'          => 'new zealand',
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
            'australian', 'austrailian', 'austrelian', 'austrelia', 'autrelia', 'asutrailia', 'austrailia' => 'australia',
            'indonesian'                                               => 'indonesia',
            'thai'                                                     => 'thailand',
            'indian'                                                   => 'india',
            'chinese'                                                  => 'china',
            'japanese'                                                 => 'japan',
            'pakistani'                                                => 'pakistan',
            'moroccan'                                                 => 'morocco',
            default                                                    => $value,
        };
    }

    /**
     * Normalizes category words and maps synonyms to stable internal categories.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeCategory(string $value): string
    {
        $value = Str::lower($this->normalizeText($value));

        $map = [
            'juice'         => 'juices',
            'juices'        => 'juices',
            'drink'         => 'drinks',
            'drinks'        => 'drinks',
            'beverage'      => 'drinks',
            'beverages'     => 'drinks',
            'soda'          => 'drinks',
            'soft drink'    => 'drinks',
            'fizzy drink'   => 'drinks',
            'fizzy drinks'  => 'drinks',
            'pop'           => 'drinks',
            'energy drink'  => 'drinks',
            'household'     => 'household',
            'household products' => 'household',
            'cleaning'      => 'household',
            'cleaner'       => 'household',
            'bathroom'      => 'household',
            'washroom'      => 'household',
            'loo'           => 'household',
            'toilet'        => 'household',
            'kitchen'       => 'household',
            'hand wash'     => 'household',
            'handwash'      => 'household',
            'handwashes'    => 'household',
            'hand soap'     => 'household',
            'soap'          => 'household',
            'antiseptic'    => 'household',
            'disinfectant'  => 'household',
            'detergent'     => 'household',
            'oil'           => 'oils',
            'oils'          => 'oils',
            'cooking oil'   => 'oils',
            'edible oil'    => 'oils',
            'sweetener'     => 'sweeteners',
            'sweeteners'    => 'sweeteners',
            'sweetner'      => 'sweeteners',
            'sweetners'     => 'sweeteners',
            'dairy alternative' => 'dairy_alternatives',
            'dairy alternatives' => 'dairy_alternatives',
            'milk alternative' => 'dairy_alternatives',
            'milk alternatives' => 'dairy_alternatives',
            'plant based milk' => 'dairy_alternatives',
            'non dairy'     => 'dairy_alternatives',
            'non-dairy'     => 'dairy_alternatives',
            'dairy free'    => 'dairy_alternatives',
            'chocolate'     => 'chocolates',
            'chocolates'    => 'chocolates',
            'cocoa'         => 'chocolates',
            'biscuit'       => 'biscuits',
            'biscuits'      => 'biscuits',
            'cookie'        => 'biscuits',
            'cookies'       => 'biscuits',
            'cracker'       => 'biscuits',
            'crackers'      => 'biscuits',
            'wafer'         => 'biscuits',
            'wafers'        => 'biscuits',
            'bread'         => 'bread',
            'breads'        => 'bread',
            'loaf'          => 'bread',
            'loaves'        => 'bread',
            'toast'         => 'bread',
            'pasta'         => 'pasta',
            'pastas'        => 'pasta',
            'noodle'        => 'pasta',
            'noodles'       => 'pasta',
            'instant noodles' => 'pasta',
            'spaghetti'     => 'pasta',
            'macaroni'      => 'pasta',
            'snack'         => 'snacks',
            'chips'         => 'snacks',
            'crisps'        => 'snacks',
            'candy'         => 'candies',
            'candies'       => 'candies',
            'sweet'         => 'candies',
            'sweets'        => 'candies',
            'gummy'         => 'candies',
            'gummies'       => 'candies',
            'beef'          => 'beef',
            'beef products' => 'beef',
            'beef items'    => 'beef',
            'meat'          => 'meats',
            'meats'         => 'meats',
            'chicken'       => 'chicken',
            'chicken products' => 'chicken',
            'poultry'       => 'chicken',
            'mutton'        => 'meats',
            'lamb'          => 'meats',
            'sausage'       => 'meats',
            'sausages'      => 'meats',
            'mayonnaise'    => 'sauces',
            'mayonese'      => 'sauces',
            'mayounese'     => 'sauces',
            'mayo'          => 'sauces',
            'ketchup'       => 'sauces',
            'sauce'         => 'sauces',
            'condiment'     => 'sauces',
            'condiments'    => 'sauces',
            'cake'          => 'cakes',
            'cakes'         => 'cakes',
            'cupcake'       => 'cakes',
            'cupcakes'      => 'cakes',
            'breakfast'     => 'breakfast',
            'cereal'        => 'breakfast',
            'cereals'       => 'breakfast',
            'oats'          => 'breakfast',
            'granola'       => 'breakfast',
            'tea'           => 'tea',
            'teas'          => 'tea',
            'tea bag'       => 'tea',
            'tea bags'      => 'tea',
            'green tea'     => 'tea',
            'black tea'     => 'tea',
            'bakery'        => 'bakery',
            'pastry'        => 'bakery',
            'baked'         => 'bakery',
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
    /**
     * Normalizes "keyword" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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
            'mayonese'      => 'mayonnaise',
            'mayounese'     => 'mayonnaise',
            'mayo'          => 'mayonnaise',
            'folate'        => 'folic acid',
            'vitamin-b'     => 'vitamin b',
            'vit b'         => 'vitamin b',
            'vit b1'        => 'vitamin b1',
            'vit b2'        => 'vitamin b2',
            'vit b3'        => 'vitamin b3',
            'vit b6'        => 'vitamin b6',
            'vit b12'       => 'vitamin b12',
            'thiamin'       => 'thiamine',
            'niacinamide'   => 'niacin',
            'riboflavine'   => 'riboflavin',
            'penut'         => 'peanut',
            'pennut'        => 'peanut',
            'peanutt'       => 'peanut',
            'suger'         => 'sugar',
            'solt'          => 'salt',
            'gelatine'      => 'gelatin',
            'alcoholic'     => 'alcohol',
            'ethanol'       => 'alcohol',
            'sodium'        => 'salt',
            'sodium chloride' => 'salt',
            'animal-driven' => 'animal derived',
            'animal driven' => 'animal derived',
            'animal based'  => 'animal derived',
            'animal-based'  => 'animal derived',
            'animal ingredients' => 'animal derived',
            'animal ingredient'  => 'animal derived',
            'animal-derived ingredients' => 'animal derived',
            'animal derived ingredients' => 'animal derived',
            'spicy'         => 'spices',
            'spiced'        => 'spices',
            'spice'         => 'spices',
            'pantry'        => 'pantry',
            'breakfast'     => 'breakfast',
            'cereal'        => 'breakfast',
            'cereals'       => 'breakfast',
            'tea'           => 'tea',
            'tea bag'       => 'tea',
            'tea bags'      => 'tea',
            'green tea'     => 'tea',
            'black tea'     => 'tea',
            'canned goods'  => 'canned_goods',
            'canned'        => 'canned_goods',
            'masala'        => 'spices',
            'seasoning'     => 'spices',
            'seasonings'    => 'spices',
            'curry spice'   => 'spices',
            'curry spice mix' => 'spices',
            'red chilli'    => 'chilli',
            'chili'         => 'chilli',
            'black pepper'  => 'pepper',
        ];

        return $map[$word] ?? $word;
    }

    /**
     * FIX: Normalize an array of status values.
     */
    /**
     * Normalizes "status array" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeDietArray(mixed $value): array
    {
        if (! is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        $normalized = [];
        foreach ($value as $item) {
            $diet = $this->normalizeDietValue((string) $item);
            if ($diet !== '') {
                $normalized[] = $diet;
            }
        }

        return array_values(array_unique($normalized));
    }

    protected function normalizeDietValue(string $value): string
    {
        $value = strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeText($value))));
        $value = str_replace(['-', ' '], '_', $value);

        return match ($value) {
            'veg', 'vegetarian' => 'vegetarian',
            'vegan' => 'vegan',
            'kosher' => 'kosher',
            'glutenfree', 'gluten_free' => 'gluten_free',
            default => '',
        };
    }

    protected function extractDietIncludeFromQuery(string $query): array
    {
        $lower = strtolower($this->normalizeText($query));
        $include = [];

        $map = [
            'vegetarian' => '/\bvegetarian\b/iu',
            'vegan' => '/\bvegan\b/iu',
            'kosher' => '/\bkosher\b/iu',
            'gluten_free' => '/\bgluten[-\s]*free\b/iu',
        ];

        foreach ($map as $diet => $pattern) {
            if (preg_match($pattern, $lower) === 1 && ! in_array($diet, $this->extractDietExcludeFromQuery($query), true)) {
                $include[] = $diet;
            }
        }

        return array_values(array_unique($include));
    }

    protected function extractDietExcludeFromQuery(string $query): array
    {
        $lower = strtolower($this->normalizeText($query));
        $exclude = [];

        if (preg_match('/\b(?:non|not|without|avoid|exclude|no)\s+vegetarian\b/iu', $lower) === 1) {
            $exclude[] = 'vegetarian';
        }
        if (preg_match('/\b(?:non|not|without|avoid|exclude|no)\s+vegan\b/iu', $lower) === 1) {
            $exclude[] = 'vegan';
        }
        if (preg_match('/\b(?:non|not|without|avoid|exclude|no)\s+kosher\b/iu', $lower) === 1) {
            $exclude[] = 'kosher';
        }
        if (preg_match('/\b(?:non|not|without|avoid|exclude|no)\s+gluten[-\s]*free\b/iu', $lower) === 1) {
            $exclude[] = 'gluten_free';
        }

        return array_values(array_unique($exclude));
    }

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
     * Extracts "status include from query" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractStatusIncludeFromQuery(string $query): array
    {
        $raw = strtolower(trim($query));
        if ($raw === '') {
            return [];
        }

        // In this catalog, "halal or not-haram" / "halal or not marked haram"
        // should show confirmed halal rows only. Otherwise unknown/null/out-of-scope
        // products leak into strict grocery lists.
        $statusText = preg_replace('/not\s*[-\s]+haram/iu', 'not haram', $raw) ?? $raw;
        $statusText = preg_replace('/muslim[-\s]*friendly/iu', 'muslim friendly', $statusText) ?? $statusText;

        if ((preg_match('/\bhalal\b/iu', $statusText) === 1
                && preg_match('/\b(?:not\s+haram|not\s+marked\s+haram|muslim\s+friendly|safe\s+for\s+muslims?)\b/iu', $statusText) === 1)
            || preg_match('/\b(?:only\s+)?show\s+(?:me\s+)?(?:only\s+)?halal\b/iu', $statusText) === 1) {
            return ['halal'];
        }

        // Plain "not haram" / "safe for Muslims" without an explicit halal request
        // remains a haram-exclusion query.
        if (preg_match('/\bhalal\b/iu', $statusText) === 1
            && preg_match('/\b(?:or\s+at\s+least|at\s+least|not\s+marked\s+haram|not\s+haram|safe\s+for\s+muslims?|muslim\s+friendly|safe\s+grocery|safe\s+products?)\b/iu', $statusText) !== 1) {
            return ['halal'];
        }

        return [];
    }

    /**
     * Extracts "status exclude from query" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractStatusExcludeFromQuery(string $query): array
    {
        $raw = strtolower(trim($query));
        if ($raw === '') {
            return [];
        }

        $exclude = [];
        if (preg_match('/\b(?:not\s+haram|not\s+marked\s+haram|without\s+haram|avoid\s+haram|exclude\s+haram|safe\s+for\s+muslims?|muslim[-\s]*friendly|safe\s+grocery|safe\s+products?|safe\s+items?|not\s+forbidden|not\s+prohibited)\b/iu', $raw) === 1) {
            $exclude[] = 'haram';
        }

        return array_values(array_unique($exclude));
    }

    /**
     * Back-compat: extract old single 'status' field.
     */
    /**
     * Extracts "legacy status filter" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractLegacyStatusFilter(array $arguments): ?string
    {
        $status = Str::lower((string) ($arguments['status'] ?? ''));

        $normalized = $this->normalizeStatusValue($status);
        return in_array($normalized, ['halal', 'haram', 'mushbooh', 'unknown', 'out_of_scope'], true)
            ? $normalized
            : null;
    }

    /**
     * Normalizes "search text" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeSearchText(string $text): string
    {
        $tokens = $this->tokenize($text);

        return trim(implode(' ', $tokens));
    }

    // ─────────────────────────────────────────────────────────────
    // ALIAS EXPANSION
    // ─────────────────────────────────────────────────────────────

    /**
     * Helper method for "expand brand aliases".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function expandBrandAliases(string $brand): array
    {
        $brand = $this->normalizeBrand($brand);

        $aliases = [
            'pepsi'              => ['pepsi', 'pepsi cola', 'pepsi max'],
            'dettol'             => ['dettol', 'detol'],
            'detol'              => ['dettol', 'detol'],
            'carex'              => ['carex'],
            'sprite'             => ['sprite', 'sprit'],
            'dairy milk'         => ['dairy milk', 'dairymilk', 'cadbury dairy milk'],
            'peanut butter'      => ['peanut butter', 'penut butter', 'pennut butter'],
            'coca cola'          => ['coca cola', 'coca-cola', 'coke'],
            'coke'               => ['coke', 'coca cola', 'coca-cola'],
            'nestle'             => ['nestle'],
            'kinder'             => ['kinder'],
            'woolworth'          => ['woolworth', 'woolworths', 'items in woolworths'],
            'woolworths'         => ['woolworths', 'woolworth', 'items in woolworths'],
            'mcvitie'            => ['mcvitie', 'mcvities', "mcvitie's"],
            'mcvities'           => ['mcvitie', 'mcvities', "mcvitie's"],
            "mcvitie's"          => ['mcvitie', 'mcvities', "mcvitie's"],
            'peek freans'        => ['peek freans'],
            'peek freans sooper' => ['peek freans', 'sooper', 'peek freans sooper'],
        ];

        if (isset($aliases[$brand])) {
            return array_values(array_unique(array_filter($aliases[$brand])));
        }

        $generic = [$brand];
        $noPunct = trim((string) preg_replace('/[^a-z0-9\s&]+/iu', '', $brand));
        if ($noPunct !== '' && $noPunct !== $brand) {
            $generic[] = $noPunct;
        }

        // Generic singular/plural support for future brands without code changes:
        // Woolworth -> Woolworths, Peters -> Peter, etc. These are only aliases;
        // applyBrandFilter still restricts to brand/name/manufacturer fields.
        if (mb_strlen($brand) > 3) {
            if (str_ends_with($brand, 's')) {
                $generic[] = rtrim($brand, 's');
            } else {
                $generic[] = $brand . 's';
            }
        }

        return array_values(array_unique(array_filter($generic)));
    }

    /**
     * FIX: Expanded to cover all major countries including Italy, Turkey, France, Switzerland, Belgium, Saudi Arabia, Pakistan.
     */
    /**
     * Helper method for "expand origin aliases".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function expandOriginAliases(string $origin): array
    {
        $origin = $this->normalizeOrigin($origin);

        $shortcutAliases = match ($origin) {
            'usa', 'us', 'u s', 'united states', 'united states of america' => ['usa', 'us', 'u.s.', 'united states', 'united-states', 'united_states', 'united states of america', 'america', 'american'],
            'uk', 'u k', 'united kingdom', 'great britain', 'britain' => ['uk', 'u.k.', 'united kingdom', 'united-kingdom', 'united_kingdom', 'great britain', 'britain', 'england', 'english', 'british'],
            'uae', 'u a e', 'united arab emirates' => ['uae', 'u.a.e.', 'united arab emirates', 'united-arab-emirates', 'united_arab_emirates', 'emirates', 'dubai'],
            default => [],
        };

        // Also preserve your existing common demonym/misspelling support.
        $legacyAliases = match ($origin) {
            'australia'    => ['australia', 'australian', 'austrailian', 'austrelian', 'austrelia', 'autrelia', 'asutrailia', 'austrailia'],
            'new zealand'  => ['new zealand', 'newzealand', 'new-zealand', 'nz', 'n.z.', 'kiwi'],
            'pakistan'     => ['pakistan', 'pakistani', 'pk'],
            'italy'        => ['italy', 'italian', 'italia'],
            'turkey'       => ['turkey', 'turkish'],
            'france'       => ['france', 'french'],
            'switzerland'  => ['switzerland', 'swiss'],
            'belgium'      => ['belgium', 'belgian'],
            'saudi arabia' => ['saudi arabia', 'saudi-arabia', 'ksa', 'saudi'],
            'malaysia'     => ['malaysia', 'malaysian'],
            'india'        => ['india', 'indian'],
            'germany'      => ['germany', 'german'],
            'canada'       => ['canada', 'canadian'],
            'spain'        => ['spain', 'spanish'],
            'netherlands', 'netherland' => ['netherlands', 'netherland', 'holland', 'dutch'],
            'indonesia'    => ['indonesia', 'indonesian'],
            'thailand'     => ['thailand', 'thai'],
            'china'        => ['china', 'chinese'],
            'japan'        => ['japan', 'japanese'],
            'south korea'  => ['south korea', 'south-korea', 'korea', 'korean'],
            'poland'       => ['poland', 'polish'],
            'qatar'        => ['qatar', 'qatari'],
            'morocco'      => ['morocco', 'moroccan'],
            'hong kong'    => ['hong kong', 'hong-kong'],
            default        => [],
        };

        $aliases = array_merge([$origin], $shortcutAliases, $legacyAliases);
        $expanded = [];
        foreach ($aliases as $alias) {
            foreach ($this->originComparableForms((string) $alias) as $form) {
                $expanded[] = $form;
            }
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    /**
     * Generic origin forms for future DB values. This avoids adding code for every
     * new origin stored as "South-africa", "South Africa", "south_africa", etc.
     */
    protected function originComparableForms(string $origin): array
    {
        $origin = Str::lower($this->normalizeText($origin));
        $origin = trim((string) preg_replace('/\s+/u', ' ', $origin));
        if ($origin === '') {
            return [];
        }

        $space = str_replace(['_', '-'], ' ', $origin);
        $space = trim((string) preg_replace('/\s+/u', ' ', $space));
        $hyphen = str_replace(' ', '-', $space);
        $underscore = str_replace(' ', '_', $space);
        $compact = preg_replace('/[^\pL\pN]+/u', '', $space) ?? '';

        $forms = [$origin, $space, $hyphen, $underscore];
        if ($compact !== '') {
            $forms[] = $compact;
        }

        return array_values(array_unique(array_filter($forms)));
    }

    protected function compactOriginForms(array $forms): array
    {
        $compact = [];
        foreach ($forms as $form) {
            $clean = preg_replace('/[^\pL\pN]+/u', '', Str::lower((string) $form)) ?? '';
            if ($clean !== '' && mb_strlen($clean) > 2) {
                $compact[] = $clean;
            }
        }

        return array_values(array_unique($compact));
    }

    /**
     * Expands a category into aliases/synonyms for matching.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function expandCategoryAliases(string $category): array
    {
        $category = $this->normalizeCategory($category);

        return match ($category) {
            'juices'     => ['juice', 'juices', 'fruit juice', 'apple juice', 'orange juice', 'mango juice'],
            'drinks'     => ['drink', 'drinks', 'beverage', 'beverages', 'soft drink', 'fizzy drink', 'fizzy drinks', 'soda', 'pop', 'cola', 'sprite'],
            'burgers'    => ['burger', 'burgers', 'burger deal', 'burger deals', 'grate burger', 'great burger'],
            'pizza'      => ['pizza', 'pizzas'],
            'snacks'     => ['snack', 'snacks', 'chips', 'crisps', 'potato snack', 'sticks'],
            'biscuits'   => ['biscuit', 'biscuits', 'cookie', 'cookies', 'cracker', 'crackers', 'wafer', 'wafers'],
            'chocolates' => ['chocolate', 'chocolates', 'cocoa', 'confectionery', 'nougat', 'ferrero', 'rocher', 'kinder', 'dairy milk'],
            'candies'    => ['candy', 'candies', 'sweet', 'sweets', 'gummy', 'gummies', 'jelly'],
            'beef'       => ['beef', 'beef stock', 'beef cube', 'beef cubes'],
            'chicken'    => ['chicken', 'poultry'],
            'meats'      => ['beef', 'meat', 'meats', 'chicken', 'poultry', 'mutton', 'lamb', 'sausage', 'sausages'],
            'cakes'      => ['cake', 'cakes', 'cupcake', 'cupcakes'],
            'bread'      => ['bread', 'breads', 'loaf', 'loaves', 'toast'],
            'pasta'      => ['pasta', 'pastas', 'noodle', 'noodles', 'instant noodles', 'spaghetti', 'macaroni'],
            'bakery'     => ['bakery', 'pastry', 'baked', 'bread', 'cake', 'muffin', 'croissant'],
            'sauces'     => ['sauce', 'sauces', 'mayonnaise', 'mayo', 'mayonese', 'mayounese', 'ketchup', 'condiment', 'condiments', 'soy sauce'],
            'dairy'      => ['dairy', 'milk', 'cheese', 'yogurt', 'yoghurt', 'cream'],
            'dairy_alternatives' => ['dairy alternative', 'dairy alternatives', 'milk alternative', 'milk alternatives', 'plant based', 'plant based milk', 'non dairy', 'non-dairy', 'dairy free', 'almond milk', 'soy milk', 'soya milk', 'oat milk', 'rice milk', 'coconut milk'],
            'spices'     => ['spice', 'spices', 'masala', 'seasoning', 'seasonings', 'mix', 'tikka mix', 'chilli', 'chili', 'paprika', 'turmeric', 'ginger'],
            'pantry'     => ['pantry', 'pasta', 'spaghetti', 'noodle', 'noodles', 'ketchup', 'stock cube', 'stock cubes', 'breadcrumbs', 'yeast extract'],
            'breakfast'  => ['breakfast', 'cereal', 'cereals', 'oats', 'granola'],
            'tea'        => ['tea', 'tea bag', 'tea bags', 'green tea', 'black tea'],
            'canned_goods' => ['canned goods', 'canned', 'beans', 'chickpeas', 'tin', 'tinned'],
            'household'  => ['household', 'cleaning', 'cleaner', 'bathroom', 'washroom', 'loo', 'toilet', 'kitchen', 'soap', 'hand wash', 'handwash', 'hand soap', 'antiseptic', 'disinfectant', 'detergent', 'bleach', 'dettol', 'carex'],
            'oils'       => ['oil', 'oils', 'cooking oil', 'edible oil', 'olive oil', 'sunflower oil', 'canola oil', 'palm oil', 'coconut oil'],
            'sweeteners' => ['sweetener', 'sweeteners', 'sugar', 'honey', 'syrup', 'molasses', 'stevia', 'aspartame', 'sucralose'],
            default      => [$category],
        };
    }

    /**
     * Helper method for "tokenize".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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

    /**
     * Boolean helper that checks whether the current request or product matches "has column".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
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
