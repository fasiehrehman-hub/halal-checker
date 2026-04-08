<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ProductLookupService
{
    protected string $table = 'products';

    protected ?array $tableColumnsCache = null;

    public function search(array $args): array
    {
        $intent = $this->normalizeArgs($args);

        if ($this->isEmptyIntent($intent)) {
            return $this->notFound($intent, 'I could not understand which product, brand, category, barcode, ingredient, or origin to search.');
        }

        if (($intent['mode'] ?? 'search') === 'ingredient_explainer') {
            return $this->explainIngredient($intent);
        }

        $products = $this->runSearchPipeline($intent);

        if ($products->isEmpty()) {
            return $this->notFoundWithSuggestions($intent);
        }

        $formattedProducts = $products
            ->map(fn ($product) => $this->formatProduct($product, $intent))
            ->values()
            ->all();

        return [
            'status' => 'found',
            'message' => 'Product information found.',
            'intent' => $intent,
            'meta' => [
                'result_count' => count($formattedProducts),
                'search_summary' => $this->buildSearchSummary($intent),
                'lookup_kind' => $this->determineLookupKind($intent),
                'category_aliases' => $intent['category_aliases'] ?? [],
                'barcode_variants' => $intent['barcode_variants'] ?? [],
                'preferred_origin_applied' => !empty($intent['preferred_origin']) && empty($intent['origin']),
                'search_strategy' => $intent['search_strategy'] ?? null,
                'exact_match_confident' => $intent['exact_match_confident'] ?? false,
            ],
            'products' => $formattedProducts,
        ];
    }

    protected function runSearchPipeline(array &$intent): Collection
    {
        if ($this->isDirectLookupIntent($intent)) {
            return $this->runDirectLookupPipeline($intent);
        }

        return $this->runGeneralLookup($intent, $intent['limit']);
    }

    protected function runDirectLookupPipeline(array &$intent): Collection
    {
        $limit = max(1, min((int) ($intent['limit'] ?? 8), 20));

        if (!empty($intent['barcode'])) {
            $barcodeMatches = $this->runBarcodeLookup($intent, $limit);
            if ($barcodeMatches->isNotEmpty()) {
                $intent['search_strategy'] = 'barcode_exact_or_near';
                $intent['exact_match_confident'] = true;
                return $barcodeMatches;
            }
        }

        if (!empty($intent['product_name'])) {
            $strictNameMatches = $this->runStrictProductLookup($intent, $limit);
            if ($strictNameMatches->isNotEmpty()) {
                $intent['search_strategy'] = 'strict_product_name';
                $intent['exact_match_confident'] = true;
                return $strictNameMatches;
            }

            $brandAwareMatches = $this->runBrandAwareProductLookup($intent, $limit);
            if ($brandAwareMatches->isNotEmpty()) {
                $intent['search_strategy'] = 'brand_aware_product_lookup';
                $intent['exact_match_confident'] = true;
                return $brandAwareMatches;
            }

            $tokenMatches = $this->runTokenProductLookup($intent, $limit);
            if ($tokenMatches->isNotEmpty()) {
                $intent['search_strategy'] = 'token_product_lookup';
                $intent['exact_match_confident'] = false;
                return $tokenMatches;
            }
        }

        if (!empty($intent['brand'])) {
            $brandMatches = $this->runBrandLookup($intent, $limit);
            if ($brandMatches->isNotEmpty()) {
                $intent['search_strategy'] = 'brand_lookup';
                $intent['exact_match_confident'] = false;
                return $brandMatches;
            }
        }

        $generalMatches = $this->runGeneralLookup($intent, $limit);
        if ($generalMatches->isNotEmpty()) {
            $intent['search_strategy'] = 'general_fallback_from_direct_lookup';
            $intent['exact_match_confident'] = false;
        }

        return $generalMatches;
    }

    protected function runBarcodeLookup(array $intent, int $limit): Collection
    {
        if (!$this->hasColumn('barcode') || empty($intent['barcode_variants'])) {
            return collect();
        }

        $query = Product::query();
        $variants = $this->buildBarcodeSearchCandidates($intent['barcode'] ?? null, $intent['barcode_variants'] ?? []);
        $digits = $intent['barcode_digits'] ?? null;

        $query->where(function (Builder $q) use ($variants, $digits) {
            foreach ($variants as $index => $variant) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                $q->{$method}('LOWER(CAST(barcode AS CHAR)) = ?', [strtolower($variant)]);
                $q->orWhereRaw('LOWER(CAST(barcode AS CHAR)) LIKE ?', ['%' . strtolower($variant) . '%']);
            }

            if ($digits) {
                $normalizedBarcodeSql = $this->normalizedBarcodeSql('barcode');
                $q->orWhereRaw($normalizedBarcodeSql . ' = ?', [$digits])
                  ->orWhereRaw($normalizedBarcodeSql . ' LIKE ?', ['%' . $digits . '%']);
            }
        });

        $this->applyDecisionFilter($query, $intent);
        $this->applyOriginFilter($query, $intent);
        $this->applyRanking($query, $intent);

        return $query->limit($limit)->get();
    }

    protected function runStrictProductLookup(array $intent, int $limit): Collection
    {
        if (empty($intent['product_name']) || !$this->hasColumn('name')) {
            return collect();
        }

        $productName = strtolower($intent['product_name']);
        $compactName = $this->compact($productName);
        $tokens = $this->meaningfulProductTokens($productName);
        $allTextSql = $this->combinedSearchTextSql(['name', 'brand', 'description']);

        $query = Product::query();

        $query->where(function (Builder $q) use ($productName, $compactName, $tokens, $allTextSql) {
            $q->whereRaw('LOWER(name) = ?', [$productName])
              ->orWhereRaw($this->compactColumnSql('name') . ' = ?', [$compactName]);

            if ($this->hasColumn('brand')) {
                $q->orWhereRaw($this->compactConcatBrandNameSql() . ' = ?', [$compactName]);
                $q->orWhereRaw('LOWER(CONCAT(COALESCE(brand, \'\'), \' \', COALESCE(name, \'\'))) = ?', [$productName]);
            }

            if (!empty($tokens) && $allTextSql) {
                $q->orWhere(function (Builder $sub) use ($tokens, $allTextSql) {
                    foreach ($tokens as $token) {
                        $sub->whereRaw($allTextSql . ' LIKE ?', ['%' . $token . '%']);
                    }
                });
            }
        });

        $this->applyOriginFilter($query, $intent);
        $this->applyRanking($query, $intent);

        return $query->limit($limit)->get();
    }

    protected function runBrandAwareProductLookup(array $intent, int $limit): Collection
    {
        if (!$this->hasColumn('name') || !$this->hasColumn('brand') || empty($intent['product_name'])) {
            return collect();
        }

        $productName = strtolower($intent['product_name']);
        $brand = strtolower((string) ($intent['brand'] ?? $this->extractLeadingBrandGuess($intent['product_name'])));
        $tokens = $this->meaningfulProductTokens($productName);

        if ($brand === '' || empty($tokens)) {
            return collect();
        }

        $query = Product::query();

        $query->where(function (Builder $q) use ($brand, $tokens) {
            $q->whereRaw('LOWER(brand) LIKE ?', ['%' . $brand . '%']);

            foreach ($tokens as $token) {
                $q->where(function (Builder $sub) use ($token) {
                    $sub->whereRaw('LOWER(name) LIKE ?', ['%' . $token . '%']);
                    if ($this->hasColumn('description')) {
                        $sub->orWhereRaw('LOWER(description) LIKE ?', ['%' . $token . '%']);
                    }
                });
            }
        });

        $this->applyOriginFilter($query, $intent);
        $this->applyRanking($query, $intent);

        return $query->limit($limit)->get();
    }

    protected function runTokenProductLookup(array $intent, int $limit): Collection
    {
        if (empty($intent['product_name'])) {
            return collect();
        }

        $tokens = $this->meaningfulProductTokens($intent['product_name']);
        if (empty($tokens)) {
            return collect();
        }

        $allTextSql = $this->combinedSearchTextSql(['name', 'brand', 'description', 'category', 'categories', 'main_category', 'main_category1']);
        if (!$allTextSql) {
            return collect();
        }

        $query = Product::query();
        $requiredTokens = array_slice($tokens, 0, min(3, count($tokens)));
        $fullProductName = strtolower((string) ($intent['product_name'] ?? ''));
        $compactProductName = $this->compact($fullProductName);

        $query->where(function (Builder $q) use ($requiredTokens, $allTextSql, $fullProductName, $compactProductName) {
            foreach ($requiredTokens as $token) {
                $q->whereRaw($allTextSql . ' LIKE ?', ['%' . $token . '%']);
            }

            if ($fullProductName !== '') {
                if ($this->hasColumn('name')) {
                    $q->orWhereRaw('LOWER(name) LIKE ?', ['%' . $fullProductName . '%'])
                      ->orWhereRaw($this->compactColumnSql('name') . ' = ?', [$compactProductName]);
                }

                if ($this->hasColumn('brand')) {
                    $q->orWhereRaw('LOWER(brand) LIKE ?', ['%' . $fullProductName . '%']);
                }

                if ($this->hasColumn('description')) {
                    $q->orWhereRaw('LOWER(description) LIKE ?', ['%' . $fullProductName . '%']);
                }
            }
        });

        if (!empty($intent['brand'])) {
            $this->applyBrandFilter($query, ['brand' => $intent['brand']]);
        }

        $this->applyOriginFilter($query, $intent);
        $this->applyRanking($query, $intent);

        return $query->limit($limit)->get();
    }

    protected function runBrandLookup(array $intent, int $limit): Collection
    {
        $query = Product::query();
        $this->applyOriginFilter($query, $intent);
        $this->applyBrandFilter($query, $intent);
        $this->applyDecisionFilter($query, $intent);
        $this->applyCategoryFilter($query, $intent);
        $this->applyIngredientFilter($query, $intent);
        $this->applyRanking($query, $intent);

        return $query->limit($limit)->get();
    }

    protected function runGeneralLookup(array $intent, int $limit): Collection
    {
        $query = Product::query();

        $this->applyDecisionFilter($query, $intent);
        $this->applyOriginFilter($query, $intent);
        $this->applyBrandFilter($query, $intent);
        $this->applyNameFilter($query, $intent);
        $this->applyCategoryFilter($query, $intent);
        $this->applyIngredientFilter($query, $intent);
        $this->applyGenericKeywordsFilter($query, $intent);
        $this->applyRanking($query, $intent);

        return $query->limit($limit)->get();
    }

    protected function normalizeArgs(array $args): array
    {
        $mode = strtolower(trim((string) ($args['mode'] ?? 'search')));
        if (!in_array($mode, [
            'specific_product',
            'brand_list',
            'category_list',
            'barcode_lookup',
            'ingredient_lookup',
            'ingredient_explainer',
            'search',
        ], true)) {
            $mode = 'search';
        }

        $decision = strtolower(trim((string) ($args['decision'] ?? '')));
        if (!in_array($decision, ['halal', 'haram', 'mashbooh', ''], true)) {
            $decision = '';
        }

        $barcodeRawInput = $this->cleanText((string) ($args['barcode'] ?? ''));
        $normalizedBarcode = $this->normalizeBarcode($barcodeRawInput);

        $brand = $this->normalizeFreeText($this->cleanText((string) ($args['brand'] ?? '')));
        $productName = $this->normalizeFreeText($this->cleanText((string) ($args['product_name'] ?? '')));
        $categoryTermRaw = $this->normalizeFreeText($this->cleanText((string) ($args['category_term'] ?? '')));
        $origin = $this->normalizeCountry($this->cleanText((string) ($args['origin'] ?? '')));
        $preferredOrigin = $this->normalizeCountry($this->cleanText((string) ($args['preferred_origin'] ?? '')));
        $query = $this->normalizeFreeText($this->cleanText((string) ($args['query'] ?? '')));

        $keywords = $this->normalizeStringArray($args['keywords'] ?? []);
        $ingredientTerms = $this->normalizeStringArray($args['ingredient_terms'] ?? []);
        $searchIngredients = filter_var($args['search_ingredients'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $limit = (int) ($args['limit'] ?? 8);

        if (in_array($mode, ['ingredient_lookup', 'ingredient_explainer'], true) && !empty($ingredientTerms)) {
            $searchIngredients = true;
        }

        if (!empty($normalizedBarcode['raw'])) {
            $mode = 'barcode_lookup';
        }


        $isDirectProductLookup =
            !empty($normalizedBarcode['raw']) ||
            !empty($productName) ||
            in_array($mode, ['specific_product', 'barcode_lookup'], true);

        $normalizedCategoryTerm = $this->normalizeCategoryTerm($categoryTermRaw ?: '');
        $categoryAliases = $normalizedCategoryTerm ? $this->expandCategoryAliases($normalizedCategoryTerm) : [];

        if (!$isDirectProductLookup && empty($normalizedCategoryTerm)) {
            $categoryGuess = $this->inferCategoryFromText(
                implode(' ', array_filter([
                    $query,
                    implode(' ', $keywords),
                    implode(' ', $ingredientTerms),
                    $brand,
                ]))
            );

            if ($categoryGuess) {
                $normalizedCategoryTerm = $categoryGuess;
                $categoryAliases = $this->expandCategoryAliases($normalizedCategoryTerm);

                if ($mode === 'search') {
                    $mode = 'category_list';
                }
            }
        }

        if ($isDirectProductLookup) {
            $decision = '';
            $normalizedCategoryTerm = null;
            $categoryAliases = [];
        }

        return [
            'mode' => $mode,
            'decision' => $decision ?: null,
            'barcode' => $normalizedBarcode['raw'],
            'barcode_digits' => $normalizedBarcode['digits'],
            'barcode_variants' => $normalizedBarcode['variants'],
            'brand' => $brand,
            'product_name' => $productName,
            'category_term' => $normalizedCategoryTerm,
            'category_aliases' => $categoryAliases,
            'origin' => $origin,
            'preferred_origin' => empty($origin) ? $preferredOrigin : null,
            'query' => $query,
            'keywords' => $keywords,
            'ingredient_terms' => $ingredientTerms,
            'search_ingredients' => $searchIngredients,
            'limit' => max(1, min($limit, 20)),
            'search_strategy' => null,
            'exact_match_confident' => false,
        ];
    }

    protected function explainIngredient(array $intent): array
    {
        $ingredient = $intent['ingredient_terms'][0] ?? null;

        if (!$ingredient) {
            return [
                'status' => 'not_found',
                'message' => 'No ingredient was provided.',
                'intent' => $intent,
                'meta' => [
                    'lookup_kind' => 'ingredient_explainer',
                    'result_count' => 0,
                    'search_summary' => $this->buildSearchSummary($intent),
                ],
                'ingredient_explanation' => null,
                'products' => [],
            ];
        }

        $explanation = $this->buildIngredientKnowledge($ingredient);

        return [
            'status' => 'found',
            'message' => 'Ingredient explanation prepared.',
            'intent' => $intent,
            'meta' => [
                'lookup_kind' => 'ingredient_explainer',
                'result_count' => 0,
                'search_summary' => $this->buildSearchSummary($intent),
            ],
            'ingredient_explanation' => $explanation,
            'products' => [],
        ];
    }

    protected function buildIngredientKnowledge(string $ingredient): array
    {
        $normalized = strtolower(trim($ingredient));

        $map = [
            'gelatin' => [
                'ingredient' => 'Gelatin',
                'summary' => 'Gelatin can come from animal sources, so its halal status depends on the source and processing.',
                'usual_sources' => ['bovine', 'porcine', 'fish'],
                'decision_hint' => 'source_dependent',
                'halal_when' => [
                    'it is from halal-slaughtered bovine source',
                    'or from verified fish/permissible source where accepted by your policy',
                ],
                'haram_when' => [
                    'it is from pork or porcine source',
                    'or from non-halal animal source',
                ],
                'mashbooh_when' => [
                    'the source is not clearly disclosed',
                ],
            ],
            'e471' => [
                'ingredient' => 'E471',
                'summary' => 'E471 may be derived from plant or animal fats, so the source matters.',
                'usual_sources' => ['plant fats', 'animal fats'],
                'decision_hint' => 'source_dependent',
                'halal_when' => [
                    'the source is verified plant-based',
                    'or from halal animal source',
                ],
                'haram_when' => [
                    'the source is from non-halal animal fat',
                ],
                'mashbooh_when' => [
                    'the source is not specified',
                ],
            ],
            'enzyme' => [
                'ingredient' => 'Enzyme',
                'summary' => 'Enzymes may come from microbial, plant, or animal sources.',
                'usual_sources' => ['microbial', 'plant', 'animal'],
                'decision_hint' => 'source_dependent',
                'halal_when' => [
                    'the enzyme source is microbial or plant-based',
                    'or from halal animal source',
                ],
                'haram_when' => [
                    'the enzyme comes from non-halal animal source',
                ],
                'mashbooh_when' => [
                    'the enzyme source is not disclosed',
                ],
            ],
            'alcohol' => [
                'ingredient' => 'Alcohol',
                'summary' => 'Alcohol-related ingredients are sensitive and must be handled according to your halal policy and source context.',
                'usual_sources' => ['fermentation', 'solvent or carrier use', 'flavour processing'],
                'decision_hint' => 'sensitive',
                'halal_when' => [
                    'only if your internal halal policy explicitly allows the specific technical use-case',
                ],
                'haram_when' => [
                    'the product clearly contains non-permissible intoxicating alcohol according to your policy',
                ],
                'mashbooh_when' => [
                    'the label is unclear or lacks technical context',
                ],
            ],
            'carmine' => [
                'ingredient' => 'Carmine',
                'summary' => 'Carmine is a coloring ingredient commonly derived from insects.',
                'usual_sources' => ['insect-derived coloring'],
                'decision_hint' => 'policy_sensitive',
                'halal_when' => [
                    'only if your internal Shariah policy explicitly allows it',
                ],
                'haram_when' => [
                    'if your internal policy does not allow it',
                ],
                'mashbooh_when' => [
                    'the product uses related color coding without clarity',
                ],
            ],
            'lecithin' => [
                'ingredient' => 'Lecithin',
                'summary' => 'Lecithin is often plant-based, but source confirmation is still useful.',
                'usual_sources' => ['soy', 'sunflower', 'egg'],
                'decision_hint' => 'usually_permissible_but_verify',
                'halal_when' => [
                    'it is from soy, sunflower, or other verified permissible source',
                ],
                'haram_when' => [
                    'it is from a clearly non-halal source according to your policy',
                ],
                'mashbooh_when' => [
                    'the source is not disclosed and product context is sensitive',
                ],
            ],
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        return [
            'ingredient' => ucfirst($ingredient),
            'summary' => 'This ingredient may require source verification before a final halal conclusion.',
            'usual_sources' => [],
            'decision_hint' => 'unknown_source',
            'halal_when' => [
                'the source is verified permissible under your policy',
            ],
            'haram_when' => [
                'the source is verified non-halal under your policy',
            ],
            'mashbooh_when' => [
                'the source is not clearly known',
            ],
        ];
    }

    protected function isEmptyIntent(array $intent): bool
    {
        return empty($intent['decision'])
            && empty($intent['barcode'])
            && empty($intent['brand'])
            && empty($intent['product_name'])
            && empty($intent['category_term'])
            && empty($intent['origin'])
            && empty($intent['preferred_origin'])
            && empty($intent['query'])
            && empty($intent['keywords'])
            && empty($intent['ingredient_terms']);
    }

    protected function isDirectLookupIntent(array $intent): bool
    {
        return !empty($intent['barcode'])
            || !empty($intent['product_name'])
            || in_array(($intent['mode'] ?? ''), ['specific_product', 'barcode_lookup'], true);
    }

    protected function applyDecisionFilter(Builder $query, array $intent): void
    {
        if (empty($intent['decision']) || !$this->hasColumn('type')) {
            return;
        }

        if ($this->isDirectLookupIntent($intent)) {
            return;
        }

        $values = $this->decisionToTypeValues($intent['decision']);

        $query->where(function (Builder $q) use ($values) {
            foreach ($values as $index => $value) {
                if ($index === 0) {
                    $q->whereRaw('LOWER(type) LIKE ?', ['%' . strtolower($value) . '%']);
                } else {
                    $q->orWhereRaw('LOWER(type) LIKE ?', ['%' . strtolower($value) . '%']);
                }
            }
        });
    }

    protected function applyOriginFilter(Builder $query, array $intent): void
    {
        if (!$this->hasColumn('origin')) {
            return;
        }

        $originSource = !empty($intent['origin'])
            ? (string) $intent['origin']
            : (!empty($intent['preferred_origin']) ? (string) $intent['preferred_origin'] : '');

        if ($originSource === '') {
            return;
        }

        $origin = strtolower($originSource);
        $tokens = $this->tokenize($origin);

        $query->where(function (Builder $q) use ($origin, $tokens) {
            $q->whereRaw('LOWER(origin) LIKE ?', ['%' . $origin . '%']);

            foreach ($tokens as $token) {
                if (strlen($token) >= 2) {
                    $q->orWhereRaw('LOWER(origin) LIKE ?', ['%' . $token . '%']);
                }
            }
        });
    }

    protected function applyBrandFilter(Builder $query, array $intent): void
    {
        if (empty($intent['brand'])) {
            return;
        }

        $brand = strtolower($intent['brand']);

        $query->where(function (Builder $q) use ($brand) {
            $applied = false;

            if ($this->hasColumn('brand')) {
                $q->whereRaw('LOWER(brand) LIKE ?', ['%' . $brand . '%']);
                $applied = true;
            }

            if ($this->hasColumn('name')) {
                if ($applied) {
                    $q->orWhereRaw('LOWER(name) LIKE ?', ['%' . $brand . '%']);
                } else {
                    $q->whereRaw('LOWER(name) LIKE ?', ['%' . $brand . '%']);
                    $applied = true;
                }
            }

            if ($this->hasColumn('description')) {
                if ($applied) {
                    $q->orWhereRaw('LOWER(description) LIKE ?', ['%' . $brand . '%']);
                } else {
                    $q->whereRaw('LOWER(description) LIKE ?', ['%' . $brand . '%']);
                }
            }
        });
    }

    protected function applyNameFilter(Builder $query, array $intent): void
    {
        if (empty($intent['product_name']) || !$this->hasColumn('name')) {
            return;
        }

        $name = strtolower($intent['product_name']);
        $compactName = $this->compact($name);
        $tokens = $this->meaningfulProductTokens($name);
        $allTextSql = $this->combinedSearchTextSql(['name', 'brand', 'description']);

        $query->where(function (Builder $q) use ($name, $compactName, $tokens, $allTextSql) {
            $q->whereRaw('LOWER(name) = ?', [$name])
                ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $name . '%'])
                ->orWhereRaw($this->compactColumnSql('name') . ' = ?', [$compactName]);

            if ($this->hasColumn('brand')) {
                $q->orWhereRaw('LOWER(CONCAT(COALESCE(brand, \'\'), \' \', COALESCE(name, \'\'))) LIKE ?', ['%' . $name . '%'])
                  ->orWhereRaw($this->compactConcatBrandNameSql() . ' = ?', [$compactName]);
            }

            if ($allTextSql && !empty($tokens)) {
                $q->orWhere(function (Builder $sub) use ($tokens, $allTextSql) {
                    foreach ($tokens as $token) {
                        $sub->whereRaw($allTextSql . ' LIKE ?', ['%' . strtolower($token) . '%']);
                    }
                });
            }
        });
    }

    protected function applyCategoryFilter(Builder $query, array $intent): void
    {
        if (empty($intent['category_term'])) {
            return;
        }

        $terms = $this->buildCategorySearchTerms($intent);
        $categoryColumns = array_values(array_filter([
            $this->hasColumn('category') ? 'category' : null,
            $this->hasColumn('categories') ? 'categories' : null,
            $this->hasColumn('main_category') ? 'main_category' : null,
            $this->hasColumn('main_category1') ? 'main_category1' : null,
        ]));

        $query->where(function (Builder $outer) use ($categoryColumns, $terms) {
            foreach ($categoryColumns as $column) {
                $outer->orWhere(function (Builder $q) use ($column, $terms) {
                    foreach ($terms as $index => $term) {
                        if ($index === 0) {
                            $q->whereRaw("LOWER($column) LIKE ?", ['%' . $term . '%']);
                        } else {
                            $q->orWhereRaw("LOWER($column) LIKE ?", ['%' . $term . '%']);
                        }
                    }
                });
            }

            if ($this->hasColumn('name')) {
                $outer->orWhere(function (Builder $q) use ($terms) {
                    foreach ($terms as $index => $term) {
                        if ($index === 0) {
                            $q->whereRaw('LOWER(name) LIKE ?', ['%' . $term . '%']);
                        } else {
                            $q->orWhereRaw('LOWER(name) LIKE ?', ['%' . $term . '%']);
                        }
                    }
                });
            }

            if ($this->hasColumn('description')) {
                $outer->orWhere(function (Builder $q) use ($terms) {
                    foreach ($terms as $index => $term) {
                        if ($index === 0) {
                            $q->whereRaw('LOWER(description) LIKE ?', ['%' . $term . '%']);
                        } else {
                            $q->orWhereRaw('LOWER(description) LIKE ?', ['%' . $term . '%']);
                        }
                    }
                });
            }
        });
    }

    protected function applyIngredientFilter(Builder $query, array $intent): void
    {
        if (empty($intent['search_ingredients']) || empty($intent['ingredient_terms'])) {
            return;
        }

        $allTextSql = $this->combinedSearchTextSql(['ingredients', 'name', 'description']);
        if (!$allTextSql && !$this->hasColumn('ingredients')) {
            return;
        }

        foreach ($intent['ingredient_terms'] as $ingredient) {
            $ingredient = strtolower((string) $ingredient);
            $tokens = array_values(array_filter($this->tokenize($ingredient), fn ($token) => strlen($token) >= 3));

            $query->where(function (Builder $q) use ($ingredient, $tokens, $allTextSql) {
                if ($allTextSql) {
                    $q->whereRaw($allTextSql . ' LIKE ?', ['%' . $ingredient . '%']);

                    foreach ($tokens as $token) {
                        $q->orWhereRaw($allTextSql . ' LIKE ?', ['%' . $token . '%']);
                    }

                    return;
                }

                $q->whereRaw('LOWER(ingredients) LIKE ?', ['%' . $ingredient . '%']);

                foreach ($tokens as $token) {
                    $q->orWhereRaw('LOWER(ingredients) LIKE ?', ['%' . $token . '%']);
                }
            });
        }
    }

    protected function applyGenericKeywordsFilter(Builder $query, array $intent): void
    {
        if (!empty($intent['barcode']) || !empty($intent['brand']) || !empty($intent['product_name']) || !empty($intent['category_term']) || !empty($intent['origin']) || !empty($intent['ingredient_terms'])) {
            return;
        }

        $keywords = $intent['keywords'];
        if (empty($keywords)) {
            $keywords = $this->tokenize((string) ($intent['query'] ?? ''));
        }

        $searchableColumns = array_values(array_filter([
            $this->hasColumn('name') ? 'name' : null,
            $this->hasColumn('brand') ? 'brand' : null,
            $this->hasColumn('category') ? 'category' : null,
            $this->hasColumn('categories') ? 'categories' : null,
            $this->hasColumn('main_category') ? 'main_category' : null,
            $this->hasColumn('main_category1') ? 'main_category1' : null,
            $this->hasColumn('description') ? 'description' : null,
            $this->hasColumn('ingredients') ? 'ingredients' : null,
            $this->hasColumn('notes') ? 'notes' : null,
            $this->hasColumn('allergens') ? 'allergens' : null,
            $this->hasColumn('origin') ? 'origin' : null,
        ]));

        if (empty($searchableColumns) || empty($keywords)) {
            return;
        }

        foreach ($keywords as $keyword) {
            if (strlen($keyword) < 2) {
                continue;
            }

            $query->where(function (Builder $q) use ($searchableColumns, $keyword) {
                foreach ($searchableColumns as $index => $column) {
                    if ($index === 0) {
                        $q->whereRaw("LOWER($column) LIKE ?", ['%' . strtolower($keyword) . '%']);
                    } else {
                        $q->orWhereRaw("LOWER($column) LIKE ?", ['%' . strtolower($keyword) . '%']);
                    }
                }
            });
        }
    }

    protected function applyRanking(Builder $query, array $intent): void
    {
        $scoreParts = [];

        if ($this->hasColumn('name') && !empty($intent['product_name'])) {
            $name = strtolower($intent['product_name']);
            $compactName = $this->compact($name);

            $scoreParts[] = "CASE WHEN LOWER(name) = " . $this->quote($name) . " THEN 1000 ELSE 0 END";
            $scoreParts[] = "CASE WHEN " . $this->compactColumnSql('name') . " = " . $this->quote($compactName) . " THEN 820 ELSE 0 END";
            $scoreParts[] = "CASE WHEN LOWER(name) LIKE " . $this->quote('%' . $name . '%') . " THEN 500 ELSE 0 END";

            foreach ($this->meaningfulProductTokens($name) as $token) {
                $scoreParts[] = "CASE WHEN LOWER(name) LIKE " . $this->quote('%' . $token . '%') . " THEN 120 ELSE 0 END";
            }

            if ($this->hasColumn('brand')) {
                $scoreParts[] = "CASE WHEN LOWER(CONCAT(COALESCE(brand, ''), ' ', COALESCE(name, ''))) = " . $this->quote($name) . " THEN 720 ELSE 0 END";
                $scoreParts[] = "CASE WHEN LOWER(CONCAT(COALESCE(brand, ''), ' ', COALESCE(name, ''))) LIKE " . $this->quote('%' . $name . '%') . " THEN 320 ELSE 0 END";
                $scoreParts[] = "CASE WHEN " . $this->compactConcatBrandNameSql() . " = " . $this->quote($compactName) . " THEN 620 ELSE 0 END";
            }

            if ($this->hasColumn('description')) {
                $scoreParts[] = "CASE WHEN LOWER(description) LIKE " . $this->quote('%' . $name . '%') . " THEN 90 ELSE 0 END";
            }
        }

        if ($this->hasColumn('barcode') && !empty($intent['barcode'])) {
            foreach ($this->buildBarcodeSearchCandidates($intent['barcode'] ?? null, $intent['barcode_variants'] ?? []) as $variant) {
                $scoreParts[] = "CASE WHEN LOWER(CAST(barcode AS CHAR)) = " . $this->quote(strtolower($variant)) . " THEN 1600 ELSE 0 END";
                $scoreParts[] = "CASE WHEN LOWER(CAST(barcode AS CHAR)) LIKE " . $this->quote('%' . strtolower($variant) . '%') . " THEN 740 ELSE 0 END";
            }

            if (!empty($intent['barcode_digits'])) {
                $digits = strtolower($intent['barcode_digits']);
                $scoreParts[] = "CASE WHEN " . $this->normalizedBarcodeSql('barcode') . " = " . $this->quote($digits) . " THEN 1500 ELSE 0 END";
                $scoreParts[] = "CASE WHEN " . $this->normalizedBarcodeSql('barcode') . " LIKE " . $this->quote('%' . $digits . '%') . " THEN 700 ELSE 0 END";
            }
        }

        if ($this->hasColumn('brand') && !empty($intent['brand'])) {
            $brand = strtolower($intent['brand']);
            $scoreParts[] = "CASE WHEN LOWER(brand) = " . $this->quote($brand) . " THEN 420 ELSE 0 END";
            $scoreParts[] = "CASE WHEN LOWER(brand) LIKE " . $this->quote('%' . $brand . '%') . " THEN 220 ELSE 0 END";
        }

        if (!empty($intent['category_term'])) {
            foreach ($this->buildCategorySearchTerms($intent) as $term) {
                foreach (['category', 'categories', 'main_category', 'main_category1'] as $column) {
                    if ($this->hasColumn($column)) {
                        $scoreParts[] = "CASE WHEN LOWER($column) = " . $this->quote($term) . " THEN 260 ELSE 0 END";
                        $scoreParts[] = "CASE WHEN LOWER($column) LIKE " . $this->quote('%' . $term . '%') . " THEN 130 ELSE 0 END";
                    }
                }

                if ($this->hasColumn('name')) {
                    $scoreParts[] = "CASE WHEN LOWER(name) LIKE " . $this->quote('%' . $term . '%') . " THEN 70 ELSE 0 END";
                }
            }
        }

        if (!empty($intent['origin']) && $this->hasColumn('origin')) {
            $origin = strtolower($intent['origin']);
            $scoreParts[] = "CASE WHEN LOWER(origin) = " . $this->quote($origin) . " THEN 220 ELSE 0 END";
            $scoreParts[] = "CASE WHEN LOWER(origin) LIKE " . $this->quote('%' . $origin . '%') . " THEN 120 ELSE 0 END";
        }

        if (empty($intent['origin']) && !empty($intent['preferred_origin']) && $this->hasColumn('origin')) {
            $preferredOrigin = strtolower($intent['preferred_origin']);
            $scoreParts[] = "CASE WHEN LOWER(origin) = " . $this->quote($preferredOrigin) . " THEN 120 ELSE 0 END";
            $scoreParts[] = "CASE WHEN LOWER(origin) LIKE " . $this->quote('%' . $preferredOrigin . '%') . " THEN 60 ELSE 0 END";
        }

        if (!empty($intent['ingredient_terms']) && !empty($intent['search_ingredients']) && $this->hasColumn('ingredients')) {
            foreach ($intent['ingredient_terms'] as $ingredient) {
                $scoreParts[] = "CASE WHEN LOWER(ingredients) LIKE " . $this->quote('%' . strtolower($ingredient) . '%') . " THEN 180 ELSE 0 END";
            }
        }

        if (!empty($intent['decision']) && $this->hasColumn('type')) {
            foreach ($this->decisionToTypeValues($intent['decision']) as $value) {
                $scoreParts[] = "CASE WHEN LOWER(type) LIKE " . $this->quote('%' . strtolower($value) . '%') . " THEN 120 ELSE 0 END";
            }
        }

        if (empty($scoreParts)) {
            if ($this->hasColumn('id')) {
                $query->orderByDesc('id');
            }
            return;
        }

        $query->select('*')
            ->selectRaw('(' . implode(' + ', $scoreParts) . ') as relevance_score')
            ->orderByDesc('relevance_score');

        if ($this->hasColumn('id')) {
            $query->orderByDesc('id');
        }
    }

    protected function formatProduct($product, array $intent): array
    {
        $typeRaw = strtolower(trim((string) ($product->type ?? '')));
        $decision = $this->normalizeDecision($typeRaw);
        $imagePath = $this->cleanText((string) ($product->image ?? ''));
        $productBarcodeRaw = $this->hasColumn('barcode') ? $this->cleanText((string) ($product->barcode ?? '')) : null;
        $normalizedProductBarcode = $this->normalizeBarcode($productBarcodeRaw);

        return [
            'id' => $product->id,
            'user_id' => $this->hasColumn('user_id') ? $product->user_id : null,
            'name' => $this->cleanText((string) ($product->name ?? '')),
            'image' => $imagePath ?: null,
            'image_url' => $this->makeImageUrl($imagePath),
            'barcode' => $productBarcodeRaw,
            'barcode_digits' => $normalizedProductBarcode['digits'],
            'upid' => $this->hasColumn('upid') ? $this->cleanText((string) ($product->upid ?? '')) : null,
            'main_category' => $this->hasColumn('main_category') ? $this->cleanText((string) ($product->main_category ?? '')) : null,
            'main_category1' => $this->hasColumn('main_category1') ? $this->cleanText((string) ($product->main_category1 ?? '')) : null,
            'category' => $this->hasColumn('category') ? $this->cleanText((string) ($product->category ?? '')) : null,
            'categories' => $this->hasColumn('categories') ? $this->cleanText((string) ($product->categories ?? '')) : null,
            'brand' => $this->hasColumn('brand') ? $this->cleanText((string) ($product->brand ?? '')) : null,
            'origin' => $this->hasColumn('origin') ? $this->cleanText((string) ($product->origin ?? '')) : null,
            'description' => $this->hasColumn('description') ? $this->cleanText((string) ($product->description ?? '')) : null,
            'ingredients' => $this->hasColumn('ingredients') ? $this->cleanText((string) ($product->ingredients ?? '')) : null,
            'notes' => $this->hasColumn('notes') ? $this->cleanText((string) ($product->notes ?? '')) : null,
            'allergens' => $this->hasColumn('allergens') ? $this->cleanText((string) ($product->allergens ?? '')) : null,
            'type' => $typeRaw ?: null,
            'decision' => $decision,
            'match_context' => [
                'matched_decision_filter' => !empty($intent['decision']) ? $intent['decision'] : null,
                'matched_ingredient_terms' => !empty($intent['search_ingredients']) ? $intent['ingredient_terms'] : [],
                'matched_origin' => $intent['origin'] ?? null,
                'preferred_origin' => $intent['preferred_origin'] ?? null,
                'matched_mode' => $intent['mode'] ?? 'search',
                'matched_category' => $intent['category_term'] ?? null,
                'matched_category_aliases' => $intent['category_aliases'] ?? [],
                'matched_barcode_variants' => $intent['barcode_variants'] ?? [],
                'search_strategy' => $intent['search_strategy'] ?? null,
                'exact_match_confident' => $intent['exact_match_confident'] ?? false,
            ],
            'product_url' => 'https://www.mustakshif.com/list-of-products?product_id=' . $product->id,
            'listing_url' => 'https://www.mustakshif.com/list-of-products',
        ];
    }

    protected function normalizeDecision(string $value): string
    {
        return match (true) {
            in_array($value, ['halal', 'permissible'], true) => 'halal',
            $value === 'haram' => 'haram',
            in_array($value, ['mashbooh', 'doubtful', 'decision pending', 'pending'], true) => 'mashbooh',
            default => 'mashbooh',
        };
    }

    protected function decisionToTypeValues(string $decision): array
    {
        return match ($decision) {
            'halal' => ['halal', 'permissible'],
            'haram' => ['haram'],
            'mashbooh' => ['mashbooh', 'decision pending', 'pending', 'doubtful'],
            default => [],
        };
    }

    protected function determineLookupKind(array $intent): string
    {
        if (($intent['mode'] ?? '') === 'ingredient_explainer') {
            return 'ingredient_explainer';
        }

        if (!empty($intent['barcode'])) {
            return 'barcode';
        }

        if (!empty($intent['product_name'])) {
            return 'specific_product';
        }

        if (!empty($intent['ingredient_terms']) && !empty($intent['search_ingredients'])) {
            return 'ingredient_search';
        }

        if (!empty($intent['brand'])) {
            return 'brand_search';
        }

        if (!empty($intent['category_term'])) {
            return 'category_search';
        }

        return 'general_search';
    }

    protected function buildSearchSummary(array $intent): string
    {
        $parts = [];

        if (!empty($intent['decision'])) {
            $parts[] = $intent['decision'];
        }
        if (!empty($intent['product_name'])) {
            $parts[] = 'product: ' . $intent['product_name'];
        }
        if (!empty($intent['brand'])) {
            $parts[] = 'brand: ' . $intent['brand'];
        }
        if (!empty($intent['category_term'])) {
            $parts[] = 'category: ' . $intent['category_term'];
        }
        if (!empty($intent['origin'])) {
            $parts[] = 'origin: ' . $intent['origin'];
        } elseif (!empty($intent['preferred_origin'])) {
            $parts[] = 'preferred_origin: ' . $intent['preferred_origin'];
        }
        if (!empty($intent['barcode'])) {
            $parts[] = 'barcode: ' . $intent['barcode'];
        }
        if (!empty($intent['ingredient_terms'])) {
            $parts[] = 'ingredients: ' . implode(', ', $intent['ingredient_terms']);
        }
        if (!empty($intent['search_strategy'])) {
            $parts[] = 'strategy: ' . $intent['search_strategy'];
        }

        return empty($parts) ? 'general product search' : implode(' | ', $parts);
    }

    protected function normalizeCategoryTerm(string $term): ?string
    {
        $term = strtolower(trim($term));
        $term = preg_replace('/\s+/', ' ', $term);

        if ($term === '') {
            return null;
        }

        foreach ($this->categoryAliasMap() as $canonical => $aliases) {
            if ($term === $canonical || in_array($term, $aliases, true)) {
                return $canonical;
            }
        }

        return $term;
    }

    protected function inferCategoryFromText(string $text): ?string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/\s+/', ' ', $text);

        if ($text === '') {
            return null;
        }

        foreach ($this->categoryAliasMap() as $canonical => $aliases) {
            foreach (array_merge([$canonical], $aliases) as $alias) {
                $pattern = '/(^|[^a-z0-9])' . preg_quote(strtolower($alias), '/') . '([^a-z0-9]|$)/i';
                if (preg_match($pattern, $text)) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    protected function expandCategoryAliases(string $canonical): array
    {
        $values = array_merge([$canonical], $this->categoryAliasMap()[$canonical] ?? []);
        return array_values(array_unique(array_filter(array_map(fn ($v) => strtolower(trim($v)), $values))));
    }

    protected function buildCategorySearchTerms(array $intent): array
    {
        $terms = [];
        if (!empty($intent['category_term'])) {
            $terms[] = strtolower($intent['category_term']);
        }
        foreach (($intent['category_aliases'] ?? []) as $alias) {
            $terms[] = strtolower($alias);
        }

        $expanded = [];
        foreach (array_values(array_unique(array_filter($terms))) as $term) {
            $expanded[] = $term;
            foreach ($this->tokenize($term) as $token) {
                if (strlen($token) >= 3) {
                    $expanded[] = $token;
                }
            }
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    protected function categoryAliasMap(): array
    {
        return [
            'beverages' => ['beverage', 'drink', 'drinks', 'cold drink', 'cold drinks', 'soft drink', 'soft drinks', 'soda', 'sodas', 'juice', 'juices', 'energy drink', 'energy drinks'],
            'chocolates' => ['chocolate', 'chocolates', 'chocolate bar', 'chocolate bars', 'cocoa', 'candy chocolate'],
            'biscuits' => ['biscuit', 'biscuits', 'cookies', 'cookie'],
            'candies' => ['candy', 'candies', 'sweets', 'sweet', 'confectionery'],
            'snacks' => ['snack', 'snacks', 'chips', 'crisps', 'namkeen'],
            'dairy' => ['dairy', 'milk product', 'milk products', 'cheese', 'yogurt', 'yoghurt', 'butter', 'cream'],
            'rice' => ['rice', 'rice product', 'rice products'],
            'spices' => ['spice', 'spices', 'seasoning', 'seasonings', 'masala', 'masalay'],
            'pickles' => ['pickle', 'pickles'],
            'pizza' => ['pizza', 'pizzas'],
        ];
    }

    protected function normalizeBarcode(?string $text): array
    {
        $raw = $this->cleanText($text);
        if (!$raw) {
            return ['raw' => null, 'digits' => null, 'variants' => []];
        }

        $raw = trim((string) $raw);
        $variants = [$raw, strtolower($raw), str_replace(' ', '', $raw)];

        $scientificExpanded = $this->expandScientificNotationToDigits($raw);
        if ($scientificExpanded) {
            $variants[] = $scientificExpanded;
        }

        $digitsOnly = preg_replace('/\D+/', '', $scientificExpanded ?: $raw);
        if ($digitsOnly !== '') {
            $variants[] = $digitsOnly;
        }

        return [
            'raw' => $raw,
            'digits' => $digitsOnly !== '' ? $digitsOnly : null,
            'variants' => array_values(array_unique(array_filter($variants))),
        ];
    }

    protected function buildBarcodeSearchCandidates(?string $rawBarcode, array $variants = []): array
    {
        $values = [];
        if ($rawBarcode) {
            $values[] = $rawBarcode;
            $values[] = strtolower($rawBarcode);
            $values[] = str_replace(' ', '', $rawBarcode);
        }
        foreach ($variants as $variant) {
            if (is_string($variant) && trim($variant) !== '') {
                $values[] = trim($variant);
            }
        }
        $digits = preg_replace('/\D+/', '', implode('', $values));
        if ($digits !== '') {
            $values[] = $digits;
        }
        return array_values(array_unique(array_filter($values)));
    }

    protected function expandScientificNotationToDigits(string $value): ?string
    {
        $value = trim(str_replace(' ', '', $value));
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^[\+\-]?\d+(?:\.\d+)?(?:[eE][\+\-]?\d+)$/', $value)) {
            $digits = preg_replace('/\D+/', '', $value);
            return $digits !== '' ? $digits : null;
        }

        preg_match('/^([\+\-]?)(\d+)(?:\.(\d+))?[eE]([\+\-]?\d+)$/', $value, $matches);
        $intPart = $matches[2] ?? '';
        $fracPart = $matches[3] ?? '';
        $exponent = (int) ($matches[4] ?? 0);

        $digits = $intPart . $fracPart;
        $decimalIndex = strlen($intPart);
        $newIndex = $decimalIndex + $exponent;

        if ($newIndex <= 0) {
            $result = '0.' . str_repeat('0', abs($newIndex)) . $digits;
        } elseif ($newIndex >= strlen($digits)) {
            $result = $digits . str_repeat('0', $newIndex - strlen($digits));
        } else {
            $result = substr($digits, 0, $newIndex) . '.' . substr($digits, $newIndex);
        }

        $resultDigits = preg_replace('/\D+/', '', $result);
        return $resultDigits !== '' ? $resultDigits : null;
    }

    protected function normalizeCountry(?string $country): ?string
    {
        $country = $this->cleanText($country);
        if (!$country) {
            return null;
        }

        $normalized = strtolower(trim($country));
        $normalized = str_replace(['_', '-'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        $map = [
            'pk' => 'Pakistan', 'pak' => 'Pakistan', 'pakistan' => 'Pakistan', 'pakistani' => 'Pakistan',
            'uk' => 'United Kingdom', 'u.k' => 'United Kingdom', 'gb' => 'United Kingdom', 'gbr' => 'United Kingdom', 'britain' => 'United Kingdom', 'great britain' => 'United Kingdom', 'england' => 'United Kingdom', 'united kingdom' => 'United Kingdom',
            'us' => 'United States', 'u.s' => 'United States', 'usa' => 'United States', 'america' => 'United States', 'american' => 'United States', 'united states' => 'United States',
            'uae' => 'United Arab Emirates', 'emirates' => 'United Arab Emirates', 'united arab emirates' => 'United Arab Emirates',
            'ksa' => 'Saudi Arabia', 'saudi' => 'Saudi Arabia', 'saudi arabia' => 'Saudi Arabia',
            'australia' => 'Australia', 'australian' => 'Australia', 'canada' => 'Canada', 'morocco' => 'Morocco', 'france' => 'France', 'germany' => 'Germany', 'italy' => 'Italy', 'spain' => 'Spain', 'turkey' => 'Turkey', 'china' => 'China', 'india' => 'India',
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        foreach ($map as $alias => $canonical) {
            if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', $normalized)) {
                return $canonical;
            }
        }

        return ucwords($normalized);
    }

    protected function hasColumn(string $column): bool
    {
        if ($this->tableColumnsCache === null) {
            $this->tableColumnsCache = Schema::getColumnListing($this->table);
        }
        return in_array($column, $this->tableColumnsCache, true);
    }

    protected function makeImageUrl(?string $image): ?string
    {
        if (!$image) {
            return null;
        }
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return $image;
        }
        return asset('storage/' . ltrim($image, '/'));
    }

    protected function tokenize(string $text): array
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        $stopWords = [
            'is', 'are', 'the', 'a', 'an', 'of', 'for', 'to', 'in', 'on', 'with',
            'show', 'give', 'tell', 'suggest', 'recommend', 'some', 'any', 'there', 'all',
            'products', 'product', 'item', 'items', 'list', 'which', 'what',
            'me', 'you', 'know', 'please', 'find', 'check', 'about', 'from',
            'under', 'related', 'contains', 'contain', 'containing', 'having', 'include', 'including',
            'ingredient', 'ingredients', 'made', 'make', 'has', 'have',
            'halal', 'haram', 'mashbooh', 'want', 'need', 'available', 'your',
            'showing', 'suggestion', 'suggestions', 'tellme', 'this', 'that', 'it',
            'kya', 'ye', 'yeh', 'hai', 'ka', 'ki', 'ke', 'or', 'aur'
        ];

        $tokens = array_values(array_filter(explode(' ', $text), function ($token) use ($stopWords) {
            return $token !== '' && !in_array($token, $stopWords, true) && strlen($token) >= 2;
        }));

        return array_values(array_unique($tokens));
    }

    protected function meaningfulProductTokens(string $text): array
    {
        return array_values(array_filter($this->tokenize($text), function ($token) {
            return strlen($token) >= 2 && !$this->looksGenericProductPhrase($token);
        }));
    }

    protected function normalizeStringArray($values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(function ($item) {
            return $this->normalizeFreeText($this->cleanText((string) $item));
        }, $values))));
    }

    protected function normalizeFreeText(?string $text): ?string
    {
        $text = $this->cleanText($text);
        if ($text === null) {
            return null;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);
        return $text !== '' ? $text : null;
    }

    protected function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim($text);
        if ($text === '' || in_array(strtolower($text), ['null', 'n/a', 'na'], true)) {
            return null;
        }
        return $text;
    }

    protected function compact(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/i', '', strtolower($value));
    }

    protected function compactColumnSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(LOWER(COALESCE($column, '')), ' ', ''), '-', ''), '.', '')";
    }

    protected function compactConcatBrandNameSql(): string
    {
        return "REPLACE(REPLACE(REPLACE(LOWER(CONCAT(COALESCE(brand, ''), COALESCE(name, ''))), ' ', ''), '-', ''), '.', '')";
    }

    protected function normalizedBarcodeSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(CAST($column AS CHAR)), ' ', ''), '-', ''), '.', ''), '+', ''), '/', '')";
    }

    protected function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function combinedSearchTextSql(array $columns): ?string
    {
        $available = [];
        foreach ($columns as $column) {
            if ($this->hasColumn($column)) {
                $available[] = "COALESCE($column, '')";
            }
        }
        return empty($available) ? null : 'LOWER(CONCAT(' . implode(", ' ', ", $available) . '))';
    }

    protected function looksGenericProductPhrase(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return true;
        }

        $generic = [
            'product', 'products', 'item', 'items', 'food', 'thing', 'stuff', 'pack', 'bottle', 'can'
        ];

        return in_array($normalized, $generic, true);
    }

    protected function extractLeadingBrandGuess(string $productName): string
    {
        $tokens = $this->meaningfulProductTokens($productName);
        if (empty($tokens)) {
            return '';
        }
        return implode(' ', array_slice($tokens, 0, min(2, count($tokens))));
    }

    protected function notFoundWithSuggestions(array $intent): array
    {
        $suggestions = $this->buildSuggestions($intent);

        return [
            'status' => 'not_found',
            'message' => empty($suggestions) ? 'No matching product was found in the current database.' : 'No exact product was found, but related products were found.',
            'intent' => $intent,
            'meta' => [
                'result_count' => 0,
                'search_summary' => $this->buildSearchSummary($intent),
                'lookup_kind' => $this->determineLookupKind($intent),
                'category_aliases' => $intent['category_aliases'] ?? [],
                'barcode_variants' => $intent['barcode_variants'] ?? [],
                'preferred_origin_applied' => !empty($intent['preferred_origin']) && empty($intent['origin']),
                'same_family_suggestions' => $suggestions,
                'same_family_count' => count($suggestions),
                'universal_match_flow' => empty($suggestions) ? 'no_reliable_match' : 'same_category_only',
                'top_stage' => empty($suggestions) ? 0 : 100,
                'search_strategy' => $intent['search_strategy'] ?? null,
            ],
            'products' => [],
        ];
    }

    protected function buildSuggestions(array $intent): array
    {
        $query = Product::query();

        if (!empty($intent['brand'])) {
            $this->applyBrandFilter($query, $intent);
        } elseif (!empty($intent['product_name'])) {
            $tokens = $this->meaningfulProductTokens($intent['product_name']);
            $allTextSql = $this->combinedSearchTextSql(['name', 'brand', 'description', 'category', 'categories', 'main_category', 'main_category1']);

            if ($allTextSql && !empty($tokens)) {
                $query->where(function (Builder $q) use ($tokens, $allTextSql) {
                    foreach (array_slice($tokens, 0, 2) as $index => $token) {
                        if ($index === 0) {
                            $q->whereRaw($allTextSql . ' LIKE ?', ['%' . $token . '%']);
                        } else {
                            $q->orWhereRaw($allTextSql . ' LIKE ?', ['%' . $token . '%']);
                        }
                    }
                });
            }
        } elseif (!empty($intent['category_term'])) {
            $this->applyCategoryFilter($query, $intent);
        } else {
            $keywords = $intent['keywords'] ?? $this->tokenize((string) ($intent['query'] ?? ''));
            $allTextSql = $this->combinedSearchTextSql(['name', 'brand', 'description', 'category', 'categories', 'main_category', 'main_category1']);
            if ($allTextSql && !empty($keywords)) {
                $query->where(function (Builder $q) use ($keywords, $allTextSql) {
                    foreach (array_slice($keywords, 0, 2) as $index => $token) {
                        if ($index === 0) {
                            $q->whereRaw($allTextSql . ' LIKE ?', ['%' . strtolower($token) . '%']);
                        } else {
                            $q->orWhereRaw($allTextSql . ' LIKE ?', ['%' . strtolower($token) . '%']);
                        }
                    }
                });
            }
        }

        $this->applyOriginFilter($query, $intent);
        $this->applyRanking($query, $intent);

        return $query->limit(8)->get()->map(fn ($product) => $this->formatProduct($product, $intent))->values()->all();
    }

    protected function notFound(array $intent = [], string $message = 'Product information is not available yet.'): array
    {
        return [
            'status' => 'not_found',
            'message' => $message,
            'intent' => $intent,
            'meta' => [
                'result_count' => 0,
                'search_summary' => !empty($intent) ? $this->buildSearchSummary($intent) : null,
                'lookup_kind' => !empty($intent) ? $this->determineLookupKind($intent) : 'unknown',
                'category_aliases' => $intent['category_aliases'] ?? [],
                'barcode_variants' => $intent['barcode_variants'] ?? [],
                'preferred_origin_applied' => !empty($intent['preferred_origin']) && empty($intent['origin']),
                'search_strategy' => $intent['search_strategy'] ?? null,
            ],
            'products' => [],
        ];
    }
}
