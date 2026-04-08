<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected array $productAliases = [
        'dairymilk' => 'dairy milk',
        'dairymilk' => 'dairy milk',
        'cadburydairymilk' => 'cadbury dairy milk',
        'cadburydairymilk' => 'cadbury dairy milk',
        'cadburydairymilk' => 'cadbury dairy milk',
        'cadbury dairy milk' => 'cadbury dairy milk',
        'cocacola' => 'coca cola',
        'coca-cola' => 'coca cola',
        'pepsimax' => 'pepsi max',
        'pepsi max' => 'pepsi max',
        '7up' => '7 up',
        'kitkat' => 'kit kat',
        'mnm' => 'm&m',
        'mnms' => 'm&m',
        'mnm s' => 'm&m',
        'lays' => 'lays',
        'nestle' => 'nestle',
        'nesle' => 'nestle',
        'pepsi' => 'pepsi',
        'coke' => 'coca cola',
        'cocacola' => 'coca cola',
        'mirinda' => 'mirinda',
        'sprite' => 'sprite',
    ];

    public function __construct(
        protected ProductLookupService $productLookupService,
        protected GeminiImageResolverService $geminiImageResolverService
    ) {
    }

    public function handleChat(
        string $message,
        array $history = [],
        ?UploadedFile $image = null,
        ?string $preferredOrigin = null
    ): array {
        try {
            $message = trim($message);
            $trimmedHistory = $this->trimHistory($history);
            $imageContext = null;

            if ($image) {
                try {
                    $imageContext = $this->normalizeImageContext(
                        $this->geminiImageResolverService->extractFromImage($image, $message)
                    );
                } catch (\Throwable $e) {
                    Log::warning('Image extraction failed, continuing with text flow.', [
                        'message' => $e->getMessage(),
                    ]);
                    $imageContext = null;
                }
            }

            $toolArgs = $this->resolveIntent($message, $trimmedHistory, $imageContext, $preferredOrigin);

            if (!$toolArgs || !is_array($toolArgs)) {
                return $this->response(
                    'I could not fully understand your request. Please share a product name, barcode, category, ingredient, or a clearer image.',
                    [
                        'status' => 'not_found',
                        'message' => 'Unable to prepare a valid search intent.',
                        'products' => [],
                        'meta' => [
                            'image_context' => $imageContext,
                        ],
                    ]
                );
            }

            $lookupData = $this->runLookupWithFallbacks($toolArgs, $message, $imageContext, $preferredOrigin);
            $lookupData['meta']['image_context'] = $imageContext;
            $lookupData['intent'] = $toolArgs;

            $lookupData = $this->refineLookupResults($lookupData, $toolArgs, $message, $imageContext);

            $reply = $this->buildReplyFromLookupData(
                $message,
                $lookupData,
                $imageContext
            );

            return $this->response($reply, $lookupData);
        } catch (\Throwable $e) {
            Log::error('GeminiService failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->response(
                'Sorry, something went wrong while checking the product database. Please try again.',
                [
                    'status' => 'error',
                    'message' => 'Something went wrong while checking the product database.',
                    'products' => [],
                ]
            );
        }
    }

    protected function resolveIntent(
        string $message,
        array $history = [],
        ?array $imageContext = null,
        ?string $preferredOrigin = null
    ): ?array {
        $normalizedMessage = $this->normalizeUserText($message);
        $lower = strtolower($normalizedMessage);

        $barcodeFromText = $this->extractBarcodeLikeValue($normalizedMessage);
        $followUpProduct = $this->extractLastProductQueryFromHistory($history);
        $decision = $this->extractDecision($lower);
        $directProductName = $this->extractExplicitProductName($normalizedMessage);
        $ingredient = $this->extractIngredientTerm($lower);
        $origin = $this->extractOrigin($normalizedMessage);
        $category = $this->extractKnownCategory($lower);
        $isListIntent = $this->isListIntent($lower);

        if (!empty($imageContext['barcode'])) {
            return $this->buildArgs([
                'mode' => 'barcode_lookup',
                'barcode' => $imageContext['barcode'],
                'query' => 'image barcode lookup',
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
            ]);
        }

        if ($barcodeFromText !== null) {
            return $this->buildArgs([
                'mode' => 'barcode_lookup',
                'barcode' => $barcodeFromText,
                'query' => 'barcode lookup from prompt',
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
            ]);
        }

        if ($this->isProductInfoFollowUpRequest($lower) && $followUpProduct) {
            return $this->buildArgs([
                'mode' => 'specific_product',
                'product_name' => $followUpProduct,
                'query' => 'follow up product lookup',
                'keywords' => $this->tokenizeMeaningful($followUpProduct),
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
                'origin' => $origin,
            ]);
        }

        if (!empty($imageContext['product_name'])) {
            return $this->buildArgs([
                'mode' => 'search',
                'product_name' => $imageContext['product_name'],
                'brand' => $imageContext['brand'] ?? '',
                'query' => (string) $imageContext['product_name'],
                'keywords' => array_values(array_unique(array_filter(array_merge(
                    $this->tokenizeMeaningful($imageContext['product_name']),
                    !empty($imageContext['brand']) ? $this->tokenizeMeaningful((string) $imageContext['brand']) : []
                )))),
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
                'origin' => $origin,
                'limit' => 12,
            ]);
        }

        if ($ingredient && $this->isIngredientExplainerRequest($lower) && !$this->isIngredientLookupRequest($lower)) {
            return $this->buildArgs([
                'mode' => 'ingredient_explainer',
                'ingredient_terms' => [$ingredient],
                'search_ingredients' => false,
                'query' => 'ingredient explanation',
                'keywords' => [$ingredient],
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
            ]);
        }

        if ($ingredient && $this->isIngredientLookupRequest($lower)) {
            return $this->buildArgs([
                'mode' => $isListIntent || $category ? 'search' : 'ingredient_lookup',
                'ingredient_terms' => [$ingredient],
                'search_ingredients' => true,
                'origin' => $origin,
                'category_term' => $category ?: '',
                'query' => $normalizedMessage,
                'keywords' => array_values(array_unique(array_filter(array_merge(
                    [$ingredient],
                    $category ? [$category] : [],
                    $this->tokenizeMeaningful($normalizedMessage)
                )))),
                'preferred_origin' => $preferredOrigin ?: '',
                'limit' => $this->determineRequestedLimit($normalizedMessage),
                'decision' => $decision,
            ]);
        }

        if ($this->shouldTreatAsOriginDiscoveryRequest($normalizedMessage, $origin, $category, $ingredient, $directProductName)) {
            return $this->buildArgs([
                'mode' => 'search',
                'decision' => $decision,
                'origin' => $origin,
                'query' => $normalizedMessage,
                'keywords' => $this->tokenizeMeaningful($normalizedMessage),
                'preferred_origin' => $preferredOrigin ?: '',
                'limit' => $this->determineRequestedLimit($normalizedMessage),
            ]);
        }

        if ($category && $this->soundsLikePureCategoryRequest($normalizedMessage)) {
            return $this->buildArgs([
                'mode' => 'category_list',
                'decision' => $decision,
                'category_term' => $category,
                'origin' => $origin,
                'query' => 'category product search',
                'keywords' => [$category],
                'preferred_origin' => $preferredOrigin ?: '',
                'limit' => $this->determineRequestedLimit($normalizedMessage),
            ]);
        }

        if ($directProductName !== '' && $this->shouldPreferSpecificProduct($normalizedMessage, $directProductName)) {
            return $this->buildArgs([
                'mode' => 'specific_product',
                'product_name' => $directProductName,
                'origin' => $origin,
                'query' => 'specific product exact lookup',
                'keywords' => $this->tokenizeMeaningful($directProductName),
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
            ]);
        }

        $singleKeywordProduct = $this->extractSingleKeywordProductCandidate($normalizedMessage);
        if ($singleKeywordProduct !== '' && !$this->isCountryLikePhrase($singleKeywordProduct)) {
            return $this->buildArgs([
                'mode' => 'specific_product',
                'product_name' => $singleKeywordProduct,
                'origin' => $origin,
                'query' => 'single keyword product lookup',
                'keywords' => $this->tokenizeMeaningful($singleKeywordProduct),
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
            ]);
        }

        if ($category && !$this->shouldForceProductOverCategory($normalizedMessage)) {
            return $this->buildArgs([
                'mode' => 'category_list',
                'decision' => $decision,
                'category_term' => $category,
                'origin' => $origin,
                'query' => 'category product search',
                'keywords' => [$category],
                'preferred_origin' => $preferredOrigin ?: '',
                'limit' => $this->determineRequestedLimit($normalizedMessage),
            ]);
        }

        if (!empty($imageContext['brand']) && $normalizedMessage === '') {
            return $this->buildArgs([
                'mode' => 'brand_list',
                'brand' => $imageContext['brand'],
                'query' => 'brand search from image',
                'keywords' => [$imageContext['brand']],
                'preferred_origin' => $preferredOrigin ?: '',
                'limit' => 12,
                'decision' => $decision,
            ]);
        }

        if ($directProductName !== '') {
            return $this->buildArgs([
                'mode' => 'specific_product',
                'product_name' => $directProductName,
                'origin' => $origin,
                'query' => 'specific product fallback lookup',
                'keywords' => $this->tokenizeMeaningful($directProductName),
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
            ]);
        }

        if ($normalizedMessage !== '') {
            $fastArgs = $this->fastExtractIntent($normalizedMessage, $imageContext, $history, $preferredOrigin);
            if ($fastArgs) {
                return $this->buildArgs($fastArgs + ['decision' => $decision]);
            }

            if (!$imageContext) {
                $geminiArgs = $this->getToolCallFromGemini($normalizedMessage, $history, $imageContext);
                if ($geminiArgs) {
                    if (empty($geminiArgs['origin']) && !empty($preferredOrigin)) {
                        $geminiArgs['preferred_origin'] = $preferredOrigin;
                    }
                    $geminiArgs['limit'] = $this->determineRequestedLimit($normalizedMessage, $geminiArgs['limit'] ?? 8);
                    if (empty($geminiArgs['decision'])) {
                        $geminiArgs['decision'] = $decision;
                    }
                    return $this->buildArgs($geminiArgs);
                }
            }

            return $this->buildArgs([
                'mode' => 'search',
                'query' => $normalizedMessage,
                'keywords' => $this->tokenizeMeaningful($normalizedMessage),
                'origin' => $origin,
                'preferred_origin' => $preferredOrigin ?: '',
                'limit' => $this->determineRequestedLimit($normalizedMessage),
                'decision' => $decision,
            ]);
        }

        return null;
    }

    protected function fastExtractIntent(
        string $message,
        ?array $imageContext = null,
        array $history = [],
        ?string $preferredOrigin = null
    ): ?array {
        $message = trim($message);
        $lower = strtolower($message);
        $decision = $this->extractDecision($lower);

        $barcode = $this->extractBarcodeLikeValue($message);
        if ($barcode !== null) {
            return $this->buildArgs([
                'mode' => 'barcode_lookup',
                'barcode' => $barcode,
                'query' => 'barcode lookup',
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
            ]);
        }

        $ingredient = $this->extractIngredientTerm($lower);
        if ($ingredient && $this->isIngredientLookupRequest($lower)) {
            return $this->buildArgs([
                'mode' => 'ingredient_lookup',
                'ingredient_terms' => [$ingredient],
                'search_ingredients' => true,
                'origin' => $this->extractOrigin($message),
                'query' => 'ingredient lookup',
                'keywords' => [$ingredient],
                'preferred_origin' => $preferredOrigin ?: '',
                'limit' => $this->determineRequestedLimit($message),
                'decision' => $decision,
            ]);
        }

        $productName = $this->extractExplicitProductName($message);
        if ($productName !== '' && $this->shouldPreferSpecificProduct($message, $productName)) {
            return $this->buildArgs([
                'mode' => 'specific_product',
                'product_name' => $productName,
                'origin' => $this->extractOrigin($message),
                'query' => 'specific product lookup',
                'keywords' => $this->tokenizeMeaningful($productName),
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
            ]);
        }

        $category = $this->extractKnownCategory($lower);
        if ($category && !$this->shouldForceProductOverCategory($message)) {
            return $this->buildArgs([
                'mode' => 'category_list',
                'decision' => $decision,
                'category_term' => $category,
                'origin' => $this->extractOrigin($message),
                'query' => 'category lookup',
                'keywords' => [$category],
                'preferred_origin' => $preferredOrigin ?: '',
                'limit' => $this->determineRequestedLimit($message),
            ]);
        }

        if ($this->isBrandListRequest($lower)) {
            $brand = $this->extractPossibleBrand($message);
            if ($brand !== '') {
                return $this->buildArgs([
                    'mode' => 'brand_list',
                    'brand' => $brand,
                    'origin' => $this->extractOrigin($message),
                    'query' => 'brand products',
                    'keywords' => $this->tokenizeMeaningful($brand),
                    'preferred_origin' => $preferredOrigin ?: '',
                    'limit' => $this->determineRequestedLimit($message),
                    'decision' => $decision,
                ]);
            }
        }

        if (!empty($imageContext['product_name'])) {
            return $this->buildArgs([
                'mode' => 'specific_product',
                'product_name' => (string) $imageContext['product_name'],
                'query' => 'specific product from image',
                'keywords' => $this->tokenizeMeaningful((string) $imageContext['product_name']),
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $decision,
            ]);
        }

        return null;
    }

    protected function runLookupWithFallbacks(
        array $toolArgs,
        string $message,
        ?array $imageContext = null,
        ?string $preferredOrigin = null
    ): array {
        $toolArgs = $this->buildArgs($toolArgs);
        $attempts = [];

        $attempts[] = $toolArgs;

        if (($toolArgs['mode'] ?? '') === 'specific_product' && !empty($toolArgs['product_name'])) {
            foreach ($this->buildProductSearchCandidates($toolArgs['product_name']) as $candidate) {
                $attempts[] = $this->buildArgs([
                    'mode' => 'specific_product',
                    'product_name' => $candidate,
                    'brand' => $toolArgs['brand'] ?? '',
                    'query' => 'specific product candidate lookup',
                    'keywords' => $this->tokenizeMeaningful($candidate),
                    'origin' => $toolArgs['origin'] ?? '',
                    'preferred_origin' => $preferredOrigin ?: '',
                    'decision' => $toolArgs['decision'] ?? '',
                    'limit' => 12,
                ]);

                $attempts[] = $this->buildArgs([
                    'mode' => 'search',
                    'query' => $candidate,
                    'keywords' => array_values(array_unique(array_filter(array_merge(
                        $this->tokenizeMeaningful($candidate),
                        !empty($toolArgs['brand']) ? $this->tokenizeMeaningful((string) $toolArgs['brand']) : []
                    )))),
                    'origin' => $toolArgs['origin'] ?? '',
                    'preferred_origin' => $preferredOrigin ?: '',
                    'decision' => $toolArgs['decision'] ?? '',
                    'limit' => 12,
                ]);
            }

            $brandGuess = $toolArgs['brand'] ?? $this->extractBrandFromProductPhrase($toolArgs['product_name']);
            if ($brandGuess !== '') {
                $attempts[] = $this->buildArgs([
                    'mode' => 'brand_list',
                    'brand' => $brandGuess,
                    'origin' => $toolArgs['origin'] ?? '',
                    'query' => 'brand fallback search',
                    'keywords' => $this->tokenizeMeaningful($brandGuess),
                    'preferred_origin' => $preferredOrigin ?: '',
                    'decision' => $toolArgs['decision'] ?? '',
                    'limit' => 12,
                ]);
            }

            $categoryGuess = $this->extractKnownCategory(strtolower($toolArgs['product_name']));
            if ($categoryGuess) {
                $attempts[] = $this->buildArgs([
                    'mode' => 'category_list',
                    'category_term' => $categoryGuess,
                    'query' => 'category fallback search',
                    'keywords' => [$categoryGuess],
                    'origin' => $toolArgs['origin'] ?? '',
                    'preferred_origin' => $preferredOrigin ?: '',
                    'decision' => $toolArgs['decision'] ?? '',
                    'limit' => 12,
                ]);
            }
        }

        if (($toolArgs['mode'] ?? '') === 'barcode_lookup' && !empty($toolArgs['barcode'])) {
            $barcode = $toolArgs['barcode'];
            $digitsOnly = preg_replace('/\D+/', '', $barcode);

            if ($digitsOnly !== '' && $digitsOnly !== $barcode) {
                $attempts[] = $this->buildArgs([
                    'mode' => 'barcode_lookup',
                    'barcode' => $digitsOnly,
                    'query' => 'barcode digits fallback',
                    'preferred_origin' => $preferredOrigin ?: '',
                    'decision' => $toolArgs['decision'] ?? '',
                ]);
            }
        }

        if (!empty($toolArgs['ingredient_terms'])) {
            $ingredient = $toolArgs['ingredient_terms'][0] ?? '';
            if ($ingredient !== '') {
                $attempts[] = $this->buildArgs([
                    'mode' => 'search',
                    'query' => trim(($toolArgs['origin'] ?? '') . ' ' . $ingredient . ' ' . ($toolArgs['category_term'] ?? '')),
                    'keywords' => array_values(array_unique(array_filter(array_merge(
                        [$ingredient],
                        !empty($toolArgs['category_term']) ? [$toolArgs['category_term']] : [],
                        !empty($toolArgs['keywords']) && is_array($toolArgs['keywords']) ? $toolArgs['keywords'] : []
                    )))),
                    'origin' => $toolArgs['origin'] ?? '',
                    'preferred_origin' => $preferredOrigin ?: '',
                    'decision' => $toolArgs['decision'] ?? '',
                    'limit' => 12,
                    'ingredient_terms' => $toolArgs['ingredient_terms'] ?? [],
                    'search_ingredients' => true,
                ]);
            }
        }

        if (!empty($toolArgs['origin']) && !empty($toolArgs['decision'])) {
            $attempts[] = $this->buildArgs([
                'mode' => 'search',
                'query' => trim(($toolArgs['query'] ?? '') . ' origin fallback'),
                'keywords' => !empty($toolArgs['keywords']) && is_array($toolArgs['keywords']) ? $toolArgs['keywords'] : [],
                'origin' => $toolArgs['origin'],
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => '',
                'limit' => 12,
                'category_term' => $toolArgs['category_term'] ?? '',
                'ingredient_terms' => $toolArgs['ingredient_terms'] ?? [],
                'search_ingredients' => $toolArgs['search_ingredients'] ?? false,
            ]);
        }

        if (!empty($imageContext['product_name']) && ($toolArgs['mode'] ?? '') !== 'specific_product') {
            $attempts[] = $this->buildArgs([
                'mode' => 'specific_product',
                'product_name' => $imageContext['product_name'],
                'brand' => $imageContext['brand'] ?? '',
                'query' => 'image product name fallback',
                'keywords' => array_values(array_unique(array_filter(array_merge(
                    $this->tokenizeMeaningful($imageContext['product_name']),
                    !empty($imageContext['brand']) ? $this->tokenizeMeaningful((string) $imageContext['brand']) : []
                )))),
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $toolArgs['decision'] ?? '',
            ]);
        }

        if (!empty($imageContext['brand']) && ($toolArgs['mode'] ?? '') !== 'brand_list') {
            $attempts[] = $this->buildArgs([
                'mode' => 'brand_list',
                'brand' => $imageContext['brand'],
                'query' => 'image brand fallback',
                'keywords' => $this->tokenizeMeaningful($imageContext['brand']),
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $toolArgs['decision'] ?? '',
                'limit' => 12,
            ]);

            $attempts[] = $this->buildArgs([
                'mode' => 'search',
                'query' => (string) $imageContext['brand'],
                'brand' => (string) $imageContext['brand'],
                'keywords' => $this->tokenizeMeaningful((string) $imageContext['brand']),
                'preferred_origin' => $preferredOrigin ?: '',
                'decision' => $toolArgs['decision'] ?? '',
                'limit' => 12,
            ]);
        }

        $attempts = $this->uniqueAttemptList($attempts);

        $bestResult = null;
        $bestScore = -1;
        $relaxedDecisionResult = null;

        foreach ($attempts as $attempt) {
            $result = $this->productLookupService->search($attempt);

            if (($result['status'] ?? 'not_found') !== 'found' || empty($result['products'])) {
                if ($bestResult === null) {
                    $bestResult = $result;
                }
                continue;
            }

            $result['intent'] = $attempt;
            $result = $this->refineLookupResults($result, $attempt, $message, $imageContext);

            if (($result['status'] ?? 'not_found') !== 'found' || empty($result['products'])) {
                continue;
            }

            if (!empty($toolArgs['origin']) && !empty($toolArgs['decision']) && empty($attempt['decision'])) {
                $relaxedDecisionResult = $result;
            }

            $top = $result['products'][0] ?? [];
            $score = $this->scoreResultForIntent($top, $attempt, $message);

            if ($imageContext) {
                $score += $this->scoreImageContextMatch($top, $imageContext);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestResult = $result;
            }
        }

        if ((($bestResult['status'] ?? 'not_found') !== 'found' || empty($bestResult['products'])) && $relaxedDecisionResult) {
            $bestResult = $this->attachAlternativeDecisionResults($bestResult ?? [
                'status' => 'not_found',
                'message' => 'No matching product was found.',
                'products' => [],
                'meta' => [],
                'intent' => $toolArgs,
            ], $relaxedDecisionResult);
        }

        return $bestResult ?? [
            'status' => 'not_found',
            'message' => 'No matching product was found.',
            'products' => [],
            'meta' => [],
            'intent' => $toolArgs,
        ];
    }

    protected function refineLookupResults(
        array $lookupData,
        array $toolArgs,
        string $message,
        ?array $imageContext = null
    ): array {
        $status = $lookupData['status'] ?? 'not_found';
        $products = $lookupData['products'] ?? [];

        if ($status !== 'found' || empty($products)) {
            return $lookupData;
        }

        $mode = $toolArgs['mode'] ?? 'search';
        $decisionFilter = strtolower((string) ($toolArgs['decision'] ?? ''));

        if ($mode === 'barcode_lookup' && !empty($toolArgs['barcode'])) {
            $barcode = $toolArgs['barcode'];
            usort($products, function ($a, $b) use ($barcode) {
                return $this->scoreBarcodeMatch($b, $barcode) <=> $this->scoreBarcodeMatch($a, $barcode);
            });
        }

        if (in_array($mode, ['specific_product', 'search'], true) && !empty($toolArgs['product_name'] ?: $toolArgs['query'])) {
            $needle = $toolArgs['product_name'] ?: $toolArgs['query'];

            usort($products, function ($a, $b) use ($needle) {
                return $this->scoreProductMatch($b, $needle) <=> $this->scoreProductMatch($a, $needle);
            });

            $bestScore = isset($products[0]) ? $this->scoreProductMatch($products[0], $needle) : 0;

            if ($mode === 'specific_product' && $bestScore >= 650) {
                $products = array_values(array_filter($products, function ($product) use ($needle, $bestScore) {
                    return $this->scoreProductMatch($product, $needle) >= max(420, $bestScore - 260);
                }));
            }
        }

        if (!empty($toolArgs['ingredient_terms'])) {
            $ingredient = strtolower((string) ($toolArgs['ingredient_terms'][0] ?? ''));
            if ($ingredient !== '') {
                $products = array_values(array_filter($products, function ($product) use ($ingredient) {
                    $haystack = strtolower(trim(implode(' ', array_filter([
                        (string) ($product['ingredients'] ?? ''),
                        (string) ($product['description'] ?? ''),
                        (string) ($product['name'] ?? ''),
                        (string) ($product['notes'] ?? ''),
                    ]))));
                    return $haystack !== '' && str_contains($haystack, $ingredient);
                }));
            }
        }

        if ($decisionFilter !== '') {
            $filtered = array_values(array_filter($products, function ($product) use ($decisionFilter) {
                return strtolower((string) ($product['decision'] ?? '')) === $decisionFilter;
            }));

            if (!empty($filtered)) {
                $products = $filtered;
            }
        }

        if ($imageContext) {
            usort($products, function ($a, $b) use ($imageContext) {
                return $this->scoreImageContextMatch($b, $imageContext) <=> $this->scoreImageContextMatch($a, $imageContext);
            });

            $bestImageScore = isset($products[0]) ? $this->scoreImageContextMatch($products[0], $imageContext) : 0;

            if (
                empty($imageContext['barcode'])
                && !empty($imageContext['product_name'])
                && $bestImageScore < 250
            ) {
                $lookupData['status'] = 'not_found';
                $lookupData['message'] = 'Image text was detected, but no confident database match was found.';
                $lookupData['products'] = [];
                $lookupData['meta']['result_count'] = 0;
                return $lookupData;
            }
        }

        $limit = max(1, min((int) ($toolArgs['limit'] ?? 8), 20));
        $lookupData['products'] = array_slice($products, 0, $limit);
        $lookupData['meta']['result_count'] = count($lookupData['products']);

        if (empty($lookupData['products'])) {
            $lookupData['status'] = 'not_found';
            $lookupData['message'] = 'No matching product was found after refinement.';
        }

        return $lookupData;
    }

    protected function getToolCallFromGemini(string $message, array $history = [], ?array $imageContext = null): ?array
    {
        $apiKey = config('services.gemini.api_key');
        $model = config('services.gemini.model', 'gemini-2.5-flash');

        if (!$apiKey) {
            throw new \Exception('GEMINI_API_KEY is missing.');
        }

        $historyText = $this->formatHistory($history);
        $imageContextJson = json_encode($imageContext, JSON_UNESCAPED_UNICODE);

        $systemPrompt = <<<TEXT
Extract search_products arguments only.

Rules:
- Return function call only.
- Never answer user directly.
- Modes: specific_product, brand_list, category_list, barcode_lookup, ingredient_lookup, ingredient_explainer, search
- Mixed prompts like "what is this 8886467103469" or "8886467103469 tell me about this" should be barcode_lookup.
- Questions like "is dairymilk halal?", "is coca cola haram?", "dairy milk details", "tell me about dairymilk chocolates" should prefer specific_product.
- For likely product names, prefer specific_product over category_list.
- Category mode is for list intent only, not for detail intent.
- decision only: halal, haram, mashbooh, or empty
- Do not invent facts.
TEXT;

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt],
                        ['text' => "History:\n{$historyText}"],
                        ['text' => "Image:\n" . ($imageContextJson ?: 'null')],
                        ['text' => "Message:\n{$message}"],
                    ],
                ],
            ],
            'tools' => [
                [
                    'functionDeclarations' => [
                        [
                            'name' => 'search_products',
                            'description' => 'Prepare structured product search arguments.',
                            'parameters' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'mode' => ['type' => 'STRING'],
                                    'decision' => ['type' => 'STRING'],
                                    'barcode' => ['type' => 'STRING'],
                                    'brand' => ['type' => 'STRING'],
                                    'product_name' => ['type' => 'STRING'],
                                    'category_term' => ['type' => 'STRING'],
                                    'origin' => ['type' => 'STRING'],
                                    'preferred_origin' => ['type' => 'STRING'],
                                    'ingredient_terms' => [
                                        'type' => 'ARRAY',
                                        'items' => ['type' => 'STRING'],
                                    ],
                                    'search_ingredients' => ['type' => 'BOOLEAN'],
                                    'query' => ['type' => 'STRING'],
                                    'keywords' => [
                                        'type' => 'ARRAY',
                                        'items' => ['type' => 'STRING'],
                                    ],
                                    'limit' => ['type' => 'INTEGER'],
                                ],
                                'required' => ['mode', 'query'],
                            ],
                        ],
                    ],
                ],
            ],
            'toolConfig' => [
                'functionCallingConfig' => [
                    'mode' => 'ANY',
                    'allowedFunctionNames' => ['search_products'],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0,
                'topP' => 0.1,
                'maxOutputTokens' => 180,
            ],
        ];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $httpResponse = Http::timeout(18)->post($url, $payload);

        Log::info('Gemini tool call response', [
            'status' => $httpResponse->status(),
        ]);

        $response = $httpResponse->throw()->json();
        $parts = $response['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $part) {
            if (!isset($part['functionCall'])) {
                continue;
            }

            $functionCall = $part['functionCall'];
            $name = $functionCall['name'] ?? null;
            $args = $functionCall['args'] ?? null;

            if ($name === 'search_products' && is_array($args)) {
                return $this->sanitizeToolArgs($args);
            }
        }

        Log::warning('Gemini did not return valid functionCall', [
            'response' => $response,
        ]);

        return null;
    }

    protected function sanitizeToolArgs(array $args): array
    {
        return $this->buildArgs([
            'mode' => strtolower(trim((string) ($args['mode'] ?? 'search'))),
            'decision' => strtolower(trim((string) ($args['decision'] ?? ''))),
            'barcode' => trim((string) ($args['barcode'] ?? '')),
            'brand' => trim((string) ($args['brand'] ?? '')),
            'product_name' => trim((string) ($args['product_name'] ?? '')),
            'category_term' => trim((string) ($args['category_term'] ?? '')),
            'origin' => trim((string) ($args['origin'] ?? '')),
            'preferred_origin' => trim((string) ($args['preferred_origin'] ?? '')),
            'ingredient_terms' => is_array($args['ingredient_terms'] ?? null) ? $args['ingredient_terms'] : [],
            'search_ingredients' => filter_var($args['search_ingredients'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'query' => trim((string) ($args['query'] ?? 'product search')),
            'keywords' => is_array($args['keywords'] ?? null) ? $args['keywords'] : [],
            'limit' => (int) ($args['limit'] ?? 8),
        ]);
    }

    protected function buildReplyFromLookupData(
        string $userMessage,
        array $lookupData,
        ?array $imageContext = null
    ): string {
        $status = $lookupData['status'] ?? 'not_found';
        $lookupKind = $lookupData['meta']['lookup_kind'] ?? 'unknown';
        $products = $lookupData['products'] ?? [];
        $ingredientExplanation = $lookupData['ingredient_explanation'] ?? null;
        $intent = $lookupData['intent'] ?? [];
        $category = $intent['category_term'] ?? null;
        $origin = $intent['origin'] ?? null;
        $preferredOrigin = $intent['preferred_origin'] ?? null;
        $decisionFilter = $intent['decision'] ?? null;
        $lowerUserMessage = strtolower(trim($this->normalizeUserText($userMessage)));
        $alternativeProducts = $lookupData['meta']['alternative_decision_products'] ?? [];
        $alternativeSummary = $lookupData['meta']['alternative_decision_summary'] ?? null;

        if ($status === 'error') {
            return 'Sorry, something went wrong while checking that. Please try again.';
        }

        if ($status === 'not_found') {
            if (!empty($alternativeProducts) && !empty($origin) && !empty($decisionFilter)) {
                $lines = ["I could not find {$decisionFilter} products from {$origin} in the current database."];

                if ($alternativeSummary) {
                    $lines[] = "I did find other products from {$origin}: {$alternativeSummary}.";
                } else {
                    $grouped = [];
                    foreach (array_slice($alternativeProducts, 0, 6) as $product) {
                        $grouped[] = ($product['name'] ?? 'Unnamed product') . ' (' . ucfirst((string) ($product['decision'] ?? 'mashbooh')) . ')';
                    }
                    if (!empty($grouped)) {
                        $lines[] = 'Closest matches: ' . implode(', ', $grouped) . '.';
                    }
                }

                return implode(' ', $lines);
            }

            if (!empty($imageContext)) {
                return 'I could not confidently match this image with a product in the database. Please send a clearer front-side photo, barcode, or exact product name.';
            }

            if (!empty($intent['ingredient_terms'])) {
                $ingredientText = implode(', ', $intent['ingredient_terms']);
                $parts = ["I could not find products matching ingredient: {$ingredientText}"];

                if (!empty($origin)) {
                    $parts[] = "from {$origin}";
                }

                if (!empty($decisionFilter)) {
                    $parts[] = "with {$decisionFilter} status";
                }

                return implode(' ', $parts) . '.';
            }

            if (!empty($origin) || !empty($category) || !empty($decisionFilter)) {
                $parts = ['I could not find a matching product for this search'];
                if (!empty($category)) {
                    $parts[] = "category {$category}";
                }
                if (!empty($origin)) {
                    $parts[] = "from {$origin}";
                }
                if (!empty($decisionFilter)) {
                    $parts[] = "with {$decisionFilter} status";
                }
                return implode(' ', $parts) . '.';
            }

            return 'I could not find a matching product right now. Please share a more exact product name, barcode, brand, category, ingredient, or origin.';
        }

        if ($lookupKind === 'ingredient_explainer' && is_array($ingredientExplanation)) {
            $name = $ingredientExplanation['ingredient'] ?? 'This ingredient';
            $summary = $ingredientExplanation['summary'] ?? 'This ingredient may need source verification.';
            return "{$name}: {$summary}";
        }

        if (empty($products)) {
            return $lookupData['message'] ?? 'Product information is not available yet.';
        }

        $first = $products[0];

        if ($this->isIngredientsRequest($lowerUserMessage)) {
            return $this->buildIngredientsSummary($first);
        }

        if ($this->isAllergensRequest($lowerUserMessage)) {
            return $this->buildAllergensSummary($first);
        }

        if ($this->isNotesRequest($lowerUserMessage)) {
            return $this->buildNotesSummary($first);
        }

        if ($this->isDecisionStatusQuestion($lowerUserMessage) && !$this->isListIntent($lowerUserMessage)) {
            return $this->buildDecisionAnswer($first, $userMessage);
        }

        if ($this->isDescriptionRequest($lowerUserMessage) || $this->isGeneralProductInfoRequest($lowerUserMessage)) {
            return $this->buildDetailedProductSummary($first);
        }

        if (
            $this->isListIntent($lowerUserMessage)
            || in_array($lookupKind, ['category_search', 'brand_search', 'ingredient_search', 'general_search'], true)
                && !$this->shouldPreferSingleProductReply($lowerUserMessage, $first)
        ) {
            $label = $category ?: 'products';
            $intro = "Here are some {$label} I found";

            if ($decisionFilter) {
                $intro .= " matching {$decisionFilter}";
            }

            if ($origin) {
                $intro .= " from {$origin}";
            } elseif ($preferredOrigin) {
                $intro .= " (showing {$preferredOrigin} products first)";
            }

            $intro .= ":";

            $lines = [$intro];

            foreach (array_slice($products, 0, 10) as $product) {
                $pName = $product['name'] ?? 'Unnamed product';
                $pDecision = ucfirst((string) ($product['decision'] ?? 'mashbooh'));
                $pBrand = $product['brand'] ?? null;
                $pOrigin = $product['origin'] ?? null;

                $meta = [];
                if ($pBrand) {
                    $meta[] = $pBrand;
                }
                if ($pOrigin) {
                    $meta[] = $pOrigin;
                }

                $metaText = !empty($meta) ? ' — ' . implode(' | ', $meta) : '';
                $lines[] = "- {$pName}{$metaText} ({$pDecision})";
            }

            return implode("\n", $lines);
        }

        if (!empty($imageContext['barcode'])) {
            return $this->buildDetailedProductSummary($first, 'I checked the barcode from the image.');
        }

        if (!empty($imageContext['product_name'])) {
            return $this->buildDetailedProductSummary($first, 'I searched using the product name visible in the image.');
        }

        if (($lookupKind === 'barcode') || $this->extractBarcodeLikeValue($userMessage) !== null) {
            return $this->buildDetailedProductSummary($first);
        }

        return $this->buildDecisionAnswer($first, $userMessage);
    }

    protected function buildDecisionAnswer(array $product, string $userMessage = ''): string
    {
        $name = $product['name'] ?? 'This product';
        $brand = $product['brand'] ?? null;
        $origin = trim((string) ($product['origin'] ?? ''));
        $decision = strtolower(trim((string) ($product['decision'] ?? 'mashbooh')));
        $ingredients = trim((string) ($product['ingredients'] ?? ''));
        $notes = trim((string) ($product['notes'] ?? ''));
        $description = trim((string) ($product['description'] ?? ''));

        $label = $brand ? "{$name} by {$brand}" : $name;

        $decisionText = match ($decision) {
            'halal' => "Yes, {$label} is halal according to the database.",
            'haram' => "No, {$label} is haram according to the database.",
            default => "{$label} is mashbooh according to the database.",
        };

        $parts = [$decisionText];

        if ($origin !== '') {
            $parts[] = "Origin: {$origin}.";
        }

        if ($ingredients !== '') {
            $parts[] = "Ingredients: {$ingredients}.";
        }

        if ($notes !== '') {
            $parts[] = "Notes: {$notes}.";
        } elseif ($description !== '') {
            $parts[] = "Description: {$description}.";
        }

        return implode(' ', $parts);
    }

    protected function buildIngredientsSummary(array $product): string
    {
        $name = $product['name'] ?? 'This product';
        $brand = $product['brand'] ?? null;
        $originText = $product['origin'] ?? null;
        $ingredients = trim((string) ($product['ingredients'] ?? ''));
        $decision = ucfirst((string) ($product['decision'] ?? 'mashbooh'));

        $parts = [];
        $parts[] = $brand ? "{$name} by {$brand}" : $name;
        $parts[] = "is marked as {$decision}.";

        if ($originText) {
            $parts[] = "Origin: {$originText}.";
        }

        if ($ingredients !== '') {
            $parts[] = "Ingredients: {$ingredients}.";
        } else {
            $parts[] = "Ingredients are not available in the database.";
        }

        return implode(' ', $parts);
    }

    protected function buildAllergensSummary(array $product): string
    {
        $name = $product['name'] ?? 'This product';
        $brand = $product['brand'] ?? null;
        $originText = $product['origin'] ?? null;
        $allergens = trim((string) ($product['allergens'] ?? ''));
        $decision = ucfirst((string) ($product['decision'] ?? 'mashbooh'));

        $parts = [];
        $parts[] = $brand ? "{$name} by {$brand}" : $name;
        $parts[] = "is marked as {$decision}.";

        if ($originText) {
            $parts[] = "Origin: {$originText}.";
        }

        if ($allergens !== '') {
            $parts[] = "Allergens: {$allergens}.";
        } else {
            $parts[] = "Allergen information is not available in the database.";
        }

        return implode(' ', $parts);
    }

    protected function buildNotesSummary(array $product): string
    {
        $name = $product['name'] ?? 'This product';
        $brand = $product['brand'] ?? null;
        $originText = $product['origin'] ?? null;
        $notes = trim((string) ($product['notes'] ?? ''));
        $decision = ucfirst((string) ($product['decision'] ?? 'mashbooh'));

        $parts = [];
        $parts[] = $brand ? "{$name} by {$brand}" : $name;
        $parts[] = "is marked as {$decision}.";

        if ($originText) {
            $parts[] = "Origin: {$originText}.";
        }

        if ($notes !== '') {
            $parts[] = "Notes: {$notes}.";
        } else {
            $parts[] = "No extra notes are available in the database.";
        }

        return implode(' ', $parts);
    }

    protected function buildDetailedProductSummary(array $product, ?string $prefixText = null): string
    {
        $name = $product['name'] ?? 'This product';
        $brand = $product['brand'] ?? null;
        $origin = trim((string) ($product['origin'] ?? ''));
        $decision = ucfirst((string) ($product['decision'] ?? 'mashbooh'));
        $ingredients = trim((string) ($product['ingredients'] ?? ''));
        $allergens = trim((string) ($product['allergens'] ?? ''));
        $notes = trim((string) ($product['notes'] ?? ''));
        $description = trim((string) ($product['description'] ?? ''));

        $parts = [];

        if ($prefixText) {
            $parts[] = rtrim($prefixText);
        }

        $parts[] = $brand ? "{$name} by {$brand}" : $name;
        $parts[] = "is marked as {$decision}.";

        if ($origin !== '') {
            $parts[] = "Origin: {$origin}.";
        }

        if ($ingredients !== '') {
            $parts[] = "Ingredients: {$ingredients}.";
        }

        if ($allergens !== '') {
            $parts[] = "Allergens: {$allergens}.";
        }

        if ($notes !== '') {
            $parts[] = "Notes: {$notes}.";
        }

        if ($description !== '') {
            $parts[] = "Description: {$description}.";
        }

        return implode(' ', $parts);
    }

    protected function response(string $reply, array $data): array
    {
        return [
            'reply' => $reply,
            'data' => $data,
        ];
    }

    protected function normalizeImageContext(?array $data): ?array
    {
        if (!$data || !is_array($data)) {
            return null;
        }

        $barcode = trim((string) ($data['barcode'] ?? ''));
        $productName = trim((string) ($data['product_name'] ?? ''));
        $brand = trim((string) ($data['brand'] ?? ''));
        $variant = trim((string) ($data['variant'] ?? ''));
        $confidence = strtolower(trim((string) ($data['confidence'] ?? 'low')));
        $notes = trim((string) ($data['notes'] ?? ''));

        if ($barcode !== '') {
            $barcode = $this->normalizeBarcodeValue($barcode);
        }

        return [
            'barcode' => $barcode !== '' ? $barcode : null,
            'product_name' => $productName !== '' ? $this->normalizeDetectedImageProductName($productName) : null,
            'brand' => $brand !== '' ? $this->cleanupProductPhrase($this->applyProductAliases($brand)) : null,
            'variant' => $variant !== '' ? $this->cleanupProductPhrase($variant) : null,
            'confidence' => in_array($confidence, ['high', 'medium', 'low'], true) ? $confidence : 'low',
            'notes' => $notes !== '' ? $notes : null,
        ];
    }


    protected function normalizeDetectedImageProductName(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = $this->cleanupProductPhrase($this->applyProductAliases($value));
        $value = preg_replace('/(pack|packet|bottle|can|jar|box|front|label|product)/i', ' ', $value);
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value !== '' ? $value : null;
    }

    protected function buildArgs(array $args): array
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

        $barcode = $this->normalizeBarcodeValue((string) ($args['barcode'] ?? ''));
        $brand = $this->cleanupProductPhrase((string) ($args['brand'] ?? ''));
        $productName = $this->cleanupProductPhrase($this->applyProductAliases((string) ($args['product_name'] ?? '')));
        $categoryTerm = $this->correctSpelling(trim((string) ($args['category_term'] ?? '')));
        $origin = $this->extractOrigin((string) ($args['origin'] ?? ''));
        $preferredOrigin = $this->extractOrigin((string) ($args['preferred_origin'] ?? ''));

        $ingredientTerms = $args['ingredient_terms'] ?? [];
        if (!is_array($ingredientTerms)) {
            $ingredientTerms = [];
        }

        $ingredientTerms = array_values(array_unique(array_filter(array_map(function ($term) {
            $term = strtolower(trim((string) $term));
            $term = $this->correctSpelling($term);
            return $term !== '' ? $term : null;
        }, $ingredientTerms))));

        $keywords = $args['keywords'] ?? [];
        if (!is_array($keywords)) {
            $keywords = [];
        }

        $keywords = array_values(array_unique(array_filter(array_map(function ($word) {
            $word = trim((string) $word);
            return $word !== '' ? $this->applyProductAliases($this->correctSpelling($word)) : null;
        }, $keywords))));

        $searchIngredients = filter_var($args['search_ingredients'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $query = trim((string) ($args['query'] ?? 'product search'));
        $limit = max(1, min((int) ($args['limit'] ?? 8), 20));

        if ($barcode !== '') {
            $mode = 'barcode_lookup';
        }

        if ($productName !== '' && ($this->isOriginKeyword($productName) || $this->isNationalityKeyword($productName) || $this->isGenericLookupWord($productName))) {
            $productName = '';
        }

        if ($productName !== '' && $mode === 'search') {
            $mode = 'specific_product';
        }

        return [
            'mode' => $mode,
            'decision' => $decision,
            'barcode' => $barcode,
            'brand' => $brand,
            'product_name' => $productName,
            'category_term' => $categoryTerm,
            'origin' => $origin,
            'preferred_origin' => $origin === '' ? $preferredOrigin : '',
            'ingredient_terms' => $ingredientTerms,
            'search_ingredients' => $searchIngredients,
            'query' => $query !== '' ? $query : 'product search',
            'keywords' => $keywords,
            'limit' => $limit,
        ];
    }

    protected function normalizeUserText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace(['“', '”', '’'], ['"', '"', "'"], $text);

        $tokens = explode(' ', $text);
        $tokens = array_map(function ($token) {
            if ($this->looksLikeCodeToken($token)) {
                return $token;
            }

            return $this->applyProductAliases($this->correctSpelling($token));
        }, $tokens);

        return trim(preg_replace('/\s+/', ' ', implode(' ', $tokens)));
    }

    protected function correctSpelling(string $text): string
    {
        $original = trim($text);
        if ($original === '') {
            return '';
        }

        $dictionary = [
            'chocklate' => 'chocolate',
            'chocklates' => 'chocolates',
            'choclate' => 'chocolate',
            'choclates' => 'chocolates',
            'chocalate' => 'chocolate',
            'chocalates' => 'chocolates',
            'biscits' => 'biscuits',
            'biscut' => 'biscuit',
            'coockies' => 'cookies',
            'coockie' => 'cookie',
            'bevrages' => 'beverages',
            'bevrage' => 'beverage',
            'juce' => 'juice',
            'juises' => 'juices',
            'snaks' => 'snacks',
            'spicies' => 'spices',
            'chipss' => 'chips',
            'drniks' => 'drinks',
            'drnik' => 'drink',
            'shuger' => 'sugar',
            'suger' => 'sugar',
            'milkk' => 'milk',
            'gelatine' => 'gelatin',
            'enzymes' => 'enzyme',
            'lecetin' => 'lecithin',
            'alchohol' => 'alcohol',
            'pakstan' => 'pakistan',
            'pakistn' => 'pakistan',
            'pakistani' => 'pakistan',
            'american' => 'america',
            'australian' => 'australia',
            'unted' => 'united',
            'kindom' => 'kingdom',
            'u.k' => 'uk',
            'englandd' => 'england',
            'amerca' => 'america',
            'austrailia' => 'australia',
            'sutralia' => 'australia',
            'dairymilk' => 'dairy milk',
            'cadburydairymilk' => 'cadbury dairy milk',
        ];

        $lower = strtolower($original);
        if (isset($dictionary[$lower])) {
            return $dictionary[$lower];
        }

        if ($this->looksLikeCodeToken($original)) {
            return $original;
        }

        $knownWords = [
            'chocolate', 'chocolates', 'biscuit', 'biscuits', 'cookie', 'cookies', 'beverage',
            'beverages', 'drink', 'drinks', 'juice', 'juices', 'snack', 'snacks', 'dairy',
            'milk', 'rice', 'spice', 'spices', 'sugar', 'gelatin', 'enzyme', 'alcohol',
            'carmine', 'lecithin', 'e471', 'uk', 'united', 'kingdom', 'pakistan', 'uae',
            'saudi', 'arabia', 'halal', 'haram', 'mashbooh', 'cadbury', 'dairy milk',
            'kit kat', 'coca cola', 'pepsi', 'america', 'australia'
        ];

        $best = $lower;
        $bestDistance = 99;

        foreach ($knownWords as $word) {
            $distance = levenshtein($lower, $word);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $word;
            }
        }

        if ($bestDistance <= 2 && strlen($lower) >= 4) {
            return $best;
        }

        return $original;
    }

    protected function applyProductAliases(string $text): string
    {
        $value = strtolower(trim($text));
        if ($value === '') {
            return '';
        }

        $compact = preg_replace('/[^a-z0-9]+/i', '', $value);

        if (isset($this->productAliases[$value])) {
            return $this->productAliases[$value];
        }

        if (isset($this->productAliases[$compact])) {
            return $this->productAliases[$compact];
        }

        return $text;
    }

    protected function extractBarcodeLikeValue(string $text): ?string
    {
        $value = trim($text);
        if ($value === '') {
            return null;
        }

        if (preg_match('/\b\d+(?:\.\d+)?[eE][\+\-]?\d+\b/', $value, $m)) {
            return $this->normalizeBarcodeValue($m[0]);
        }

        if (preg_match('/^\s*([A-Za-z0-9][A-Za-z0-9\-\._\/]{5,30})\s*$/', $value, $single)) {
            $candidate = trim($single[1]);

            if ($this->isLikelyBarcodeToken($candidate)) {
                return $this->normalizeBarcodeValue($candidate);
            }
        }

        preg_match_all('/\b[A-Za-z0-9][A-Za-z0-9\-\._\/]{5,30}\b/', $value, $all);
        $candidates = $all[0] ?? [];

        foreach ($candidates as $candidate) {
            if ($this->isLikelyBarcodeToken($candidate)) {
                return $this->normalizeBarcodeValue($candidate);
            }
        }

        return null;
    }

    protected function normalizeBarcodeValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = str_replace(' ', '', $value);

        if (preg_match('/^[\+\-]?\d+(?:\.\d+)?(?:[eE][\+\-]?\d+)$/', $value)) {
            $expanded = $this->expandScientificNotationToPlain($value);
            return $expanded !== '' ? $expanded : $value;
        }

        return strtoupper($value);
    }

    protected function expandScientificNotationToPlain(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (!preg_match('/^([\+\-]?)(\d+)(?:\.(\d+))?[eE]([\+\-]?\d+)$/', $value, $matches)) {
            return preg_replace('/\s+/', '', $value);
        }

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

        $digitsOnly = preg_replace('/\D+/', '', $result);
        return $digitsOnly !== '' ? $digitsOnly : '';
    }

    protected function isLikelyBarcodeToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        if (preg_match('/^\d{6,24}$/', $token)) {
            return true;
        }

        if (preg_match('/^\d+(?:\.\d+)?[eE][\+\-]?\d+$/', $token)) {
            return true;
        }

        if (!preg_match('/^[A-Z0-9\-_\.\/]{6,30}$/i', $token)) {
            return false;
        }

        $hasDigit = preg_match('/\d/', $token) === 1;
        $hasAlpha = preg_match('/[A-Za-z]/', $token) === 1;

        if ($hasDigit && $hasAlpha) {
            return true;
        }

        return false;
    }

    protected function looksLikeCodeToken(string $token): bool
    {
        return $this->isLikelyBarcodeToken($token)
            || preg_match('/^[A-Za-z0-9\-\._\/]+$/', $token) === 1;
    }

    protected function extractDecision(string $text): string
    {
        if (str_contains($text, 'haram')) {
            return 'haram';
        }

        if (str_contains($text, 'mashbooh')) {
            return 'mashbooh';
        }

        if (str_contains($text, 'halal')) {
            return 'halal';
        }

        return '';
    }

    protected function extractIngredientTerm(string $text): ?string
    {
        $text = strtolower(trim($text));

        if ($text === '') {
            return null;
        }

        $knownIngredients = [
            'gelatin' => ['gelatin', 'gelatine'],
            'e471' => ['e471'],
            'enzyme' => ['enzyme', 'enzymes'],
            'alcohol' => ['alcohol', 'alchohol'],
            'carmine' => ['carmine'],
            'lecithin' => ['lecithin', 'lecetin'],
            'sugar' => ['sugar', 'shuger', 'suger'],
            'milk' => ['milk', 'milks', 'milk powder', 'milk solids'],
            'salt' => ['salt', 'sea salt'],
            'water' => ['water'],
            'vinegar' => ['vinegar'],
            'calcium chloride' => ['calcium chloride'],
        ];

        foreach ($knownIngredients as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', $text)) {
                    return $canonical;
                }
            }
        }

        if (preg_match('/\b(?:contains?|containing|contain|with|having|include|includes|including|has|made with)\s+([a-z0-9][a-z0-9\-\s]{1,60})/i', $text, $m)) {
            $candidate = strtolower(trim($m[1]));
            $candidate = preg_replace('/\b(products?|items?|from|in|of|for|that|which|available|halal|haram|mashbooh|uk|usa|us|america|australia|canada|pakistan|uae|saudi|arabia|united|kingdom|states)\b.*$/i', '', $candidate);
            $candidate = trim(preg_replace('/\s+/', ' ', $candidate));

            if ($candidate !== '' && strlen($candidate) >= 3) {
                return $this->correctSpelling($candidate);
            }
        }

        if (preg_match('/\bingredients?\s+(?:of|with|containing)?\s*([a-z0-9][a-z0-9\-\s]{1,60})/i', $text, $m)) {
            $candidate = strtolower(trim($m[1]));
            $candidate = preg_replace('/\b(products?|items?|from|in|of|for|that|which)\b.*$/i', '', $candidate);
            $candidate = trim(preg_replace('/\s+/', ' ', $candidate));

            if ($candidate !== '' && strlen($candidate) >= 3) {
                return $this->correctSpelling($candidate);
            }
        }

        return null;
    }

    protected function isIngredientExplainerRequest(string $text): bool
    {
        return str_contains($text, 'what is')
            || (str_contains($text, 'is ') && str_contains($text, ' halal'))
            || str_contains($text, 'why')
            || str_contains($text, 'source');
    }

    protected function isIngredientLookupRequest(string $text): bool
    {
        return str_contains($text, 'contain')
            || str_contains($text, 'contains')
            || str_contains($text, 'containing')
            || str_contains($text, 'products with')
            || str_contains($text, 'that contain')
            || str_contains($text, 'having ')
            || str_contains($text, 'include ')
            || str_contains($text, 'includes ')
            || str_contains($text, 'including ')
            || str_contains($text, 'made with ')
            || str_contains($text, 'has ');
    }

    protected function isIngredientsRequest(string $text): bool
    {
        return str_contains($text, 'ingredient')
            || str_contains($text, 'ingredients')
            || str_contains($text, 'show ingredients')
            || str_contains($text, 'list ingredients')
            || str_contains($text, 'what are its ingredients')
            || str_contains($text, 'what is in it')
            || str_contains($text, 'tell me ingredients')
            || str_contains($text, 'tell me about ingredients');
    }

    protected function isAllergensRequest(string $text): bool
    {
        return str_contains($text, 'allergen')
            || str_contains($text, 'allergens')
            || str_contains($text, 'show allergens')
            || str_contains($text, 'what allergens');
    }

    protected function isNotesRequest(string $text): bool
    {
        return str_contains($text, 'notes')
            || str_contains($text, 'any note')
            || str_contains($text, 'extra note')
            || str_contains($text, 'show notes');
    }

    protected function isDescriptionRequest(string $text): bool
    {
        return str_contains($text, 'description')
            || str_contains($text, 'tell me about')
            || str_contains($text, 'what is this')
            || str_contains($text, 'what about this')
            || str_contains($text, 'tell me about this')
            || str_contains($text, 'in detail')
            || str_contains($text, 'details');
    }

    protected function isGeneralProductInfoRequest(string $text): bool
    {
        $phrases = [
            'tell me about this',
            'tell me about it',
            'what about this',
            'what about it',
            'tell me more',
            'more info',
            'more information',
            'product info',
            'details',
            'give details',
            'show details',
            'in detail',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    protected function isDecisionStatusQuestion(string $text): bool
    {
        return str_contains($text, 'halal')
            || str_contains($text, 'haram')
            || str_contains($text, 'mashbooh')
            || str_contains($text, 'permissible')
            || str_contains($text, 'allowed');
    }

    protected function isProductInfoFollowUpRequest(string $text): bool
    {
        return $this->isIngredientsRequest($text)
            || $this->isAllergensRequest($text)
            || $this->isNotesRequest($text)
            || $this->isDescriptionRequest($text)
            || $this->isGeneralProductInfoRequest($text)
            || $this->isDecisionStatusQuestion($text);
    }

    protected function shouldPreferSpecificProduct(string $message, string $productName): bool
    {
        $lower = strtolower($message);

        if ($this->isDecisionStatusQuestion($lower)) {
            return true;
        }

        if ($this->isDescriptionRequest($lower) || $this->isGeneralProductInfoRequest($lower)) {
            return true;
        }

        if (count($this->tokenizeMeaningful($productName)) >= 1 && !$this->soundsLikePureCategoryRequest($lower)) {
            return true;
        }

        return false;
    }

    protected function shouldForceProductOverCategory(string $message): bool
    {
        $lower = strtolower($message);

        return $this->isDecisionStatusQuestion($lower)
            || $this->isDescriptionRequest($lower)
            || $this->isGeneralProductInfoRequest($lower)
            || str_contains($lower, 'tell me about')
            || str_contains($lower, 'check ')
            || str_contains($lower, 'is ');
    }

    protected function shouldPreferSingleProductReply(string $userMessage, array $firstProduct): bool
    {
        if ($this->isListIntent($userMessage)) {
            return false;
        }

        if ($this->isDecisionStatusQuestion($userMessage)) {
            return true;
        }

        if ($this->isDescriptionRequest($userMessage) || $this->isGeneralProductInfoRequest($userMessage)) {
            return true;
        }

        $queryTokens = $this->tokenizeMeaningful($userMessage);
        if (count($queryTokens) <= 3) {
            return true;
        }

        return false;
    }

    protected function extractSingleKeywordProductCandidate(string $message): string
    {
        $clean = trim($message);
        if ($clean === '') {
            return '';
        }

        if ($this->extractBarcodeLikeValue($clean) !== null) {
            return '';
        }

        $tokens = $this->tokenizeMeaningful($clean);
        if (count($tokens) === 1) {
            $token = $this->applyProductAliases($tokens[0]);

            if (
                !$this->isGenericLookupWord($token)
                && !$this->isOriginKeyword($token)
                && !$this->isNationalityKeyword($token)
            ) {
                return $this->cleanupProductPhrase($token);
            }
        }

        return '';
    }

    protected function extractExplicitProductName(string $message): string
    {
        $value = trim($message);
        if ($value === '') {
            return '';
        }

        $lower = strtolower($value);

        if ($this->soundsLikePureCategoryRequest($lower)
            || preg_match('/\b(products?|items?|suggest|recommend|list|show|find)\b/i', $lower)
            || $this->isIngredientLookupRequest($lower)
            || $this->isOriginOnlySearch($lower, $this->extractOrigin($lower))
        ) {
            return '';
        }

        $patterns = [
            '/^\s*(what is this)\s+/i',
            '/^\s*(what is)\s+/i',
            '/^\s*(tell me about this)\s+/i',
            '/^\s*(tell me about)\s+/i',
            '/^\s*(check)\s+/i',
            '/^\s*(is this)\s+/i',
            '/^\s*(please check)\s+/i',
            '/^\s*(can you check)\s+/i',
            '/^\s*(can you tell me about)\s+/i',
            '/^\s*(do you know about)\s+/i',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '', $value);
        }

        $value = preg_replace('/\b(is|are)\b\s+/i', '', $value, 1);
        $value = preg_replace('/\b(halal|haram|mashbooh|permissible|allowed)\b/i', '', $value);
        $value = preg_replace('/\b(from\s+[A-Za-z]{2,}(?:\s+[A-Za-z]{2,})?)\b/i', '', $value);
        $value = preg_replace('/\b(show|list|suggest|recommend|give me|find|some)\b/i', '', $value);
        $value = preg_replace('/\b(products?|items?)\b/i', '', $value);
        $value = preg_replace('/\b(tell me about this|tell me about it|what about this|what about it|details|in detail)\b/i', '', $value);
        $value = preg_replace('/[?]+$/', '', $value);
        $value = trim(preg_replace('/\s+/', ' ', $value));

        if ($value === '' || $this->isCountryLikePhrase($value)) {
            return '';
        }

        if ($this->extractBarcodeLikeValue($value) !== null) {
            return '';
        }

        $tokenCount = count($this->tokenizeMeaningful($value));
        if ($tokenCount === 0) {
            return '';
        }

        $value = $this->applyProductAliases($value);
        $cleanValue = $this->cleanupProductPhrase($value);

        if ($this->isOriginKeyword($cleanValue) || $this->isNationalityKeyword($cleanValue)) {
            return '';
        }

        return $cleanValue;
    }

    protected function cleanupProductPhrase(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value);
        $value = preg_replace('/^[\-\:\,\.\s]+/', '', $value);
        $value = preg_replace('/[\-\:\,\.\s]+$/', '', $value);

        return $value;
    }

    protected function extractLastProductQueryFromHistory(array $history): ?string
    {
        if (empty($history)) {
            return null;
        }

        for ($i = count($history) - 1; $i >= 0; $i--) {
            $item = $history[$i] ?? [];
            $role = strtolower((string) ($item['role'] ?? ''));
            $message = trim((string) ($item['message'] ?? ''));

            if ($role !== 'user' || $message === '') {
                continue;
            }

            $lower = strtolower($message);

            if ($this->isProductInfoFollowUpRequest($lower)) {
                continue;
            }

            $barcode = $this->extractBarcodeLikeValue($message);
            if ($barcode !== null) {
                continue;
            }

            $name = $this->extractExplicitProductName($message);
            if ($name !== '') {
                return $name;
            }

            $single = $this->extractSingleKeywordProductCandidate($message);
            if ($single !== '') {
                return $single;
            }

            if (!$this->soundsAmbiguous($lower)) {
                return $message;
            }
        }

        return null;
    }

    protected function extractKnownCategory(string $text): ?string
    {
        $map = [
            'beverages' => ['drink', 'drinks', 'cold drink', 'cold drinks', 'soft drink', 'soft drinks', 'juice', 'juices', 'beverage', 'beverages', 'soda', 'sodas'],
            'chocolates' => ['chocolate', 'chocolates', 'chocklates', 'chocolats'],
            'biscuits' => ['biscuit', 'biscuits', 'cookie', 'cookies'],
            'candies' => ['candy', 'candies', 'sweets'],
            'snacks' => ['snack', 'snacks', 'chips', 'crisps', 'namkeen'],
            'dairy' => ['dairy', 'milk', 'cheese', 'yogurt', 'yoghurt', 'butter'],
            'rice' => ['rice'],
            'spices' => ['spice', 'spices', 'seasoning', 'masala'],
        ];

        foreach ($map as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($text, $alias)) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    protected function extractOrigin(string $text): string
    {
        $value = strtolower(trim($text));

        if ($value === '') {
            return '';
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $map = [
            'uk' => 'United Kingdom',
            'u.k' => 'United Kingdom',
            'united kingdom' => 'United Kingdom',
            'britain' => 'United Kingdom',
            'great britain' => 'United Kingdom',
            'english' => 'United Kingdom',
            'england' => 'United Kingdom',
            'gb' => 'United Kingdom',

            'uae' => 'United Arab Emirates',
            'emirates' => 'United Arab Emirates',
            'emirati' => 'United Arab Emirates',
            'united arab emirates' => 'United Arab Emirates',

            'pakistan' => 'Pakistan',
            'pakistani' => 'Pakistan',
            'pak' => 'Pakistan',
            'pk' => 'Pakistan',

            'ksa' => 'Saudi Arabia',
            'saudi' => 'Saudi Arabia',
            'saudi arabia' => 'Saudi Arabia',
            'saudiarabian' => 'Saudi Arabia',

            'usa' => 'United States',
            'us' => 'United States',
            'u.s' => 'United States',
            'united states' => 'United States',
            'america' => 'United States',
            'american' => 'United States',

            'australia' => 'Australia',
            'australian' => 'Australia',
            'canada' => 'Canada',
            'canadian' => 'Canada',
            'morocco' => 'Morocco',
            'moroccan' => 'Morocco',
            'france' => 'France',
            'french' => 'France',
            'germany' => 'Germany',
            'german' => 'Germany',
            'italy' => 'Italy',
            'italian' => 'Italy',
            'spain' => 'Spain',
            'spanish' => 'Spain',
            'turkey' => 'Turkey',
            'turkish' => 'Turkey',
            'china' => 'China',
            'chinese' => 'China',
            'india' => 'India',
            'indian' => 'India',
        ];

        if (isset($map[$value])) {
            return $map[$value];
        }

        if (preg_match('/\bfrom\s+([A-Za-z]{2,}(?:\s+[A-Za-z]{2,}){0,2})\b/i', $text, $matches)) {
            $candidate = strtolower(trim($matches[1]));
            $candidate = preg_replace('/\s+/', ' ', $candidate);
            return $map[$candidate] ?? ucwords($candidate);
        }

        foreach ($map as $alias => $country) {
            if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', $value)) {
                return $country;
            }
        }

        return '';
    }

    protected function isBrandListRequest(string $text): bool
    {
        return str_contains($text, 'brand')
            || str_contains($text, 'products by')
            || str_contains($text, 'show ')
            || str_contains($text, 'list ')
            || str_contains($text, 'suggest ');
    }

    protected function extractPossibleBrand(string $text): string
    {
        $value = trim($text);
        if ($value === '') {
            return '';
        }

        if (preg_match('/\bby\s+([A-Za-z][A-Za-z0-9\-\&\s]{1,60})$/i', $value, $m)) {
            return $this->cleanupProductPhrase($m[1]);
        }

        if (preg_match('/\bbrand\s+([A-Za-z][A-Za-z0-9\-\&\s]{1,60})$/i', $value, $m)) {
            return $this->cleanupProductPhrase($m[1]);
        }

        return '';
    }

    protected function extractBrandFromProductPhrase(string $text): string
    {
        $tokens = $this->tokenizeMeaningful($text);
        if (empty($tokens)) {
            return '';
        }

        $brandTokens = array_slice($tokens, 0, min(2, count($tokens)));
        return $this->cleanupProductPhrase(implode(' ', $brandTokens));
    }

    protected function scoreBarcodeMatch(array $product, string $barcode): int
    {
        $productBarcode = strtoupper((string) ($product['barcode'] ?? ''));
        $productDigits = preg_replace('/\D+/', '', $productBarcode);

        $needle = strtoupper($this->normalizeBarcodeValue($barcode));
        $needleDigits = preg_replace('/\D+/', '', $needle);

        $score = 0;

        if ($productBarcode !== '' && $productBarcode === $needle) {
            $score += 1500;
        }

        if ($needleDigits !== '' && $productDigits !== '' && $productDigits === $needleDigits) {
            $score += 1300;
        }

        if ($productBarcode !== '' && str_contains($productBarcode, $needle)) {
            $score += 600;
        }

        if ($needleDigits !== '' && $productDigits !== '' && str_contains($productDigits, $needleDigits)) {
            $score += 500;
        }

        return $score;
    }

    protected function scoreProductMatch(array $product, string $needle): int
    {
        $nameRaw = trim((string) ($product['name'] ?? ''));
        $brandRaw = trim((string) ($product['brand'] ?? ''));
        $descRaw = trim((string) ($product['description'] ?? ''));

        $name = $this->normalizeForMatching($nameRaw);
        $brand = $this->normalizeForMatching($brandRaw);
        $description = $this->normalizeForMatching($descRaw);
        $needle = $this->normalizeForMatching($needle);

        if ($name === '' || $needle === '') {
            return 0;
        }

        $score = 0;

        if ($name === $needle) {
            $score += 1400;
        }

        if ($this->compactText($name) === $this->compactText($needle)) {
            $score += 1100;
        }

        if (str_contains($name, $needle)) {
            $score += 900;
        }

        if ($brand !== '' && str_contains($brand . ' ' . $name, $needle)) {
            $score += 400;
        }

        $needleTokens = $this->tokenizeMeaningful($needle);
        $nameTokens = $this->tokenizeMeaningful($name);
        $brandTokens = $this->tokenizeMeaningful($brand);
        $descTokens = $this->tokenizeMeaningful($description);

        $matched = 0;
        foreach ($needleTokens as $token) {
            $compactToken = $this->compactText($token);

            $hit = false;
            foreach ($nameTokens as $nameToken) {
                if ($token === $nameToken || $compactToken === $this->compactText($nameToken) || levenshtein($token, $nameToken) <= 1) {
                    $score += 130;
                    $matched++;
                    $hit = true;
                    break;
                }
            }

            if ($hit) {
                continue;
            }

            foreach ($brandTokens as $brandToken) {
                if ($token === $brandToken || $compactToken === $this->compactText($brandToken) || levenshtein($token, $brandToken) <= 1) {
                    $score += 80;
                    $matched++;
                    $hit = true;
                    break;
                }
            }

            if ($hit) {
                continue;
            }

            foreach ($descTokens as $descToken) {
                if ($token === $descToken || $compactToken === $this->compactText($descToken)) {
                    $score += 35;
                    $matched++;
                    break;
                }
            }
        }

        if (!empty($needleTokens) && $matched === count($needleTokens)) {
            $score += 320;
        }

        similar_text($needle, $name, $percentName);
        $score += (int) round($percentName * 2.8);

        if ($brand !== '') {
            similar_text(trim($needle), trim($brand . ' ' . $name), $percentFull);
            $score += (int) round($percentFull * 1.6);
        }

        return $score;
    }

    protected function scoreResultForIntent(array $product, array $intent, string $message): int
    {
        $score = 0;
        $mode = $intent['mode'] ?? 'search';

        if ($mode === 'barcode_lookup' && !empty($intent['barcode'])) {
            $score += $this->scoreBarcodeMatch($product, (string) $intent['barcode']);
        }

        $needle = $intent['product_name'] ?? $intent['query'] ?? $message;
        if ($needle !== '') {
            $score += $this->scoreProductMatch($product, $needle);
        }

        $decisionFilter = strtolower((string) ($intent['decision'] ?? ''));
        if ($decisionFilter !== '' && strtolower((string) ($product['decision'] ?? '')) === $decisionFilter) {
            $score += 250;
        }

        return $score;
    }

    protected function determineRequestedLimit(string $message, int $default = 8): int
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'all')) {
            return 20;
        }

        if (str_contains($lower, 'maximum')
            || str_contains($lower, 'max')
            || str_contains($lower, 'more')
            || str_contains($lower, 'many')
        ) {
            return 20;
        }

        if (preg_match('/\b(top|first|last)\s+(\d{1,2})\b/i', $lower, $m)) {
            $n = (int) $m[2];
            if ($n >= 1 && $n <= 20) {
                return $n;
            }
        }

        if (preg_match('/\b(\d{1,2})\b/', $lower, $m)) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= 20) {
                return $n;
            }
        }

        return max(1, min($default, 20));
    }

    protected function soundsAmbiguous(string $text): bool
    {
        $ambiguousWords = [
            'suggest', 'recommend', 'show', 'list', 'give me', 'what do you have',
            'products', 'items', 'category', 'brand', 'contain', 'contains',
            'containing', 'with', 'from', 'pakistani', 'american', 'australian'
        ];

        foreach ($ambiguousWords as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }

    protected function soundsLikePureCategoryRequest(string $text): bool
    {
        return (
            $this->soundsAmbiguous($text)
            || $this->isIngredientLookupRequest($text)
        )
            && !$this->isDecisionStatusQuestion($text)
            && !$this->isDescriptionRequest($text)
            && !$this->isGeneralProductInfoRequest($text);
    }

    protected function tokenizeMeaningful(string $text): array
    {
        $text = strtolower(trim($this->applyProductAliases($text)));
        $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        $tokens = explode(' ', $text);

        $stopWords = [
            'is', 'are', 'the', 'a', 'an', 'of', 'for', 'to', 'in', 'on', 'with',
            'show', 'give', 'tell', 'some', 'any', 'there', 'all', 'product',
            'products', 'item', 'items', 'list', 'which', 'what', 'me', 'you',
            'know', 'please', 'find', 'check', 'about', 'from', 'under', 'related',
            'contains', 'contain', 'containing', 'ingredient', 'ingredients',
            'made', 'make', 'has', 'have', 'want', 'need', 'available', 'your',
            'showing', 'suggestion', 'suggestions', 'this', 'that', 'it', 'details',
            'detail', 'info', 'information', 'its', 'or', 'and', 'suggest',
            'recommend', 'recommended', 'include', 'includes', 'including',
            'halal', 'haram', 'mashbooh'
        ];

        $tokens = array_values(array_filter($tokens, function ($token) use ($stopWords) {
            return $token !== ''
                && !in_array($token, $stopWords, true)
                && strlen($token) >= 2;
        }));

        return array_values(array_unique($tokens));
    }

    protected function buildProductSearchCandidates(string $name): array
    {
        $base = $this->cleanupProductPhrase($this->applyProductAliases($name));
        $variants = [$base];

        $compact = $this->compactText($base);
        foreach ($this->productAliases as $alias => $canonical) {
            if ($compact === $this->compactText($alias) || str_contains($compact, $this->compactText($alias))) {
                $variants[] = $canonical;
            }
        }

        $variants[] = str_replace('-', ' ', $base);
        $variants[] = preg_replace('/\bchocolates\b/i', 'chocolate', $base);
        $variants[] = preg_replace('/\bchocolate\b/i', 'chocolates', $base);

        return array_values(array_unique(array_filter(array_map(function ($item) {
            return $this->cleanupProductPhrase($item);
        }, $variants))));
    }

    protected function uniqueAttemptList(array $attempts): array
    {
        $seen = [];
        $final = [];

        foreach ($attempts as $attempt) {
            $key = md5(json_encode($attempt));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $final[] = $attempt;
        }

        return $final;
    }

    protected function normalizeForMatching(string $text): string
    {
        $text = strtolower(trim($text));
        $text = $this->applyProductAliases($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    protected function compactText(string $text): string
    {
        return preg_replace('/[^a-z0-9]+/i', '', strtolower($text));
    }

    protected function isGenericLookupWord(string $word): bool
    {
        return in_array(strtolower(trim($word)), [
            'product', 'products', 'item', 'items', 'halal', 'haram', 'mashbooh',
            'ingredients', 'ingredient', 'details', 'detail', 'info', 'information',
            'show', 'list', 'check', 'pakistan', 'pakistani', 'america', 'american',
            'australia', 'australian', 'uk', 'britain', 'england'
        ], true);
    }


    protected function isListIntent(string $text): bool
    {
        $text = strtolower(trim($text));

        return str_contains($text, 'suggest')
            || str_contains($text, 'recommend')
            || str_contains($text, 'list')
            || str_contains($text, 'show me')
            || str_contains($text, 'give me')
            || str_contains($text, 'what products')
            || str_contains($text, 'what items')
            || str_contains($text, 'some ');
    }

    protected function shouldTreatAsOriginDiscoveryRequest(
        string $message,
        string $origin,
        ?string $category = null,
        ?string $ingredient = null,
        string $directProductName = ''
    ): bool {
        if ($origin === '') {
            return false;
        }

        if ($directProductName !== '' && !$this->isCountryLikePhrase($directProductName)) {
            return false;
        }

        if ($ingredient !== null && $ingredient !== '') {
            return true;
        }

        if ($category !== null && $category !== '') {
            return true;
        }

        return $this->isListIntent($message)
            || str_contains(strtolower($message), 'product')
            || str_contains(strtolower($message), 'products')
            || $this->isCountryLikePhrase($message);
    }

    protected function isCountryLikePhrase(string $value): bool
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z\s]/', ' ', $normalized);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

        if ($normalized === '') {
            return false;
        }

        $countries = [
            'pakistan', 'pakistani', 'united states', 'america', 'american',
            'australia', 'australian', 'united kingdom', 'uk', 'britain', 'england',
            'saudi arabia', 'saudi', 'uae', 'united arab emirates', 'canada',
            'morocco', 'france', 'germany', 'italy', 'spain', 'turkey', 'china', 'india'
        ];

        return in_array($normalized, $countries, true);
    }

    protected function scoreImageContextMatch(array $product, ?array $imageContext = null): int
    {
        if (!$imageContext) {
            return 0;
        }

        $score = 0;

        if (!empty($imageContext['barcode'])) {
            $score += $this->scoreBarcodeMatch($product, (string) $imageContext['barcode']);
        }

        if (!empty($imageContext['product_name'])) {
            $score += $this->scoreProductMatch($product, (string) $imageContext['product_name']);
        }

        if (!empty($imageContext['brand'])) {
            $brand = strtolower((string) ($product['brand'] ?? ''));
            $imageBrand = strtolower((string) $imageContext['brand']);

            if ($brand !== '' && $imageBrand !== '') {
                if ($brand === $imageBrand) {
                    $score += 380;
                } elseif (str_contains($brand, $imageBrand) || str_contains($imageBrand, $brand)) {
                    $score += 220;
                }
            }

            if (!empty($imageContext['product_name'])) {
                $score += (int) round($this->scoreProductMatch($product, trim($imageBrand . ' ' . $imageContext['product_name'])) * 0.35);
            }
        }

        return $score;
    }

    protected function attachAlternativeDecisionResults(array $baseResult, array $alternativeResult): array
    {
        $products = $alternativeResult['products'] ?? [];
        if (empty($products)) {
            return $baseResult;
        }

        $counts = [];
        foreach ($products as $product) {
            $decision = strtolower((string) ($product['decision'] ?? 'mashbooh'));
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
        }

        $summaryParts = [];
        foreach ($counts as $decision => $count) {
            $summaryParts[] = $count . ' ' . $decision;
        }

        $baseResult['meta']['alternative_decision_products'] = array_slice($products, 0, 8);
        $baseResult['meta']['alternative_decision_summary'] = implode(', ', $summaryParts);

        return $baseResult;
    }

    protected function isOriginKeyword(string $value): bool
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, [
            'pakistan', 'pakistani', 'united states', 'usa', 'us', 'america', 'american',
            'united kingdom', 'uk', 'britain', 'england', 'australia', 'australian',
            'canada', 'uae', 'emirates', 'saudi arabia', 'saudi'
        ], true);
    }

    protected function isNationalityKeyword(string $value): bool
    {
        return in_array(strtolower(trim($value)), [
            'pakistani', 'american', 'australian', 'british', 'english', 'canadian'
        ], true);
    }

    protected function isOriginOnlySearch(string $message, string $origin = ''): bool
    {
        $normalized = strtolower(trim($message));

        if ($normalized === '' || $origin === '') {
            return false;
        }

        $stripped = preg_replace('/\b(' . implode('|', [
            'suggest', 'recommend', 'show', 'list', 'give', 'me', 'some', 'all', 'products?', 'items?',
            'halal', 'haram', 'mashbooh', 'of', 'from', 'made', 'in', 'country'
        ]) . ')\b/i', ' ', $normalized);

        $stripped = trim(preg_replace('/\s+/', ' ', $stripped));

        if ($stripped === '') {
            return true;
        }

        return $this->extractOrigin($stripped) !== '' && count($this->tokenizeMeaningful($stripped)) <= 1;
    }

    protected function formatHistory(array $history): string
    {
        if (empty($history)) {
            return 'No previous conversation.';
        }

        $lines = [];

        foreach ($history as $item) {
            $role = strtoupper((string) ($item['role'] ?? 'user'));
            $message = trim((string) ($item['message'] ?? ''));

            if ($message !== '') {
                $lines[] = "{$role}: {$message}";
            }
        }

        return implode("\n", $lines);
    }

    protected function trimHistory(array $history, int $maxItems = 6): array
    {
        return array_slice($history, -$maxItems);
    }
}


