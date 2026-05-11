<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * ProductAssistantService
 *
 * Manager/Developer overview: Main orchestration layer: receives the chat request, splits multi-intent prompts, resolves intent, executes lookup, runs recovery, and formats the final answer.
 * Comments were added for documentation only; business logic is unchanged from v3.
 */
class ProductAssistantService
{
    /**
     * Injects required services through Laravel dependency injection.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    public function __construct(
        protected GeminiImageResolverService $imageResolver,
        protected IntentResolverService $intentResolver,
        protected ProductLookupService $productLookup,
        protected ProductReasoningService $reasoning,
        protected ResponseFormatterService $formatter
    ) {
    }

    /**
     * Entry point for one user message. It prepares image context, handles small talk, splits multi-intent prompts, and returns the final formatted answer.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    public function handle(
        string $message,
        array $history = [],
        ?UploadedFile $image = null,
        ?string $preferredOrigin = null
    ): array {
        try {
            $message = trim($message);
            $imageContext = $this->extractImageContext($image, $message);

            $smallTalkReply = $this->buildSmallTalkReply($message);
            if (!$image && $smallTalkReply !== null) {
                return $this->formatter->format([
                    'status' => 'conversation',
                    'message' => 'Small-talk response prepared.',
                    'products' => [],
                    'meta' => [
                        'tool' => 'small_talk',
                        'is_product_lookup' => false,
                    ],
                ], $smallTalkReply);
            }

            if (!$image && $this->isAllProductsDumpRequest($message)) {
                return $this->formatter->format([
                    'status' => 'not_found',
                    'message' => 'Please narrow your search by category, ingredient, halal status, brand, barcode, or origin.',
                    'products' => [],
                    'meta' => [
                        'tool' => 'all_products_guard',
                        'is_product_lookup' => false,
                    ],
                ], 'I cannot show every product at once. Please narrow your search by category, ingredient, halal status, brand, barcode, or origin.');
            }

            // Product-check list prompts need a direct early pass.
            // Examples:
            // - "Need Sprite barcode, Dairy Milk ingredients, Kinder Bueno halal status"
            // - "Check Sprite for alcohol, Dairy Milk for gelatin"
            $productCheckListSegments = $this->buildProductCheckListSegments($message);
            if (count($productCheckListSegments) > 1) {
                return $this->handleMultiIntent(
                    originalMessage: $message,
                    segments: $productCheckListSegments,
                    history: $history,
                    imageContext: $imageContext,
                    preferredOrigin: $preferredOrigin
                );
            }

            $mixedHouseholdFoodSegments = $this->buildMixedHouseholdFoodSegments($message);
            if (count($mixedHouseholdFoodSegments) > 1) {
                return $this->handleMultiIntent(
                    originalMessage: $message,
                    segments: $mixedHouseholdFoodSegments,
                    history: $history,
                    imageContext: $imageContext,
                    preferredOrigin: $preferredOrigin
                );
            }

            $beforeBuySegments = $this->buildBeforeBuyCategoryListSegments($message);
            if (count($beforeBuySegments) > 1) {
                return $this->handleMultiIntent(
                    originalMessage: $message,
                    segments: $beforeBuySegments,
                    history: $history,
                    imageContext: $imageContext,
                    preferredOrigin: $preferredOrigin
                );
            }

            // Question-style multi-product prompts need a direct early pass.
            // Example: "Is Dairy Milk halal? What about Sprite and Coke?"
            // must never collapse into a single "Dairy Milk" lookup.
            $questionStyleSegments = $this->buildQuestionStyleMultiProductSegments($message);
            if (count($questionStyleSegments) > 1) {
                return $this->handleMultiIntent(
                    originalMessage: $message,
                    segments: $questionStyleSegments,
                    history: $history,
                    imageContext: $imageContext,
                    preferredOrigin: $preferredOrigin
                );
            }

            // Multi-intent messages are split before resolving so every part keeps its own filters.
            $segments = $this->buildMultiIntentSegments($message);
            if (count($segments) > 1) {
                return $this->handleMultiIntent(
                    originalMessage: $message,
                    segments: $segments,
                    history: $history,
                    imageContext: $imageContext,
                    preferredOrigin: $preferredOrigin
                );
            }

            $single = $this->runSingleIntent(
                message: $message,
                history: $history,
                imageContext: $imageContext,
                preferredOrigin: $preferredOrigin
            );

            return $this->formatter->format($single['lookup'], $single['reply']);
        } catch (\Throwable $e) {
            Log::error('ProductAssistantService failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->formatter->format([
                'status' => 'error',
                'message' => 'Something went wrong while checking the product records.',
                'products' => [],
                'meta' => [],
            ], 'Sorry, something went wrong while checking the product records. Please try again.');
        }
    }

    /**
     * History is useful only for true follow-up questions like "what about its ingredients?".
     * For standalone requests with explicit brands, ingredients, barcodes, categories, or
     * statuses, history must not leak the previous prompt's entity into the current turn.
     */
    protected function shouldUseHistoryForIntentResolution(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return false;
        }

        // True follow-up questions often contain detail words such as
        // "ingredients", "barcode", or "halal", so they must be allowed
        // to use the previous product context before the explicit-entity guard.
        // Example: after an image result, "tell me its ingredients" should
        // resolve to the last matched product, not become a new empty lookup.
        if ($this->isProductFollowUpQuestion($lower)) {
            return true;
        }

        $hasExplicitCurrentEntity = preg_match('/\b(?:barcode|bar\s*code|brand|products?\s+(?:of|by|from)|(?:fan\s+of|huge\s+fan\s+of|like|love|prefer)\s+[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)|halal|haram|mushbooh|unknown|origin|from\s+[a-z]{2,}|made\s+in|ingredients?|contain|contains|containing|with|without|having|include|includes|including|rich\s+in|high\s+in|sugar|salt|vitamins?|folic\s+acid|vitamin\s*b|palm\s+oil|gelatin|gelatine|alcohol|spices?|spicy|milk|cocoa|drinks?|beverages?|snacks?|chips|crisps|biscuits?|cookies?|chocolates?|cakes?|cand(?:y|ies)|sweets?|pasta|noodles?|spaghetti|sauces?|mayonnaise|ketchup)\b/iu', $lower) === 1;

        if ($hasExplicitCurrentEntity) {
            return false;
        }

        return preg_match('/\b(?:it|its|this|that|same|previous|above|those|these|them|their|they)\b/iu', $lower) === 1;
    }


    /**
     * Detects follow-up questions that depend on the previously matched product.
     * This covers both image flows and text/card flows:
     * - "tell me its ingredients"
     * - "what about barcode?"
     * - "is it halal?"
     * - "ingredients?"
     * It intentionally rejects new catalog/list searches and explicit new product
     * names so existing independent prompts keep their current behavior.
     */
    protected function isProductFollowUpQuestion(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return false;
        }

        if ($this->hasExplicitCatalogBrowsePhrase($lower) || $this->isPluralCatalogText($lower)) {
            return false;
        }

        if ($this->extractBarcodeCandidateFromMessage($lower) !== null) {
            return false;
        }

        $hasPronoun = preg_match('/\b(?:it|its|this|that|same|previous|above|last|selected|scanned|detected|shown|uploaded|them|their|they)\b/iu', $lower) === 1;
        $hasDetailFocus = preg_match('/\b(?:ingredients?|inside|barcode|bar\s*code|details?|detail|status|halal|haram|mushbooh|mashbooh|safe|safety|origin|brand|contain|contains|containing|has|have|alcohol|gelatin|gelatine|pork|animal[-\s]*derived|palm\s*oil|harmful|substances?)\b/iu', $lower) === 1;

        if ($hasPronoun && $hasDetailFocus) {
            return true;
        }

        // Generic follow-up without a product name: "tell me ingredients", "barcode?", "what about status".
        if (preg_match('/^(?:please\s+)?(?:tell|show|give|check)\s+(?:me\s+)?(?:the\s+)?(?:ingredients?|inside|barcode|bar\s*code|details?|detail|status|halal\s+status|origin|brand)\b[?.!\s]*$/iu', $lower) === 1) {
            return true;
        }

        if (preg_match('/^(?:what\s+about|and\s+what\s+about|also)\s+(?:the\s+)?(?:ingredients?|inside|barcode|bar\s*code|details?|detail|status|halal\s+status|origin|brand)\b[?.!\s]*$/iu', $lower) === 1) {
            return true;
        }

        if (preg_match('/^(?:ingredients?|inside|barcode|bar\s*code|details?|detail|status|halal\s+status|origin|brand)[?.!\s]*$/iu', $lower) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Builds an image-like context from the latest product in chat history.
     * The rest of the service already knows how to answer image-anchored product
     * follow-ups, so reusing that shape keeps this patch small and safe.
     */
    protected function resolveFollowUpProductContextFromHistory(array $history, string $message): ?array
    {
        if (empty($history) || ! $this->isProductFollowUpQuestion($message)) {
            return null;
        }

        for ($i = count($history) - 1; $i >= 0; $i--) {
            $entry = $history[$i];

            $product = $this->extractLastProductArrayFromHistoryValue($entry);
            if (is_array($product)) {
                $context = $this->productArrayToFollowUpContext($product, 'history_product');
                if ($context !== null) {
                    return $context;
                }
            }

            $context = $this->extractImageContextFromHistoryValue($entry);
            if (is_array($context)) {
                $context = $this->normalizeImageContext(array_merge($context, ['source' => 'history_image_context']));
                if (! empty($context['product_name']) || ! empty($context['barcode'])) {
                    return $context;
                }
            }

            $text = $this->historyValueToText($entry);
            if ($text !== '') {
                $context = $this->extractProductContextFromHistoryText($text);
                if ($context !== null) {
                    return $context;
                }
            }
        }

        return null;
    }

    protected function extractLastProductArrayFromHistoryValue(mixed $value, int $depth = 0): ?array
    {
        if ($depth > 6 || ! is_array($value)) {
            return null;
        }

        if ($this->looksLikeProductPayload($value)) {
            return $value;
        }

        foreach (['products', 'items', 'product_cards'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                for ($i = count($value[$key]) - 1; $i >= 0; $i--) {
                    $product = $this->extractLastProductArrayFromHistoryValue($value[$key][$i], $depth + 1);
                    if (is_array($product)) {
                        return $product;
                    }
                }
            }
        }

        foreach (['data', 'lookup', 'result', 'response', 'message', 'meta'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                $product = $this->extractLastProductArrayFromHistoryValue($value[$key], $depth + 1);
                if (is_array($product)) {
                    return $product;
                }
            }
        }

        return null;
    }

    protected function looksLikeProductPayload(array $value): bool
    {
        $name = trim((string) ($value['name'] ?? ($value['product_name'] ?? ($value['title'] ?? ''))));
        $barcode = trim((string) ($value['barcode'] ?? ($value['code'] ?? '')));

        if ($name === '' && $barcode === '') {
            return false;
        }

        return array_key_exists('ingredients', $value)
            || array_key_exists('status', $value)
            || array_key_exists('origin', $value)
            || array_key_exists('brand', $value)
            || array_key_exists('category', $value)
            || array_key_exists('main_category', $value)
            || array_key_exists('barcode', $value)
            || array_key_exists('product_name', $value);
    }

    protected function productArrayToFollowUpContext(array $product, string $source): ?array
    {
        $name = trim((string) ($product['name'] ?? ($product['product_name'] ?? ($product['title'] ?? ''))));
        $brand = trim((string) ($product['brand'] ?? ($product['manufacturer'] ?? '')));
        $barcode = preg_replace('/\D+/', '', (string) ($product['barcode'] ?? ($product['code'] ?? ''))) ?? '';
        $category = trim((string) ($product['category'] ?? ($product['main_category'] ?? ($product['main_category1'] ?? ''))));
        $visibleText = trim(implode(' ', array_filter([
            $brand,
            $name,
            (string) ($product['description'] ?? ''),
            (string) ($product['ingredients'] ?? ''),
        ])));

        if ($name === '' && $barcode === '') {
            return null;
        }

        return $this->normalizeImageContext([
            'product_name' => $name !== '' ? $name : null,
            'brand' => $brand !== '' ? $brand : null,
            'barcode' => $barcode !== '' ? $barcode : null,
            'category' => $category !== '' ? $category : null,
            'visible_text' => $visibleText !== '' ? $visibleText : trim($brand . ' ' . $name),
            'source' => $source,
        ]);
    }

    protected function extractImageContextFromHistoryValue(mixed $value, int $depth = 0): ?array
    {
        if ($depth > 5 || ! is_array($value)) {
            return null;
        }

        foreach (['image_context', 'imageContext', 'detected_product', 'image_detection'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                return $value[$key];
            }
        }

        foreach (['data', 'lookup', 'result', 'response', 'message', 'meta'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                $context = $this->extractImageContextFromHistoryValue($value[$key], $depth + 1);
                if (is_array($context)) {
                    return $context;
                }
            }
        }

        return null;
    }

    protected function historyValueToText(mixed $value, int $depth = 0): string
    {
        if ($depth > 4) {
            return '';
        }

        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = [];
        foreach (['reply', 'content', 'text', 'message', 'body'] as $key) {
            if (isset($value[$key]) && (is_string($value[$key]) || is_numeric($value[$key]))) {
                $parts[] = (string) $value[$key];
            }
        }

        foreach (['data', 'lookup', 'result', 'response'] as $key) {
            if (isset($value[$key])) {
                $nested = $this->historyValueToText($value[$key], $depth + 1);
                if ($nested !== '') {
                    $parts[] = $nested;
                }
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts))));
    }

    protected function extractProductContextFromHistoryText(string $text): ?array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }

        $patterns = [
            '/\bI\s+(?:cannot|can\s*not|can\'t)\s+confirm\s+(.+?)\s+as\s+halal\b/iu',
            '/\b(.+?)\s+ingredients\s*:/iu',
            '/\b(?:Yes|No),\s+(.+?)\s+(?:is|are)\s+(?:halal|haram|mushbooh|unknown)\b/iu',
            '/\b(.+?)\s+is\s+marked\s+as\s+(?:halal|haram|unknown|mushbooh)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $name = $this->cleanupHistoryProductNameCandidate((string) ($m[1] ?? ''));
                if ($name !== null) {
                    return $this->normalizeImageContext([
                        'product_name' => $name,
                        'brand' => null,
                        'barcode' => $this->extractBarcodeFromHistoryText($text),
                        'visible_text' => $text,
                        'source' => 'history_text',
                    ]);
                }
            }
        }

        return null;
    }

    protected function cleanupHistoryProductNameCandidate(string $candidate): ?string
    {
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($candidate)));
        $candidate = trim($candidate, " \t\n\r\0\x0B,.;:!?؟-–—");

        $candidate = preg_replace('/^(?:yes|no|found|matching|product|the\s+product)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s+(?:barcode|origin|status|ingredients?|details?)\b.*$/iu', '', $candidate) ?? $candidate;
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));

        if ($candidate === '' || mb_strlen($candidate) < 2 || mb_strlen($candidate) > 120) {
            return null;
        }

        if (preg_match('/\b(?:found\s+\d+\s+results?|please\s+check|product\s+cards|image\s+detection|uploaded\s+image)\b/iu', $candidate) === 1) {
            return null;
        }

        return $candidate;
    }

    protected function extractBarcodeFromHistoryText(string $text): ?string
    {
        if (preg_match('/\b(\d{8,40})\b/u', $text, $m) === 1) {
            return (string) $m[1];
        }

        return null;
    }

    /**
     * Extracts "image context" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractImageContext(?UploadedFile $image, string $message): ?array
    {
        if (!$image) {
            return null;
        }

        try {
            return $this->normalizeImageContext(
                $this->imageResolver->extractFromImage($image, $message)
            );
        } catch (\Throwable $e) {
            Log::warning('Image extraction failed', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Runs one intent end-to-end: resolve, clean arguments, execute DB tool, recover failed searches, and generate reply.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function runSingleIntent(
        string $message,
        array $history = [],
        ?array $imageContext = null,
        ?string $preferredOrigin = null
    ): array {
        $preferredOrigin = $this->normalizePreferredOriginLock($preferredOrigin);

        // Deterministic rules handle high-confidence patterns before Gemini is used.
        // For single long queries, strip lifestyle context before resolving intent.
        $intentMessage = $this->stripLeadingContextForSegmentation($message);
        $routingMessage = $this->stripDependentResultCheckFromSearchQuery($intentMessage);
        if ($routingMessage === '') {
            $routingMessage = $intentMessage;
        }

        // If this turn is a follow-up like "tell me its ingredients", reuse the
        // latest matched product from history as an image-like context. This makes
        // text and image follow-ups behave the same without touching lookup/ranking.
        if (empty($imageContext) && $this->isProductFollowUpQuestion($routingMessage)) {
            $historyProductContext = $this->resolveFollowUpProductContextFromHistory($history, $routingMessage);
            if (is_array($historyProductContext) && (! empty($historyProductContext['product_name']) || ! empty($historyProductContext['barcode']))) {
                $imageContext = $historyProductContext;
            }
        }

        $deterministicIntent = $this->resolveDeterministicSegmentIntent($routingMessage, $imageContext);

        if ($deterministicIntent !== null) {
            $intent = $deterministicIntent;
        } else {
            $resolverHistory = $this->shouldUseHistoryForIntentResolution($routingMessage) ? $history : [];
            $intent = $this->intentResolver->resolve($routingMessage, $resolverHistory, $imageContext);
        }

        $toolName = $intent['tool_name'] ?? null;
        $arguments = is_array($intent['arguments'] ?? null) ? $intent['arguments'] : [];

        if (!$toolName) {
            $lookup = [
                'status' => 'not_found',
                'message' => 'Unable to understand request.',
                'products' => [],
                'meta' => [
                    'image_context' => $imageContext,
                    'intent' => $intent,
                ],
            ];

            return [
                'lookup' => $lookup,
                'reply' => 'I could not fully understand your request. Please share a product name, barcode, category, ingredient, or a clearer image.',
                'intent' => $intent,
                'toolName' => $toolName,
                'arguments' => $arguments,
            ];
        }

        // Location preference is applied later as a hard origin lock after
        // deterministic fixes. Keep this pre-fix pass minimal so existing
        // resolver behavior stays intact.
        if ($preferredOrigin !== null
            && empty($arguments['origin'])
            && empty($arguments['origins'])
            && ! $this->messageContainsExplicitOrigin($message)) {
            $arguments['origin'] = $preferredOrigin;
        }

        if (!empty($imageContext) && !isset($arguments['image_context'])) {
            $arguments['image_context'] = $imageContext;
        }

        // Deterministic safety layer over Gemini output.
        // Gemini sometimes drops category words from short segments such as
        // "snacks from Pakistan" or treats "Sprite barcode" as an empty
        // barcode lookup. These fixes preserve the user's explicit intent
        // before any database query is executed.
        $fixedIntent = $this->applyDeterministicIntentFixes($routingMessage, (string) $toolName, $arguments, $intent, $imageContext);
        $toolName = $fixedIntent['tool_name'];
        $arguments = $fixedIntent['arguments'];
        $intent = $fixedIntent['intent'];

        $locationConflict = $this->preferredOriginConflict($routingMessage, $arguments, $preferredOrigin);
        if ($locationConflict !== null) {
            $lookup = $this->buildPreferredOriginBlockedLookup(
                preferredOrigin: $preferredOrigin,
                requestedOrigins: $locationConflict,
                message: $routingMessage,
                toolName: (string) $toolName,
                arguments: $arguments,
                imageContext: $imageContext
            );

            return [
                'lookup' => $lookup,
                'reply' => (string) ($lookup['message'] ?? 'Location filter is active for your selected region.'),
                'intent' => $intent,
                'toolName' => $toolName,
                'arguments' => $arguments,
            ];
        }

        $arguments = $this->applyPreferredOriginLockToArguments((string) $toolName, $arguments, $preferredOrigin);
        $intent['arguments'] = $arguments;
        $intent['input_arguments'] = $arguments;

        // Execute the structured intent against the database lookup layer.
        $lookup = $this->productLookup->executeTool($toolName, $arguments);
        $lookup = $this->enforcePreferredOriginOnLookup($lookup, $preferredOrigin, $routingMessage, (string) $toolName, $arguments);

        if ($this->shouldRunPreferredOriginNameRecovery((string) $toolName, $lookup, $arguments, $preferredOrigin)) {
            $originRecovered = $this->attemptPreferredOriginNameRecovery($arguments, $preferredOrigin, $imageContext);
            if (($originRecovered['status'] ?? 'not_found') === 'found' && ! empty($originRecovered['products'])) {
                $lookup = $originRecovered;
                $toolName = $originRecovered['meta']['tool'] ?? $toolName;
                $lookup['meta']['recovered_from_location_locked_name_search'] = true;
            }
        }

        if ($this->shouldRunSearchRecovery($toolName, $lookup, $arguments)) {
            $recovered = $this->attemptSearchRecovery($routingMessage, $arguments, $lookup, $preferredOrigin, $imageContext);
            $recovered = $this->enforcePreferredOriginOnLookup($recovered, $preferredOrigin, $routingMessage, (string) $toolName, $arguments);

            if (($recovered['status'] ?? 'not_found') === 'found' && !empty($recovered['products'])) {
                $lookup = $recovered;
                $toolName = $recovered['meta']['tool'] ?? $toolName;
                $lookup['meta']['recovered_from_filter_relaxation'] = true;
            }
        }

        if ($this->shouldRunImageRecovery($lookup, $imageContext)) {
            $recovered = $this->attemptImageRecovery($routingMessage, $intent, $imageContext, $preferredOrigin);
            $recovered = $this->enforcePreferredOriginOnLookup($recovered, $preferredOrigin, $routingMessage, (string) $toolName, $arguments);

            if (($recovered['status'] ?? 'not_found') === 'found' && !empty($recovered['products'])) {
                $lookup = $recovered;
                $toolName = $recovered['meta']['tool'] ?? $toolName;
                $lookup['meta']['recovered_from_image'] = true;
            }
        }

        if (($intent['single_product'] ?? false) === true && !empty($lookup['products'])) {
            $lookup['products'] = [$lookup['products'][0]];
            $lookup['meta']['trimmed_to_single'] = true;
        }

        if (($intent['allow_suggestions'] ?? false) === false) {
            $lookup['meta']['show_suggestions'] = false;
        }

        $lookup['meta'] = array_merge(
            is_array($lookup['meta'] ?? null) ? $lookup['meta'] : [],
            [
                'image_context' => $imageContext,
                'intent' => $intent,
                'tool_name' => $toolName,
                'tool_arguments' => $arguments,
            ]
        );

        // Convert raw lookup data into a user-facing reply.
        if ($this->isPreferredOriginLockNotFound($lookup)) {
            $reply = (string) ($lookup['message'] ?? $this->buildPreferredOriginUnavailableMessage($preferredOrigin));
        } else {
            $reply = $this->reasoning->buildReply($message, $lookup, $intent, $imageContext);
        }

        return [
            'lookup' => $lookup,
            'reply' => $reply,
            'intent' => $intent,
            'toolName' => $toolName,
            'arguments' => $arguments,
        ];
    }

    /**
     * Processes each sub-request independently so mixed prompts keep their own filters and product focus.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function handleMultiIntent(
        string $originalMessage,
        array $segments,
        array $history = [],
        ?array $imageContext = null,
        ?string $preferredOrigin = null
    ): array {
        $perIntent = [];
        $combinedProducts = [];
        $seenProducts = [];
        $foundCount = 0;

        foreach ($segments as $index => $segment) {
            $segmentMessage = trim((string) ($segment['message'] ?? ''));
            if ($segmentMessage === '') {
                continue;
            }

            try {
                $single = $this->runSingleIntent(
                    message: $segmentMessage,
                    history: $history,
                    imageContext: $imageContext,
                    preferredOrigin: $preferredOrigin
                );
            } catch (\Throwable $e) {
                Log::warning('ProductAssistantService multi-intent segment failed', [
                    'segment' => $segmentMessage,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $perIntent[] = [
                    'index' => $index + 1,
                    'label' => $segment['label'] ?? $this->makeSegmentLabel($segmentMessage),
                    'message' => $segmentMessage,
                    'status' => 'error',
                    'reply' => 'This part could not be checked right now.',
                    'product_count' => 0,
                    'tool_name' => null,
                    'arguments' => [],
                    'intent' => [],
                    'lookup_message' => 'Segment failed during product check.',
                ];
                continue;
            }

            $lookup = is_array($single['lookup'] ?? null) ? $single['lookup'] : [];
            $products = is_array($lookup['products'] ?? null) ? $lookup['products'] : [];
            $status = (string) ($lookup['status'] ?? 'not_found');
            $cleanReply = $this->cleanSegmentReply((string) ($single['reply'] ?? ''));

            if ($status === 'found' && !empty($products)) {
                $foundCount++;
            }

            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }

                $key = $this->productKey($product);
                if ($key !== '' && isset($seenProducts[$key])) {
                    continue;
                }

                if ($key !== '') {
                    $seenProducts[$key] = true;
                }
                $combinedProducts[] = $product;
            }

            $perIntent[] = [
                'index' => $index + 1,
                'label' => $segment['label'] ?? $this->makeSegmentLabel($segmentMessage),
                'message' => $segmentMessage,
                'status' => $status,
                'reply' => $cleanReply,
                'product_count' => count($products),
                'tool_name' => $single['toolName'] ?? null,
                'arguments' => $single['arguments'] ?? [],
                'intent' => $single['intent'] ?? [],
                'lookup_message' => $lookup['message'] ?? null,
            ];
        }

        if (empty($perIntent)) {
            $single = $this->runSingleIntent($originalMessage, $history, $imageContext, $preferredOrigin);
            return $this->formatter->format($single['lookup'], $single['reply']);
        }

        $lookup = [
            'status' => $foundCount > 0 ? 'found' : 'not_found',
            'message' => $foundCount > 0
                ? 'Multi-intent product search completed.'
                : 'No products matched any part of the request.',
            'products' => array_slice($combinedProducts, 0, 30),
            'meta' => [
                'tool' => 'multi_intent_router',
                'is_multi_intent' => true,
                'multi_intent' => true,
                'intent_count' => count($perIntent),
                'found_intent_count' => $foundCount,
                'per_intent' => $perIntent,
                'image_context' => $imageContext,
                'original_message' => $originalMessage,
            ],
        ];

        return $this->formatter->format($lookup, $this->buildMultiIntentReply($perIntent, $foundCount, $originalMessage, $combinedProducts));
    }

    /**
     * Builds "multi intent reply" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildMultiIntentReply(array $perIntent, int $foundCount, string $originalMessage = '', array $combinedProducts = []): string
    {
        $detailReplies = [];
        $hasCatalogResult = false;

        foreach ($perIntent as $item) {
            if (! is_array($item)) {
                continue;
            }

            $reply = $this->compactMultiIntentDetailReply((string) ($item['reply'] ?? ''));
            if ($reply === '') {
                continue;
            }

            if ($this->isNoisyMultiIntentFailureReply($item, $reply)) {
                continue;
            }

            if ($this->shouldShowMultiIntentDetailReply($item, $reply)) {
                $detailReplies[] = $reply;
                continue;
            }

            if ((string) ($item['status'] ?? 'not_found') === 'found' && (int) ($item['product_count'] ?? 0) > 0) {
                $hasCatalogResult = true;
            }
        }

        $detailReplies = array_values(array_unique(array_map(fn ($reply) => $this->ensureSentenceEnd($reply), $detailReplies)));
        $dependentCheckReply = $this->buildDependentResultCheckReply($originalMessage, $combinedProducts);
        if ($dependentCheckReply !== '') {
            $detailReplies[] = $dependentCheckReply;
        }

        // Catalog/list requests should stay short, but explicit detail sub-requests
        // such as "Sprite barcode" or "Dairy Milk ingredients" must still answer
        // in the chat reply. Product cards remain the main place for catalog results.
        if (! empty($detailReplies)) {
            $prefix = $hasCatalogResult ? 'Found matching products. ' : '';
            return trim($prefix . implode(' ', array_slice($detailReplies, 0, 8)));
        }

        return $foundCount > 0
            ? 'Found matching products. Please check the product cards below.'
            : 'No matching products found.';
    }


    protected function ensureSentenceEnd(string $reply): string
    {
        $reply = trim((string) preg_replace('/\s+/u', ' ', $reply));
        if ($reply === '') {
            return '';
        }

        return preg_match('/[.!?؟]$/u', $reply) === 1 ? $reply : $reply . '.';
    }

    /**
     * Dependent checks like "check if any returned products contain gelatin/pork"
     * should inspect the already returned products. They must not become extra DB
     * ingredient filters, otherwise valid category results are filtered out.
     */
    protected function buildDependentResultCheckReply(string $message, array $products): string
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '' || empty($products)) {
            return '';
        }

        if (! $this->hasDependentResultCheckWording($lower)) {
            return '';
        }

        $terms = [];
        $termMap = [
            'gelatin' => '/\b(?:gelatin|gelatine)\b/iu',
            'pork' => '/\b(?:pork|lard)\b/iu',
            'alcohol' => '/\b(?:alcohol|ethanol)\b/iu',
            'animal derived' => '/\b(?:animal[-\s]*derived|animal\s+driven|carmine|rennet|enzymes?)\b/iu',
        ];

        foreach ($termMap as $term => $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                $terms[] = $term;
            }
        }

        $terms = array_values(array_unique($terms));
        if (empty($terms)) {
            return '';
        }

        $hits = [];
        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $ingredients = mb_strtolower(trim((string) ($product['ingredients'] ?? '')));
            if ($ingredients === '') {
                continue;
            }

            $matched = [];
            foreach ($terms as $term) {
                $pattern = match ($term) {
                    'gelatin' => '/\b(?:gelatin|gelatine)\b/iu',
                    'pork' => '/\b(?:pork|lard)\b/iu',
                    'alcohol' => '/\b(?:alcohol|ethanol)\b/iu',
                    default => '/\b(?:animal[-\s]*derived|animal\s+driven|carmine|rennet|enzymes?)\b/iu',
                };

                if (preg_match($pattern, $ingredients) === 1) {
                    $matched[] = $term;
                }
            }

            if (! empty($matched)) {
                $name = trim((string) ($product['name'] ?? 'This product'));
                $hits[] = $name . ' contains ' . implode(', ', array_values(array_unique($matched)));
            }
        }

        if (! empty($hits)) {
            return 'Sensitive ingredient check: ' . implode('; ', array_slice(array_values(array_unique($hits)), 0, 5)) . '.';
        }

        return 'Sensitive ingredient check: none of the returned products show ' . implode(', ', $terms) . ' in their listed ingredients.';
    }


    protected function isNoisyMultiIntentFailureReply(array $item, string $reply): bool
    {
        $status = (string) ($item['status'] ?? 'not_found');
        $productCount = (int) ($item['product_count'] ?? 0);
        $lowerReply = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $reply)));

        if ($status === 'error') {
            return true;
        }

        if ($productCount > 0) {
            return false;
        }

        return preg_match('/\b(?:could\s+not\s+check|couldn\'t\s+check|could\s+not\s+find|couldn\'t\s+find|not\s+find\s+this\s+product|no\s+products?\s+matched|no\s+matching\s+products?)\b/iu', $lowerReply) === 1;
    }

    protected function shouldShowMultiIntentDetailReply(array $item, string $reply): bool
    {
        $status = (string) ($item['status'] ?? 'not_found');
        if ($status === 'error') {
            return false;
        }

        $message = mb_strtolower(trim((string) ($item['message'] ?? '')));
        $tool = (string) ($item['tool_name'] ?? '');
        $intent = is_array($item['intent'] ?? null) ? $item['intent'] : [];
        $arguments = is_array($item['arguments'] ?? null) ? $item['arguments'] : [];
        $focuses = is_array($intent['question_focuses'] ?? null) ? $intent['question_focuses'] : [];
        $focuses = array_values(array_unique(array_map(fn ($focus) => mb_strtolower((string) $focus), $focuses)));

        $asksForSpecificDetail = preg_match('/\b(?:barcode|bar\s*code|ingredients?|inside|what\s+is\s+in|what\'s\s+in|halal\s+status|status|safe\s+for\s+muslims?|muslim[-\s]*friendly|alcohol|gelatin|gelatine|palm\s+oil|animal[-\s]*derived|pork|haram|halal)\b/iu', $message) === 1;
        $hasDetailFocus = ! empty(array_intersect($focuses, [
            'ingredients',
            'barcode',
            'halal_status',
            'safety',
            'alcohol_check',
            'animal_derived_check',
            'suspicious_check',
            'health_check',
            'details',
        ]));

        if (in_array($tool, ['find_product_by_name', 'find_product_by_barcode'], true) && ($asksForSpecificDetail || $hasDetailFocus)) {
            return true;
        }

        if (! empty($arguments['product_names'] ?? []) && ($asksForSpecificDetail || $hasDetailFocus)) {
            return true;
        }

        // Do not show catalog/list replies in chat. Cards handle those.
        if ($tool === 'search_products' && empty($arguments['product_names'] ?? [])) {
            return false;
        }

        return $asksForSpecificDetail && $reply !== 'Found matching products. Please check the product cards below.';
    }

    protected function compactMultiIntentDetailReply(string $reply): string
    {
        $reply = trim((string) preg_replace('/\s+/u', ' ', $reply));
        if ($reply === '') {
            return '';
        }

        $reply = preg_replace('/\s*Found\s+\d+\s+results?.*$/iu', '', $reply) ?? $reply;
        $reply = preg_replace('/\s*Ask me about any one item for full ingredient and safety details\.?$/iu', '', $reply) ?? $reply;
        $reply = preg_replace('/\s*If you want, ask me about any one item from this list and I will break down its ingredients, barcode, and safety notes\.?$/iu', '', $reply) ?? $reply;
        $reply = preg_replace('/\baccording\s+to\s+your\s+database\b/iu', 'according to our records', $reply) ?? $reply;
        $reply = preg_replace('/\bin\s+your\s+database\b/iu', 'in our records', $reply) ?? $reply;
        $reply = preg_replace('/\bthe\s+database\b/iu', 'our records', $reply) ?? $reply;
        $reply = preg_replace('/\bdatabase\b/iu', 'records', $reply) ?? $reply;
        $reply = preg_replace('/I\s+could\s+not\s+find\s+this\s+product\s+in\s+our\s+records\.?/iu', 'I could not find a matching product for that part.', $reply) ?? $reply;

        // Keep exact ingredient/status/barcode answers, but avoid accidental very long
        // catalog prose if an upstream service returns one for a detail sub-request.
        $reply = trim($reply);
        if (mb_strlen($reply) > 900) {
            $reply = mb_substr($reply, 0, 897) . '...';
        }

        return $reply;
    }

    protected function isAllProductsDumpRequest(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return false;
        }

        return preg_match('/\b(?:give|show|list|fetch|bring)\s+(?:me\s+)?(?:all|every)\s+(?:products?|items?)\b/iu', $lower) === 1
            || preg_match('/\b(?:all|every)\s+(?:products?|items?)\s+(?:you\s+have|you\s+have\s+access\s+to|in\s+(?:your|the)\s+database)\b/iu', $lower) === 1;
    }

    /**
     * Builds "small talk reply" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildSmallTalkReply(string $message): ?string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $message)));
        $normalized = trim($normalized, " \t\n\r\0\x0B.!؟?");

        if ($normalized === '') {
            return null;
        }

        $hasProductWorkIntent = preg_match('/\b(product|products|item|items|barcode|bar\s*code|ingredients?|halal|haram|mushbooh|mashbooh|origin|brand|category|show|list|give|suggest|recommend|check|find|search|from|made\s+in|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|pasta|pastas|noodles?|spaghetti|macaroni|chocolates?|biscuits?|cookies?|snacks?|chips|cakes?|(?:candy|candies)|dairy|household|cleaning|cleaner|hand\s*washes?|handwashes?|soap|detto?l|carex|pasta|pastas|noodles?|spaghetti|macaroni|oils?|sweeteners?|condiments?|sauces?|mayou?n+ai?se|mayonese|mayounese|spices?|spicy|masala|seasonings?|beef|meat|chicken|sprite|pepsi|coke|cola)\b/iu', $normalized) === 1;
        $pureAssistantQuestion = preg_match('/^(tell me about yourself|who are you|what can you do|help|guide me|how do you work|apnay baray|apne bare)$/iu', $normalized) === 1;

        if ($hasProductWorkIntent && !$pureAssistantQuestion) {
            return null;
        }

        if (preg_match('/^(hi|hello|hey|salam|salaam|assalamualaikum|assalamu alaikum|اسلام علیکم|السلام علیکم)$/iu', $normalized) === 1) {
            return 'Hi! I am your halal product assistant. Send a product name, barcode, image, category, ingredient, or country and I will check only our product records.';
        }

        if (preg_match('/\b(how are you|kaise ho|kese ho|kia haal|kya haal)\b/iu', $normalized) === 1) {
            return 'I am ready to help. Ask me about halal status, ingredients, barcode, origin, or product suggestions from our records.';
        }

        if (preg_match('/\b(thanks|thank you|shukriya|jazakallah)\b/iu', $normalized) === 1) {
            return 'You are welcome. Send the next product, barcode, image, category, or ingredient whenever you need a product check.';
        }

        if ($pureAssistantQuestion) {
            return 'I am a halal product assistant. I can search products by name, barcode, image, category, brand, origin, halal status, and ingredients. For product facts, I stay grounded in our records and do not invent product records.';
        }

        return null;
    }


    /**
     * Splits question/sentence style product prompts before a single-product guard can collapse them.
     * Example: "Is Dairy Milk halal? What about Sprite and Coke?" => three product checks.
     */
    protected function buildQuestionStyleMultiProductSegments(string $message): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($normalized === '') {
            return [];
        }

        if (preg_match('/[؟?!]/u', $normalized) !== 1
            && preg_match('/\b(?:what\s+about|how\s+about|and\s+what\s+about)\b/iu', $normalized) !== 1) {
            return [];
        }

        $focus = $this->inferSharedQuestionFocusForMultiProductPrompt($normalized);
        if ($focus === null) {
            return [];
        }

        $prepared = preg_replace('/\s*[؟?!]+\s*/u', ' ||| ', $normalized) ?? $normalized;
        $prepared = preg_replace('/\s+(?:and\s+)?(?:what\s+about|how\s+about)\s+/iu', ' ||| ', $prepared) ?? $prepared;
        $parts = preg_split('/\s*\|\|\|\s*/u', $prepared) ?: [];

        $segments = [];
        foreach ($parts as $part) {
            $part = $this->cleanQuestionStylePartForSegmentation((string) $part);
            if ($part === '') {
                continue;
            }

            if ($this->looksLikeCategoryOnlyText($part)) {
                return [];
            }

            if ($this->shouldKeepAsSingleProductDetailQuery($part)) {
                $segments[] = [
                    'message' => $part,
                    'label' => $this->makeSegmentLabel($part),
                ];
                continue;
            }

            foreach ($this->splitBareProductNamesForQuestionFollowup($part) as $name) {
                if ($this->looksLikeCategoryOnlyText($name)) {
                    return [];
                }

                $messageForProduct = $this->buildFocusedProductQuestionSegment($name, $focus);
                $segments[] = [
                    'message' => $messageForProduct,
                    'label' => $this->makeSegmentLabel($messageForProduct),
                ];
            }
        }

        return count($segments) > 1 ? $segments : [];
    }

    protected function inferSharedQuestionFocusForMultiProductPrompt(string $message): ?string
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return null;
        }

        if (preg_match('/\b(?:halal|haram|mushbooh|mashbooh|permissible|safe\s+for\s+muslims?|muslim[-\s]*friendly)\b/iu', $lower) === 1) {
            return 'halal';
        }
        if (preg_match('/\b(?:ingredients?|made\s+of|what\s+is\s+in|inside)\b/iu', $lower) === 1) {
            return 'ingredients';
        }
        if (preg_match('/\b(?:alcohol|ethanol)\b/iu', $lower) === 1) {
            return 'alcohol';
        }
        if (preg_match('/\b(?:gelatin|gelatine|animal[-\s]*derived|pork|lard|carmine|rennet|enzymes?)\b/iu', $lower) === 1) {
            return 'animal';
        }
        if (preg_match('/\b(?:barcode|bar\s*code)\b/iu', $lower) === 1) {
            return 'barcode';
        }

        return null;
    }

    protected function cleanQuestionStylePartForSegmentation(string $part): string
    {
        $part = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($part)));
        if ($part === '') {
            return '';
        }

        $part = preg_replace('/^(?:and\s+)?(?:what\s+about|how\s+about)\s+/iu', '', $part) ?? $part;
        $part = preg_replace('/^(?:also|and|plus)\s+/iu', '', $part) ?? $part;
        return trim($part, " \t\n\r\0\x0B,.;:!?؟");
    }

    protected function splitBareProductNamesForQuestionFollowup(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($text)));
        if ($text === '') {
            return [];
        }

        $text = preg_replace('/\b(?:halal|haram|mushbooh|mashbooh|permissible|safe\s+for\s+muslims?|muslim[-\s]*friendly|status|ingredients?|barcode|bar\s*code)\b/iu', '', $text) ?? $text;
        $text = trim((string) preg_replace('/\s+/u', ' ', $text), " \t\n\r\0\x0B,.;:!?؟");
        if ($text === '') {
            return [];
        }

        $chunks = preg_split('/\s*,\s*|\s+and\s+|\s*&\s*/iu', $text) ?: [];
        $names = [];
        foreach ($chunks as $chunk) {
            $chunk = trim((string) preg_replace('/\s+/u', ' ', $chunk), " \t\n\r\0\x0B,.;:!?؟");
            if ($chunk === '' || mb_strlen($chunk) < 2) {
                continue;
            }
            if ($this->looksLikeCategoryOnlyText($chunk)) {
                return [];
            }
            $names[] = $chunk;
        }

        return array_values(array_unique($names));
    }


    protected function looksLikeCategoryOnlyText(string $text): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($text))));
        if ($lower === '') {
            return false;
        }

        $lower = trim($lower, " \t\n\r\0\x0B,.;:!?؟");
        $categoryPattern = '/^(?:halal\s+|haram\s+|mushbooh\s+|unknown\s+|not\s+haram\s+|safe\s+)?(?:products?|items?|options?|foods?|burgers?|pizzas?|drinks?|juices?|beverages?|snacks?|chips|crisps|biscuits?|cookies?|chocolates?|cakes?|cand(?:y|ies)|sweets?|pasta|pastas|noodles?|spaghetti|macaroni|sauces?|ketchup|mayou?n+ai?se|bread|bakery|dairy|cheese|butter|meat|beef|chicken|spices?|seasonings?|household|cleaning|hand\s*washes?|soap)$/iu';

        return preg_match($categoryPattern, $lower) === 1;
    }

    protected function buildFocusedProductQuestionSegment(string $productName, string $focus): string
    {
        $productName = trim((string) preg_replace('/\s+/u', ' ', $productName));

        return match ($focus) {
            'ingredients' => 'tell me ingredients of ' . $productName,
            'alcohol' => 'does ' . $productName . ' contain alcohol',
            'animal' => 'does ' . $productName . ' contain gelatin or animal derived ingredients',
            'barcode' => 'tell me barcode of ' . $productName,
            default => 'is ' . $productName . ' halal',
        };
    }

    /**
     * Splits compact product-check lists where every comma is a separate product detail check.
     */
    protected function buildProductCheckListSegments(string $message): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($normalized === '' || preg_match('/,|\s+and\s+/iu', $normalized) !== 1) {
            return [];
        }

        if (preg_match('/\b(?:barcode|bar\s*code|ingredients?|halal\s+status|status|safe\s+for\s+muslims?|alcohol|gelatin|gelatine|animal[-\s]*derived|palm\s+oil|pork|haram|halal)\b/iu', $normalized) !== 1) {
            return [];
        }

        if ($this->hasExplicitCatalogBrowsePhrase($normalized) || preg_match('/\b(?:products?|items?|options?|burgers?|pizzas?|drinks?|juices?|beverages?|snacks?|chips|crisps|chocolates?|biscuits?|cookies?|cakes?|cand(?:y|ies)|sweets?|pasta|noodles?|sauces?)\b/iu', mb_strtolower($normalized)) === 1) {
            return [];
        }

        $prepared = preg_replace('/^\s*(?:need|check|tell\s+me|please\s+check|please\s+tell\s+me)\s+/iu', '', $normalized) ?? $normalized;
        $prepared = preg_replace('/\s*,\s*(?:and\s+)?/u', ' ||| ', $prepared) ?? $prepared;
        $prepared = preg_replace('/\s+and\s+(?=[A-Z0-9\pL][^,]{1,80}\b(?:barcode|bar\s*code|ingredients?|halal\s+status|status|for\s+(?:alcohol|gelatin|gelatine|palm\s+oil|pork|halal\s+status)))\b/iu', ' ||| ', $prepared) ?? $prepared;
        $parts = preg_split('/\s*\|\|\|\s*/u', $prepared) ?: [];

        $segments = [];
        foreach ($parts as $part) {
            $segment = $this->normalizeProductCheckListPart((string) $part);
            if ($segment === '') {
                continue;
            }
            $segments[] = [
                'message' => $segment,
                'label' => $this->makeSegmentLabel($segment),
            ];
        }

        return count($segments) > 1 ? $segments : [];
    }

    protected function normalizeProductCheckListPart(string $part): string
    {
        $part = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($part)));
        $part = preg_replace('/^(?:and|also|plus)\s+/iu', '', $part) ?? $part;
        $part = trim($part, " \t\n\r\0\x0B,.;:!?؟");
        if ($part === '') {
            return '';
        }

        if (preg_match('/^(.+?)\s+for\s+(alcohol|gelatin|gelatine|animal[-\s]*derived|palm\s+oil|pork|halal\s+status|halal|haram|safe\s+for\s+muslims?)\b/iu', $part, $m) === 1) {
            $name = trim((string) $m[1]);
            $focus = mb_strtolower(trim((string) $m[2]));
            if (preg_match('/halal|haram|safe/u', $focus) === 1) {
                return 'is ' . $name . ' halal';
            }
            return 'does ' . $name . ' contain ' . str_replace('gelatine', 'gelatin', $focus);
        }

        if (preg_match('/^(.+?)\s+(barcode|bar\s*code)\b/iu', $part, $m) === 1) {
            return 'tell me barcode of ' . trim((string) $m[1]);
        }

        if (preg_match('/^(.+?)\s+(ingredients?|inside|whats?\s+inside)\b/iu', $part, $m) === 1) {
            return 'tell me ingredients of ' . trim((string) $m[1]);
        }

        if (preg_match('/^(.+?)\s+(halal\s+status|status)\b/iu', $part, $m) === 1) {
            return 'is ' . trim((string) $m[1]) . ' halal';
        }

        if (preg_match('/^(?:is|does|do|can|tell|check)\b/iu', $part) === 1) {
            return $part;
        }

        return 'tell me about ' . $part;
    }


    /**
     * Splits household + food mixed prompts so household items never get mixed into food cards.
     */
    protected function buildMixedHouseholdFoodSegments(string $message): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($normalized === '') {
            return [];
        }

        $lower = mb_strtolower($normalized);
        $hasHousehold = preg_match('/\b(?:household|cleaning|cleaner|bathroom|kitchen|washroom|hand\s*washes?|handwashes?|soap|soaps|antiseptic|disinfectant|detto?l|carex)\b/iu', $lower) === 1;
        $hasFood = preg_match('/\b(?:grocery|food|drinks?|juices?|beverages?|snacks?|chips|crisps|chocolates?|biscuits?|cookies?|cakes?|cand(?:y|ies)|sweets?|pasta|noodles?|sauces?)\b/iu', $lower) === 1;
        if (! $hasHousehold || ! $hasFood) {
            return [];
        }

        // Direct pattern: "I need bathroom cleaning items and grocery drinks, but do not mix..."
        if (preg_match('/^(?:i\s+)?(?:need|want|show|list|give|find|search)\b\s+(.+?)\s+and\s+(.+?)(?:,?\s+but\s+do\s+not\s+mix.*)?[.?!؟]*$/iu', $normalized, $m) === 1) {
            $left = $this->cleanSegmentText((string) $m[1]);
            $right = $this->cleanSegmentText((string) $m[2]);
            $segments = [];
            foreach ([$left, $right] as $part) {
                if ($part === '') {
                    continue;
                }
                if (preg_match('/\b(?:household|cleaning|cleaner|bathroom|kitchen|washroom|hand\s*washes?|handwashes?|soap|soaps|antiseptic|disinfectant|detto?l|carex)\b/iu', $part) === 1
                    || preg_match('/\b(?:grocery|food|drinks?|juices?|beverages?|snacks?|chips|crisps|chocolates?|biscuits?|cookies?|cakes?|cand(?:y|ies)|sweets?|pasta|noodles?|sauces?)\b/iu', $part) === 1) {
                    $segments[] = [
                        'message' => $part,
                        'label' => $this->makeSegmentLabel($part),
                    ];
                }
            }
            return count($segments) > 1 ? $segments : [];
        }

        return [];
    }

    /**
     * Handles "Before I buy A, B, and C, tell me which halal options...".
     */
    protected function buildBeforeBuyCategoryListSegments(string $message): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($normalized === '') {
            return [];
        }

        if (preg_match('/^(?:before\s+i\s+(?:buy|purchase|shop)|before\s+buying)\s+(.+?)\s*,\s*(?:tell|show|check)\s+me\s+which\s+(.+)$/iu', $normalized, $m) !== 1) {
            return [];
        }

        $listText = trim((string) $m[1]);
        $tail = trim((string) $m[2]);
        $status = preg_match('/\b(?:halal|safe\s+for\s+muslims?|muslim[-\s]*friendly)\b/iu', $tail) === 1 ? 'halal ' : '';
        $tailCheck = preg_match('/\b(?:animal[-\s]*derived|gelatin|gelatine|alcohol|pork|ingredients?)\b/iu', $tail) === 1
            ? ' and also check if any contain ' . $this->compactSensitiveCheckTerms($tail)
            : '';

        $baseSegments = $this->buildSegmentsFromCategoryListText($listText, $message);
        $segments = [];
        foreach ($baseSegments as $segment) {
            $msg = trim((string) ($segment['message'] ?? ''));
            if ($msg === '') {
                continue;
            }
            if ($status !== '' && ! $this->segmentHasExplicitStatus($msg)) {
                $msg = trim($status . $msg);
            }
            if ($tailCheck !== '' && ! $this->hasDependentResultCheckWording($msg)) {
                $msg .= $tailCheck;
            }
            $segments[] = ['message' => $msg, 'label' => $this->makeSegmentLabel($msg)];
        }

        return count($segments) > 1 ? $segments : [];
    }

    protected function compactSensitiveCheckTerms(string $text): string
    {
        $terms = [];
        $lower = mb_strtolower($text);
        foreach (['gelatin', 'alcohol', 'pork', 'animal derived'] as $term) {
            $pattern = '/' . preg_quote($term, '/') . '|'.($term === 'gelatin' ? 'gelatine' : '___never___').'/iu';
            if (preg_match($pattern, $lower) === 1) {
                $terms[] = $term;
            }
        }
        if (empty($terms) && preg_match('/animal/iu', $lower) === 1) {
            $terms[] = 'animal derived ingredients';
        }
        return implode(' or ', array_values(array_unique($terms ?: ['sensitive ingredients'])));
    }

    /**
     * Builds "multi intent segments" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildMultiIntentSegments(string $message): array
    {
        $message = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($message === '' || mb_strlen($message) < 12) {
            return [];
        }

        // Long shopping/list prompts often contain a context preface followed by
        // "like/such as/including" examples. Try the original first so an opener
        // like "I want to buy grocery items like pasta..." keeps the full list.
        $listLikeSegments = $this->buildListLikeMultiIntentSegments($message, $message);
        if (count($listLikeSegments) > 1) {
            return array_slice($this->deduplicateSegments($listLikeSegments), 0, 10);
        }

        $colonListSegments = $this->buildColonListMultiIntentSegments($message, $message);
        if (count($colonListSegments) > 1) {
            return array_slice($this->deduplicateSegments($colonListSegments), 0, 10);
        }

        // Remove leading lifestyle/context text before segmentation.
        // Example: "I am at grocery store and lunch for my children, show biscuits..."
        // The context helps wording, but it must not become a product lookup.
        $workingMessage = $this->stripLeadingContextForSegmentation($message);

        // Try again after stripping, for prompts where the list starts after the context.
        $listLikeSegments = $this->buildListLikeMultiIntentSegments($workingMessage, $message);
        if (count($listLikeSegments) > 1) {
            return array_slice($this->deduplicateSegments($listLikeSegments), 0, 10);
        }

        $colonListSegments = $this->buildColonListMultiIntentSegments($workingMessage, $message);
        if (count($colonListSegments) > 1) {
            return array_slice($this->deduplicateSegments($colonListSegments), 0, 10);
        }

        // V27: mixed catalog + explicit product-detail prompts must split before
        // the contextual single-request guard. Example:
        // "quickly suggest halal pasta, halal drinks, halal chocolates, and tell almond milk status"
        // must produce catalog segments plus a direct Almond Milk status segment.
        $forcedMixedSegments = $this->buildForcedMixedCatalogAndProductDetailSegments($workingMessage, $message);
        if (count($forcedMixedSegments) > 1) {
            return array_slice($this->deduplicateSegments($forcedMixedSegments), 0, 12);
        }

        // Context + one request must remain one intent. But context + a real list
        // must continue into the splitter above/below instead of becoming one broad
        // recommendation query.
        if ($workingMessage === $message && $this->shouldKeepAsSingleContextualRequest($message)) {
            return [];
        }

        if ($this->looksLikeSingleBarcodeQuery($workingMessage) || $this->shouldKeepAsSingleProductDetailQuery($workingMessage)) {
            return [];
        }

        $prepared = $workingMessage;
        $prepared = preg_replace('/\b(?:grocer(?:y|ies)|grocery\s+items?|shopping\s+list|list|items?|products?|options?)\s*:\s*/iu', '', $prepared) ?? $prepared;
        $prepared = preg_replace('/\s*(?:;|\n|\r\n)\s*/u', ' ||| ', $prepared) ?? $prepared;
        $prepared = preg_replace('/\s+\/\s+/u', ' ||| ', $prepared) ?? $prepared;
        $prepared = preg_replace('/\s+(?:and\s+also|also|plus|aur\s+bhi|aur|what\s+about)\s+/iu', ' ||| ', $prepared) ?? $prepared;

        // Split comma-separated catalog requests too:
        // "need snacks from pakistan, chocolates from australia, and sprite barcode"
        // must become three independent database lookups instead of one broad Pakistan filter.
        $prepared = preg_replace('/\s*,\s*(?=(?:and\s+)?(?:show|tell|give|suggest|recommend|list|find|check|need|want|halal|haram|mushbooh|unknown|products?|items?|household|cleaning|cleaner|hand\s*washes?|handwashes?|soap|detto?l|carex|pasta|pastas|noodles?|spaghetti|macaroni|oils?|sweeteners?|condiments?|mayou?n+ai?se|mayonese|mayounese|beef|meat|chicken|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|pasta|pastas|noodles?|spaghetti|macaroni|chocolates?|biscuits?|cookies?|snacks?|chips|cakes?|(?:candy|candies)|sweets?|bakery|dairy|dairy\s+alternatives?|sauces?|spices?|spicy|masala|seasonings?|sprite|pepsi|coke|cola|[a-z0-9][a-z0-9\s&\-]{1,40}\s+(?:barcode|bar\s*code|ingredients?|details?|halal\s+status|status))\b)/iu', ' ||| ', $prepared) ?? $prepared;

        // Split plain "and" only when the right side clearly starts a new independent product/category request.
        $prepared = preg_replace('/\s+and\s+(?=(?:show|tell|give|suggest|recommend|list|find|check|need|want|halal|haram|mushbooh|unknown|products?|items?|household|cleaning|cleaner|hand\s*washes?|handwashes?|soap|detto?l|carex|pasta|pastas|noodles?|spaghetti|macaroni|oils?|sweeteners?|condiments?|mayou?n+ai?se|mayonese|mayounese|beef|meat|chicken|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|pasta|pastas|noodles?|spaghetti|macaroni|chocolates?|biscuits?|cookies?|snacks?|chips|cakes?|(?:candy|candies)|sweets?|bakery|dairy|dairy\s+alternatives?|sauces?|spices?|spicy|masala|seasonings?|sprite|pepsi|coke|cola|[a-z0-9][a-z0-9\s&\-]{1,40}\s+(?:barcode|bar\s*code|ingredients?|details?|halal\s+status|status))\b)/iu', ' ||| ', $prepared) ?? $prepared;

        $rawParts = preg_split('/\s*\|\|\|\s*/u', $prepared) ?: [];
        $parts = [];
        $lastAction = $this->detectLeadingAction($workingMessage);
        $pendingContext = [];

        foreach ($rawParts as $part) {
            $part = $this->cleanSegmentText($part);
            if ($part === '') {
                continue;
            }

            // Do not turn natural context clauses into independent product searches.
            // Keep them and attach them to the next real request so nutrient/health
            // wording can still influence ingredient extraction.
            if ($this->isContextOnlySegment($part)) {
                $pendingContext[] = $part;
                continue;
            }

            if (! empty($pendingContext)) {
                // Preserve medical/nutrient context because it changes the filter,
                // but drop shopping/family/party/lunch context so it never becomes
                // a product name or noisy search query.
                $preservedContext = array_values(array_filter($pendingContext, fn (string $context): bool => $this->shouldPreserveContextForRequest($context)));
                if (! empty($preservedContext)) {
                    $part = trim(implode(', ', $preservedContext) . ', ' . $part);
                }
                $pendingContext = [];
            }

            $part = $this->completeSegment($part, $lastAction);

            if (!$this->looksLikeProductIntentSegment($part)) {
                continue;
            }

            // "any of them / those products" is a dependent check about the previous result set.
            // Keep it attached to the previous segment instead of turning it into a new product lookup.
            if (!empty($parts) && $this->isDependentResultCheckSegment($part)) {
                // Keep dependent result-set checks out of DB lookup segments.
                // The combined result set is inspected after lookups complete.
                continue;
            }

            $parts[] = [
                'message' => $part,
                'label' => $this->makeSegmentLabel($part),
            ];
        }

        $parts = $this->applySharedListModifiersToSegments($parts, $message);
        $parts = $this->deduplicateSegments($parts);

        return count($parts) > 1 ? array_slice($parts, 0, 10) : [];
    }

    /**
     * Cleans "segment text" before matching, resolving, or replying.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function cleanSegmentText(string $segment): string
    {
        $segment = trim($segment);
        $segment = preg_replace('/^(?:and|also|plus|aur|aur bhi|what about)\s+/iu', '', $segment) ?? $segment;
        $segment = preg_replace('/\s+(?:also|too)$/iu', '', $segment) ?? $segment;
        $segment = preg_replace('/\s+from\s+your\s+database\b/iu', '', $segment) ?? $segment;
        $segment = preg_replace('/\s*,?\s*\bbut\b\s+(?:do\s+not\s+show|don\'t\s+show|dont\s+show|avoid\s+showing|exclude)\b.*$/iu', '', $segment) ?? $segment;
        $segment = preg_replace('/\s*,?\s*(?:and\s+)?all\s+should\s+be\s+(?:halal|not\s+haram).*$/iu', '', $segment) ?? $segment;
        $segment = preg_replace('/\s+/u', ' ', $segment) ?? $segment;
        return trim($segment, " \t\n\r\0\x0B,.;");
    }

    /**
     * Boolean helper that checks whether the current request or product matches "is dependent result check segment".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function isDependentResultCheckSegment(string $segment): bool
    {
        return $this->hasDependentResultCheckWording($segment);
    }

    /**
     * Connector-aware ingredient match mode used by deterministic routing.
     * AND/plus/& means every listed ingredient is required. OR/either means any one can match.
     */
    protected function resolveSegmentIngredientMatchMode(array $ingredients, string $message, string $fallback = 'all'): string
    {
        $ingredients = array_values(array_unique(array_filter(array_map(fn ($item) => mb_strtolower(trim((string) $item)), $ingredients))));
        if (count($ingredients) <= 1) {
            return 'all';
        }

        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));

        foreach ($ingredients as $left) {
            foreach ($ingredients as $right) {
                if ($left === $right) {
                    continue;
                }

                $l = preg_quote($left, '/');
                $r = preg_quote($right, '/');

                if (preg_match('/(?<![\pL\pN])' . $l . '(?![\pL\pN])\s+(?:and|plus|&)\s+(?<![\pL\pN])' . $r . '(?![\pL\pN])/iu', $lower) === 1) {
                    return 'all';
                }

                if (preg_match('/(?<![\pL\pN])' . $l . '(?![\pL\pN])\s+(?:or|either)\s+(?<![\pL\pN])' . $r . '(?![\pL\pN])/iu', $lower) === 1) {
                    return 'any';
                }
            }
        }

        return in_array($fallback, ['all', 'any'], true) ? $fallback : 'all';
    }

    /**
     * Detects trailing checks that depend on the previous result set, not on a new product name.
     * Examples: "tell me if any contain gelatin", "check whether any of them have alcohol",
     * "tell me if those products contain animal-derived ingredients".
     */
    protected function hasDependentResultCheckWording(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return false;
        }

        $sensitiveTerms = '(?:animal|derived|driven|animal\s+derived|alcohol|alcoholic|ethanol|gelatin|gelatine|pork|lard|carmine|rennet|enzymes?|ingredients?)';
        $containWords = '(?:contain|contains|containing|have|has|having|with|include|includes|including)';

        if (preg_match('/\b(?:any\s+of\s+)?(?:them|these|those|these\s+products?|those\s+products?|these\s+items?|those\s+items?|the\s+products?|the\s+items?|the\s+results?|above\s+products?|listed\s+products?)\b[^.?!;]{0,140}\b' . $sensitiveTerms . '\b/iu', $lower) === 1) {
            return true;
        }

        if (preg_match('/\b(?:tell|check|show|explain)\b[^.?!;]{0,80}\b(?:if|whether)\b[^.?!;]{0,40}\bany\b[^.?!;]{0,80}\b' . $containWords . '\b[^.?!;]{0,80}\b' . $sensitiveTerms . '\b/iu', $lower) === 1) {
            return true;
        }

        // Short trailing dependent checks after a list query:
        // "show chocolates from UK and check gelatin", "also alcohol check".
        if (preg_match('/^(?:please\s+)?(?:also\s+)?(?:check|tell|show|explain)\s+(?:for\s+)?(?:any\s+)?' . $sensitiveTerms . '\b/iu', $lower) === 1) {
            return true;
        }

        if (preg_match('/^(?:' . $sensitiveTerms . ')\s+(?:check|present|inside|available|found)\b/iu', $lower) === 1) {
            return true;
        }

        return preg_match('/\b(?:if|whether)\b[^.?!;]{0,40}\bany\b[^.?!;]{0,80}\b' . $containWords . '\b[^.?!;]{0,80}\b' . $sensitiveTerms . '\b/iu', $lower) === 1;
    }

    /**
     * Removes dependent result-set checks from the DB search query so they do not become
     * extra ingredient filters. The original message is still used by the reasoning layer,
     * so the final reply can answer the dependent check for the returned products.
     */
    protected function stripDependentResultCheckFromSearchQuery(string $message): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($clean === '' || ! $this->hasDependentResultCheckWording($clean)) {
            return $clean;
        }

        $sensitiveTerms = '(?:animal(?:\s+derived)?|derived|driven|alcohol|alcoholic|ethanol|gelatin|gelatine|pork|lard|carmine|rennet|enzymes?|ingredients?)';
        $containWords = '(?:contain|contains|containing|have|has|having|with|include|includes|including)';

        $patterns = [
            '/\s*(?:,?\s*(?:and\s+also|and|also|plus)?\s*)?(?:tell|check|show|explain)\s+(?:me\s+)?(?:if|whether)\s+(?:any\s+(?:of\s+)?(?:them|these|those|products?|items?|results?)?|any|(?:any\s+of\s+)?(?:them|these|those|the\s+products?|the\s+items?|the\s+results?))\s+' . $containWords . '[^.?!;]*\b' . $sensitiveTerms . '\b.*$/iu',
            '/\s*(?:,?\s*(?:and\s+also|and|also|plus)?\s*)?(?:if|whether)\s+(?:any\s+(?:of\s+)?(?:them|these|those|products?|items?|results?)?|any|(?:any\s+of\s+)?(?:them|these|those|the\s+products?|the\s+items?|the\s+results?))\s+' . $containWords . '[^.?!;]*\b' . $sensitiveTerms . '\b.*$/iu',
            '/\s*(?:,?\s*(?:and\s+also|and|also|plus)?\s*)?(?:tell|check|show|explain)\s+(?:me\s+)?(?:if|whether)\s+(?:any\s+)?(?:of\s+)?(?:them|these|those|the\s+products?|the\s+items?|the\s+results?)\b.*$/iu',
        ];

        foreach ($patterns as $pattern) {
            $clean = preg_replace($pattern, '', $clean) ?? $clean;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        return trim($clean, " \t\n\r\0\x0B,.;:!?&");
    }

    /**
     * Helper method for "complete segment".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function completeSegment(string $segment, ?string $lastAction): string
    {
        $segment = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($segment)));
        $lower = mb_strtolower($segment);

        // Guard against splitter leftovers such as an empty "and check" tail.
        // These previously became a fake product lookup: "tell me about" -> name "about".
        if ($lower === '' || preg_match('/^(?:and|also|plus|tell|tell\s+me|tell\s+me\s+about|about|check|show|give)$/iu', $lower) === 1) {
            return '';
        }

        if (preg_match('/\b(show|list|give|suggest|recommend|tell|check|find|search|need|want|is|are|does|what)\b/iu', $lower) === 1) {
            return $segment;
        }

        // Bare category/list items like "bread", "cereal", or "milk alternatives"
        // are catalog intents, not product-detail lookups.
        if ($this->extractCategoryFromSegment($segment) !== null || $this->segmentLooksLikeCatalogFilter($segment)) {
            return $segment;
        }

        return 'tell me about ' . $segment;
    }

    /**
     * Helper method for "detect leading action".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function detectLeadingAction(string $message): ?string
    {
        $lower = mb_strtolower($message);

        if (preg_match('/\b(suggest|recommend)\b/iu', $lower) === 1) {
            return 'suggest me';
        }

        if (preg_match('/\b(show|list|give|find|need|want)\b/iu', $lower) === 1) {
            return 'show me';
        }

        return null;
    }

    /**
     * Boolean helper that checks whether the current request or product matches "looks like product intent segment".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function looksLikeProductIntentSegment(string $segment): bool
    {
        $lower = mb_strtolower(trim($this->normalizeIntentText($segment)));
        if ($lower === '') {
            return false;
        }

        if ($this->buildSmallTalkReply($segment) !== null) {
            return false;
        }

        if (preg_match('/\b\d{8,14}\b/u', $lower) === 1) {
            return true;
        }

        return preg_match('/\b(product|products|item|items|barcode|bar\s*code|ingredients?|contain|contains|containing|with|without|halal|haram|mushbooh|mashbooh|unknown|safe|muslim\s*friendly|unsafe|origin|brand|category|show|list|give|suggest|recommend|need|want|tell\s+me|check|find|search|from|made\s+in|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|pasta|pastas|noodles?|spaghetti|macaroni|chocolates?|biscuits?|cookies?|snacks?|chips|cakes?|(?:candy|candies)|bakery|bread|breads?|loaves|loaf|toast|breakfast|cereals?|oats?|granola|tea\s*bags?|tea|dairy|dairy\s+alternatives?|milk\s+alternatives?|household|cleaning|cleaner|bathroom|kitchen|hand\s*washes?|handwashes?|soap|antiseptic|detto?l|carex|oils?|sweeteners?|condiments?|sauces?|mayou?n+ai?se|mayonese|mayounese|spices?|spicy|masala|seasonings?|beef|meat|chicken|animal|derived|driven|alcohol|alcoholic|gelatin|gelatine|sugar|salt|folic|vitamins?|vitamin\s*b|nutrients?|nutrition|protein|fiber|fibre|calcium|iron|zinc|sprite|pepsi|coke|cola)\b/iu', $lower) === 1;
    }

    /**
     * Helper method for "segment looks like catalog filter".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function segmentLooksLikeCatalogFilter(string $segment): bool
    {
        $lower = mb_strtolower($this->normalizeIntentText($segment));

        return preg_match('/\b(products?|items?|halal|haram|mushbooh|unknown|safe|from|made\s+in|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|pasta|pastas|noodles?|spaghetti|macaroni|chocolates?|biscuits?|cookies?|snacks?|chips|cakes?|(?:candy|candies)|bakery|bread|breads?|loaves|loaf|toast|breakfast|cereals?|oats?|granola|tea\s*bags?|tea|dairy|dairy\s+alternatives?|milk\s+alternatives?|household|cleaning|cleaner|hand\s*washes?|handwashes?|soap|antiseptic|detto?l|carex|oils?|sweeteners?|condiments?|mayou?n+ai?se|mayonese|mayounese|sauces?|spices?|beef|meat|chicken|contain|contains|containing|with|without|animal|derived|driven|alcohol|gelatin|gelatine|sugar|salt|folic|vitamins?|vitamin\s*b|nutrients?|nutrition|protein|fiber|fibre|calcium|iron|zinc|sprite|pepsi|coke|cola|barcode|bar\s*code)\b/iu', $lower) === 1;
    }

    /**
     * Removes duplicate entries for "segments".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function deduplicateSegments(array $segments): array
    {
        $seen = [];
        $unique = [];

        foreach ($segments as $segment) {
            $message = trim((string) ($segment['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $key = mb_strtolower((string) preg_replace('/[^\pL\pN]+/u', ' ', $message));
            $key = trim((string) preg_replace('/\s+/u', ' ', $key));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $segment;
        }

        return $unique;
    }

    /**
     * Helper method for "make segment label".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function makeSegmentLabel(string $segment): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $segment));
        $label = preg_replace('/^(?:i\s+want\s+you\s+to\s+)?(show\s+me|list|give\s+me|suggest\s+me|recommend\s+me|tell\s+me\s+about|check|find|search)\s+/iu', '', $label) ?? $label;
        $label = preg_replace('/\b(?:give|show|tell)\s+(?:me\s+)?(?:its|the)?\s*(?:barcode|bar\s*code|ingredients?|details?|origin|brand).*$/iu', '', $label) ?? $label;
        $label = trim($label, " \t\n\r\0\x0B,.;");

        if (mb_strlen($label) > 70) {
            $label = mb_substr($label, 0, 67) . '...';
        }

        return $label !== '' ? ucfirst($label) : 'Request';
    }

    /**
     * Cleans "segment reply" before matching, resolving, or replying.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function cleanSegmentReply(string $reply): string
    {
        $reply = trim($reply);
        $reply = preg_replace('/\s*Ask me about any one item for full ingredient and safety details\.?$/iu', '', $reply) ?? $reply;
        $reply = preg_replace('/\s*If you want, ask me about any one item from this list and I will break down its ingredients, barcode, and safety notes\.?$/iu', '', $reply) ?? $reply;
        return trim($reply);
    }

    /**
     * Product helper used for "product key".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function productKey(array $product): string
    {
        $id = trim((string) ($product['id'] ?? ''));
        if ($id !== '') {
            return 'id:' . $id;
        }

        $barcode = trim((string) ($product['barcode'] ?? ''));
        if ($barcode !== '') {
            return 'barcode:' . mb_strtolower($barcode);
        }

        $name = trim((string) ($product['name'] ?? ''));
        return $name !== '' ? 'name:' . mb_strtolower($name) : '';
    }

    /**
     * Boolean helper that checks whether the current request or product matches "looks like single barcode query".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function looksLikeSingleBarcodeQuery(string $message): bool
    {
        $barcode = $this->extractBarcodeCandidateFromMessage($message);
        if ($barcode === null) {
            return false;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        $compact = preg_replace('/[^a-z0-9]+/iu', '', $clean) ?? '';

        return preg_match('/\bbar\s*code\b/iu', $clean) === 1
            || preg_match('/^\d{4,40}$/u', $compact) === 1
            || (preg_match('/^[a-z0-9]{6,40}$/iu', $compact) === 1 && preg_match('/\s/u', $clean) !== 1);
    }

    /**
     * Keeps one-product detail/check questions together so "Sprite barcode and halal status"
     * or "I scanned Sprite and now tell me if it contains alcohol, gelatin..." do not get split
     * into broken sub-requests.
     */
    protected function shouldKeepAsSingleProductDetailQuery(string $message): bool
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($normalized === '') {
            return false;
        }

        if ($this->extractBarcodeCandidateFromMessage($normalized) !== null) {
            return true;
        }

        if (preg_match('/\b\d{8,40}\b/u', $normalized) === 1) {
            return true;
        }

        // Clear multi-product pattern: ", Dairy Milk barcode" or ", Dettol ingredients".
        if (preg_match('/,\s*(?:and\s+)?[a-z0-9][a-z0-9\s&\-]{1,50}\s+(?:barcode|bar\s*code|ingredients?|details?|halal\s+status)\b/iu', $normalized) === 1
            && ! preg_match('/\b(?:if|whether)\s+it\s+(?:contains?|has|have)|\b(?:alcohol|gelatin|gelatine|animal[-\s]*derived|palm\s+oil)\b/iu', $normalized)) {
            return false;
        }

        $productName = $this->extractDirectProductNameForDetail($normalized);
        if ($productName === null) {
            return false;
        }

        $hasDirectProductWording = preg_match('/\b(?:scanned|scan|selected|picked|before\s+i\s+(?:buy|purchase|take)|want\s+to\s+check|need\s+to\s+check|does|do|is|are|check|tell\s+me|give\s+me|about)\b/iu', $normalized) === 1;

        if (! $hasDirectProductWording && ($this->hasExplicitCatalogBrowsePhrase($normalized) || $this->isPluralCatalogText($normalized))) {
            return false;
        }

        return preg_match('/\b(barcode|bar\s*code|ingredients?|details?|origin|brand|halal|haram|safe|safety|contain|contains|has|have|alcohol|gelatin|gelatine|animal[-\s]*derived|palm\s+oil)\b/iu', $normalized) === 1;
    }

    /**
     * Normalizes common real-user typos before deterministic intent routing.
     * This does not add any new service; it only makes the current resolver less brittle.
     */
    protected function normalizeIntentText(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }

        // Repair common pasted/mobile typing issues before intent extraction.
        $value = preg_replace('/^\s*(?:multi[-\s]*intent|single[-\s]*intent|test\s*prompt)\s*[:*\-\"\']+\s*/iu', '', $value) ?? $value;
        $value = preg_replace('/([a-z])([A-Z])/u', '$1 $2', $value) ?? $value;
        $value = preg_replace('/(?<![a-z0-9])agrocery(?![a-z0-9])/iu', 'a grocery', $value) ?? $value;
        $value = preg_replace('/(?<![a-z0-9])a\s*grocery(?![a-z0-9])/iu', 'a grocery', $value) ?? $value;

        $replacements = [
            // Repair common accidental spaces inserted inside important intent words.
            // Example from Tinker/wrapped input: "brand p roducts" should still mean "brand products".
            '/(?<![a-z0-9])p\s+roducts?(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])pro\s+ducts?(?![a-z0-9])/iu' => 'products',
            '/(?<![a-z0-9])prod\s+ucts?(?![a-z0-9])/iu' => 'products',
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
            '/(?<![a-z0-9])inside\s+ingredients?(?![a-z0-9])/iu' => 'inside ingredients',
            '/(?<![a-z0-9])groceries(?![a-z0-9])/iu' => 'grocery items',
            '/(?<![a-z0-9])handwashes(?![a-z0-9])/iu' => 'hand wash',
            '/(?<![a-z0-9])handwash(?:es)?(?![a-z0-9])/iu' => 'hand wash',
            '/animal[-\s]*driven/iu' => 'animal derived',
            '/animal-derived/iu' => 'animal derived',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }


    /**
     * Extracts a barcode/code token from natural language without assuming that
     * barcodes must be 8-14 numeric digits. Some local databases contain long,
     * short, or alphanumeric barcode/code values.
     */
    protected function extractBarcodeCandidateFromMessage(string $message): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($text === '') {
            return null;
        }

        if (preg_match('/(?<![A-Z0-9])(\d{8,40})(?![A-Z0-9])/iu', $text, $match) === 1) {
            return $this->cleanBarcodeCandidateToken((string) $match[1]);
        }

        if (preg_match('/\b(?:barcode|bar\s*code|code|scan(?:ned)?|product\s+code)\b\s*(?:is|as|:|=|#)?\s*([A-Z0-9][A-Z0-9\-_]{1,63})\b/iu', $text, $match) === 1) {
            $candidate = $this->cleanBarcodeCandidateToken((string) $match[1]);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        if (preg_match('/\b(?:have|has|with|this|the)\s+([A-Z0-9][A-Z0-9\-_]{1,63})\s+(?:barcode|bar\s*code|code)\b/iu', $text, $match) === 1) {
            $candidate = $this->cleanBarcodeCandidateToken((string) $match[1]);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Sanitizes barcode candidates and rejects common conversational words.
     */
    protected function cleanBarcodeCandidateToken(string $value): ?string
    {
        $candidate = strtoupper(trim($value));
        $candidate = preg_replace('/[^A-Z0-9]+/u', '', $candidate) ?? '';
        $candidate = trim($candidate);

        if ($candidate === '' || mb_strlen($candidate) < 2 || mb_strlen($candidate) > 64) {
            return null;
        }

        $blocked = [
            'TELL', 'SHOW', 'CHECK', 'PLEASE', 'INGREDIENT', 'INGREDIENTS', 'HALAL', 'HARAM',
            'STATUS', 'PRODUCT', 'PRODUCTS', 'ITEM', 'ITEMS', 'THIS', 'THAT', 'DOES', 'DOSE',
            'CONTAIN', 'CONTAINS', 'ANY', 'HARMFUL', 'SUBSTANCE', 'SUBSTANCES', 'SAFE', 'MUSLIM',
        ];

        if (in_array($candidate, $blocked, true)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Detects broad nutrition/vitamin catalog requests from long lifestyle prompts.
     * Example: "hungry after gym ... show items rich in vitamins".
     */
    protected function looksLikeNutritionCatalogRequest(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return false;
        }

        $hasNutrition = preg_match('/\b(?:nutrients?|nutrition|nutritious|vitamins?|minerals?|folic\s+acid|folate|vitamin\s*b|protein|fiber|fibre|iron|calcium|zinc|magnesium|energy|healthy|rich\s+in|high\s+in)\b/iu', $lower) === 1;
        if (! $hasNutrition) {
            return false;
        }

        return preg_match('/\b(?:show|list|suggest|recommend|find|search|give|need|want|items?|products?|options?|grocery|food|hungry|gym|workout|after\s+gym|after\s+workout)\b/iu', $lower) === 1;
    }

    /**
     * Extracts nutrition-related ingredient filters. For broad "vitamins" prompts,
     * keep the filter intentionally small so strict post-filtering does not require
     * multiple unrelated nutrients at once.
     */
    protected function extractNutritionIngredientFilters(string $message): array
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
        if (preg_match('/\bprotein\b/iu', $lower) === 1) {
            $include[] = 'protein';
        }
        if (preg_match('/\b(?:fiber|fibre)\b/iu', $lower) === 1) {
            $include[] = 'fiber';
        }
        if (preg_match('/\biron\b/iu', $lower) === 1) {
            $include[] = 'iron';
        }
        if (preg_match('/\bcalcium\b/iu', $lower) === 1) {
            $include[] = 'calcium';
        }
        if (preg_match('/\bzinc\b/iu', $lower) === 1) {
            $include[] = 'zinc';
        }

        // If the user explicitly named ordinary ingredients in the request clause
        // (for example "must be having sugar and salt"), do not convert the earlier
        // lifestyle context word "nutrients" into a vitamin search. Explicit user
        // ingredients must beat broad nutrition fallback.
        $explicitIngredientFilters = $this->extractIngredientFiltersFromSegment($message);
        $explicitIncludes = is_array($explicitIngredientFilters['include'] ?? null) ? $explicitIngredientFilters['include'] : [];
        if ($this->explicitIngredientFiltersShouldBeatNutrition($explicitIncludes)) {
            return [];
        }

        if ($include === [] && preg_match('/\b(?:nutrients?|nutrition|nutritious|healthy|energy|gym|workout)\b/iu', $lower) === 1) {
            $include[] = 'vitamin';
        }

        return array_values(array_unique(array_filter($include)));
    }

    /**
     * Returns true when explicit ingredient filters should override broad nutrition context.
     * Example: "I need nutrients... items having sugar and salt" should search sugar+salt,
     * not vitamin.
     */
    protected function explicitIngredientFiltersShouldBeatNutrition(array $ingredients): bool
    {
        $ingredients = array_values(array_unique(array_filter(array_map(
            fn ($item) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $item))),
            $ingredients
        ))));

        if ($ingredients === []) {
            return false;
        }

        $nutritionTerms = [
            'vitamin', 'vitamins', 'vitamin b', 'vitamin b1', 'vitamin b2', 'vitamin b3', 'vitamin b6', 'vitamin b12',
            'folic acid', 'folate', 'niacin', 'thiamine', 'riboflavin', 'cyanocobalamin', 'pyridoxine',
            'protein', 'fiber', 'fibre', 'iron', 'calcium', 'zinc', 'magnesium', 'omega', 'collagen', 'probiotic', 'prebiotic',
            'nutrient', 'nutrients', 'nutrition',
        ];

        foreach ($ingredients as $ingredient) {
            if (! in_array($ingredient, $nutritionTerms, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cleans a possible product name extracted from direct detail/check wording.
     */
    protected function cleanupDirectProductCandidate(string $candidate): ?string
    {
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($candidate)));
        $candidate = trim($candidate, " \t\n\r\0\x0B,.;:!?&");

        // Strip conversational wrappers that users naturally add before the product name.
        // Examples: "can you check Dairy Milk", "please tell me about Sprite".
        $candidate = preg_replace('/^(?:please\s+)?(?:can|could|would)\s+you\s+(?:please\s+)?(?:check|tell\s+me\s+about|tell\s+me|look\s+up|lookup|find|search)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^(?:please\s+)?(?:check|tell\s+me\s+about|tell\s+me|look\s+up|lookup|find|search)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^(?:me\s+)?(?:about)\s+/iu', '', $candidate) ?? $candidate;

        $candidate = preg_replace('/\b(?:before|because|and\s+(?:now\s+)?(?:tell|check|show|explain)|tell\s+me|check\s+if|whether|if)\b.*$/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:barcode|bar\s*code|ingredients?|ingredient|details?|origin|brand|halal|haram|safe|safety|okay|ok|contain|contains|containing|has|have|with|include|includes)\b.*$/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^(?:the\s+)?(?:product|item)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));
        $candidate = trim($candidate, " \t\n\r\0\x0B,.;:!?&");
        $candidate = $this->canonicalProductName($candidate);

        if ($candidate === '' || mb_strlen($candidate) < 3 || $this->looksLikeNoisyProductName($candidate)) {
            return null;
        }

        return $candidate;
    }


    /**
     * Converts colon-based grocery/list prompts into clean independent intents.
     * Example: "I want US grocery options: chips, cookies, soda, candy, and check Dairy Milk gelatin".
     */
    protected function buildColonListMultiIntentSegments(string $message, ?string $originalMessage = null): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        $originalMessage = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($originalMessage ?? $message)));
        if ($normalized === '') {
            return [];
        }

        if (preg_match('/\b(?:grocery(?:\s+for\s+(?:home|house|family))?|grocery\s+options?|grocery\s+items?|shopping\s+list|grocery\s+list|options?|items?|products?|list)\s*:\s*(.+)$/iu', $normalized, $matches) !== 1) {
            return [];
        }

        $listText = trim((string) ($matches[1] ?? ''));
        if ($listText === '') {
            return [];
        }

        return $this->buildSegmentsFromCategoryListText($listText, $originalMessage);
    }

    /**
     * Converts the list portion of a user message into independent category/product requests.
     */
    protected function buildSegmentsFromCategoryListText(string $listText, string $originalMessage): array
    {
        $listText = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($listText)));
        if ($listText === '') {
            return [];
        }

        $tailSegments = [];
        if (preg_match('/\s+(?:and\s+also|also|and)\s+((?:tell|check|show|explain|give)\s+(?:me\s+)?.+?|(?:is|are|does|do)\s+.+?|(?:check\s+)?[\pL\pN][\pL\pN\s&\-\'’]{1,90}\s+separately)\s*[.?!؟]*$/iu', $listText, $tailMatch, PREG_OFFSET_CAPTURE) === 1) {
            $tailText = trim((string) $tailMatch[1][0]);
            $tailStart = (int) $tailMatch[0][1];
            $listText = trim(mb_substr($listText, 0, $tailStart));
            $tailText = trim($tailText, " \t\n\r\0\x0B,.;:!?؟");
            if ($tailText !== '') {
                $tailSegments[] = $tailText;
            }
        }

        $listText = $this->stripTrailingGlobalListConstraints($listText);
        $listText = preg_replace('/\s+and\s+(?=(?:halal|haram|mushbooh|mashbooh|unknown|not\s+haram)\s+(?:pasta|pastas|noodles?|chocolates?|cakes?|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|snacks?|chips|crisps|biscuits?|cookies?|sweets?|candies|candy|bread|sauces?|spices?|dairy|grocery|items?))/iu', ', ', $listText) ?? $listText;
        $listText = preg_replace('/\b(pasta|pastas|noodles?|spaghetti|macaroni|chocolates?|cakes?|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|snacks?|chips|crisps|biscuits?|cookies?|sweets?|candies|candy|bread|sauces?|spices?)\s+(?=(?:halal|haram|mushbooh|mashbooh|unknown|not\s+haram)\s+(?:pasta|pastas|noodles?|chocolates?|cakes?|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|snacks?|chips|crisps|biscuits?|cookies?|sweets?|candies|candy|bread|sauces?|spices?))/iu', '$1, ', $listText) ?? $listText;

        $chunks = preg_split('/\s*,\s*/u', $listText) ?: [];
        $segments = [];
        foreach ($chunks as $chunk) {
            $chunk = $this->cleanSegmentText($chunk);
            if ($chunk === '') {
                continue;
            }

            foreach ($this->expandCompoundShoppingListSegment($chunk) as $expanded) {
                $expanded = $this->cleanSegmentText($expanded);
                if ($expanded === '' || ! $this->looksLikeProductIntentSegment($expanded)) {
                    continue;
                }

                $segments[] = [
                    'message' => $expanded,
                    'label' => $this->makeSegmentLabel($expanded),
                ];
            }
        }

        foreach ($tailSegments as $tail) {
            $tail = $this->cleanSegmentText($tail);

            if ($tail !== '' && ! empty($segments) && $this->isDependentResultCheckSegment($tail)) {
                // Keep dependent result-set checks out of the DB lookup segments.
                // They are answered after the category/product results are returned.
                continue;
            }

            $tail = $this->normalizeTrailingProductDetailSegment($tail);
            if ($tail === '' || ! $this->looksLikeProductIntentSegment($tail)) {
                continue;
            }

            if (! empty($segments) && $this->isDependentResultCheckSegment($tail)) {
                // Keep dependent result-set checks out of the DB lookup segments.
                // They are answered after the category/product results are returned.
                continue;
            }

            $segments[] = [
                'message' => $tail,
                'label' => $this->makeSegmentLabel($tail),
            ];
        }

        $segments = $this->applySharedListModifiersToSegments($segments, $originalMessage);

        return count($segments) > 1 ? $segments : [];
    }


    /**
     * V27 robust splitter for mixed category-list + product-detail prompts.
     *
     * This is intentionally conservative: it only activates when the message has
     * both catalog/category wording and an explicit product-detail request
     * (status, ingredients, barcode, alcohol, gelatin, etc.). It does not touch
     * ingredient connector queries like "sugar and salt".
     */
    protected function buildForcedMixedCatalogAndProductDetailSegments(string $message, ?string $originalMessage = null): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        $originalMessage = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($originalMessage ?? $message)));

        if ($normalized === '') {
            return [];
        }

        $lower = mb_strtolower($normalized);

        $hasCatalogWording = preg_match('/\b(?:suggest|recommend|show|list|give|find|need|want|buy|shopping|grocery|groceries|options?|items?|products?|pasta|pastas|noodles?|spaghetti|macaroni|drinks?|juices?|beverages?|soft\s+drinks?|fizzy\s+drinks?|soda|pop|chocolates?|biscuits?|cookies?|cakes?|cand(?:y|ies)|sweets?|snacks?|chips|crisps|sauces?|ketchup|mayou?n+ai?se|bread|bakery|dairy\s+alternatives?|cereal|household|cleaning|hand\s*washes?|soap)\b/iu', $lower) === 1;

        $hasExplicitDetailTail = preg_match('/(?:^|[,;]|\band\s+)\s*(?:tell|check|show|give)\s+(?:me\s+)?[\pL\pN][\pL\pN\s&\-\'’]{1,90}\s+(?:barcode|bar\s*code|ingredients?|inside|status|halal\s+status|is\s+halal|halal|safe\s+for\s+muslims?|muslim[-\s]*friendly|alcohol|gelatin|gelatine|animal[-\s]*derived|palm\s+oil|pork|lard|carmine|rennet|enzymes?|separately)\b/iu', $lower) === 1;

        if (! $hasCatalogWording || ! $hasExplicitDetailTail) {
            return [];
        }

        $prepared = $this->stripLeadingContextForSegmentation($normalized);
        if ($prepared === '') {
            $prepared = $normalized;
        }
        $prepared = preg_replace('/^\s*(?:please\s+)?(?:quickly\s+|kindly\s+)?/iu', '', $prepared) ?? $prepared;
        $prepared = preg_replace('/\s*(?:;|\n|\r\n)\s*/u', ' ||| ', $prepared) ?? $prepared;

        // Split before explicit trailing product-detail clauses.
        $prepared = preg_replace('/\s*,\s*(?=(?:and\s+also|and|also|plus)?\s*(?:tell|check|show|give)\s+(?:me\s+)?[\pL\pN])/iu', ' ||| ', $prepared) ?? $prepared;
        $prepared = preg_replace('/\s+and\s+(?=(?:also\s+)?(?:tell|check|show|give)\s+(?:me\s+)?[\pL\pN])/iu', ' ||| ', $prepared) ?? $prepared;

        // Split comma-separated category list items while preserving ingredient AND/OR queries.
        $categoryStart = '(?:halal\s+|not\s+haram\s+|muslim[-\s]*friendly\s+|safe\s+)?(?:pasta|pastas|noodles?|spaghetti|macaroni|drinks?|juices?|beverages?|soft\s+drinks?|fizzy\s+drinks?|soda|pop|chocolates?|biscuits?|cookies?|cakes?|cand(?:y|ies)|sweets?|snacks?|chips|crisps|sauces?|ketchup|mayou?n+ai?se|bread|bakery|cereal|dates?|dairy\s+alternatives?|milk\s+alternatives?|household|cleaning|hand\s*washes?|soap)';
        $prepared = preg_replace('/\s*,\s*(?=(?:and\s+)?' . $categoryStart . '\b)/iu', ' ||| ', $prepared) ?? $prepared;

        $rawParts = preg_split('/\s*\|\|\|\s*/u', $prepared) ?: [];
        $segments = [];
        $lastAction = $this->detectLeadingAction($normalized);

        foreach ($rawParts as $part) {
            $part = $this->cleanSegmentText((string) $part);
            $part = preg_replace('/^(?:quickly|kindly)\s+/iu', '', $part) ?? $part;

            if ($part !== '' && ! empty($segments) && $this->isDependentResultCheckSegment($part)) {
                // Dependent result checks are answered after lookups using the
                // combined returned products. Do not append them to category
                // lookup messages, otherwise words like gelatin/pork become
                // hard DB ingredient filters and valid category results vanish.
                continue;
            }

            $part = $this->normalizeTrailingProductDetailSegment($part);
            $part = $this->completeSegment($part, $lastAction);

            if ($part === '' || ! $this->looksLikeProductIntentSegment($part)) {
                continue;
            }

            if (! empty($segments) && $this->isDependentResultCheckSegment($part)) {
                // Dependent result checks are answered after lookups using the
                // combined returned products. Do not append them to category
                // lookup messages, otherwise words like gelatin/pork become
                // hard DB ingredient filters and valid category results vanish.
                continue;
            }

            $segments[] = [
                'message' => $part,
                'label' => $this->makeSegmentLabel($part),
            ];
        }

        $segments = $this->applySharedListModifiersToSegments($segments, $originalMessage);
        return count($segments) > 1 ? $segments : [];
    }

    /**
     * Normalizes trailing product detail clauses from list prompts.
     * Examples:
     * - "tell almond milk status" => "is almond milk halal"
     * - "tell Dairy Milk gelatin" => "does Dairy Milk contain gelatin"
     * - "check Sprite barcode" => "tell me barcode of Sprite"
     */
    protected function normalizeTrailingProductDetailSegment(string $segment): string
    {
        $segment = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($segment)));
        if ($segment === '') {
            return '';
        }

        $segment = preg_replace('/^(?:and\s+also|and|also|plus)\s+/iu', '', $segment) ?? $segment;
        $segment = trim($segment, " \t\n\r\0\x0B,.;:!?؟");

        if ($segment === '' || preg_match('/^(?:tell|tell\s+me|tell\s+me\s+about|about|check|show|give)$/iu', mb_strtolower($segment)) === 1) {
            return '';
        }

        if ($this->isDependentResultCheckSegment($segment)) {
            return $segment;
        }

        // Direct product-sensitive check tail from mixed catalog prompts.
        // Example: "..., halal snacks, biscuits, drinks, and check if Dairy Milk contains gelatin"
        // must become a single product lookup for Dairy Milk. Without this guard,
        // the generic tail rule below can produce noisy text like
        // "does if Dairy Milk contains contain gelatin" and the detail check is lost.
        if (preg_match('/^(?:tell|check|show|give|explain)\s+(?:me\s+)?(?:if|whether)\s+(.+?)\s+(?:contain|contains|containing|has|have|with|include|includes)\s+(?:any\s+)?(alcohol|alcoholic|ethanol|gelatin|gelatine|animal[-\s]*derived|animal\s+derived|pork|lard|palm\s+oil|carmine|rennet|enzymes?)\b/iu', $segment, $m) === 1) {
            $name = $this->cleanupDirectProductCandidate((string) ($m[1] ?? ''));
            $term = mb_strtolower(trim((string) ($m[2] ?? '')));
            $term = str_replace(['gelatine', 'animal-derived'], ['gelatin', 'animal derived'], $term);

            if ($name !== null && ! $this->looksLikeCategoryOnlyText($name) && ! $this->looksLikeNoisyProductName($name)) {
                return 'does ' . $name . ' contain ' . $term;
            }
        }

        // Generic product-detail tail from list prompts.
        // Examples:
        // - "check Dorito separately" => "tell me about Dorito"
        // - "tell me about Doritos separately" => "tell me about Doritos"
        // This is intentionally not product-specific; any DB product name after
        // check/tell/show/give can become an independent lookup instead of being dropped.
        if (preg_match('/^(?:tell|check|show|give)\s+(?:me\s+)?(?:about\s+)?(.+?)\s+separately\s*$/iu', $segment, $m) === 1) {
            $name = trim((string) $m[1], " \t\n\r\0\x0B,.;:!?؟");
            if ($name !== '') {
                return 'tell me about ' . $name;
            }
        }

        if (preg_match('/^(.+?)\s+separately\s*$/iu', $segment, $m) === 1
            && preg_match('/\b(?:barcode|bar\s*code|ingredients?|status|halal|haram|safe|alcohol|gelatin|gelatine|animal[-\s]*derived|palm\s+oil|pork)\b/iu', $segment) !== 1) {
            $name = trim((string) $m[1], " \t\n\r\0\x0B,.;:!?؟");
            if ($name !== '' && ! $this->looksLikeCategoryOnlyText($name)) {
                return 'tell me about ' . $name;
            }
        }

        // Bare "halal" is a product-status tail only when it ends the clause.
        // Without the end guard, category requests like "give me halal drinks"
        // were misread as product "me" + status "halal" and became "is me halal".
        if (preg_match('/^(?:tell|check|show|give)\s+(?:me\s+)?(.+?)\s+(?:halal\s+status|status|is\s+halal|halal)\s*$/iu', $segment, $m) === 1) {
            return 'is ' . trim((string) $m[1]) . ' halal';
        }

        if (preg_match('/^(?:tell|check|show|give)\s+(?:me\s+)?(.+?)\s+(barcode|bar\s*code)\b/iu', $segment, $m) === 1) {
            return 'tell me barcode of ' . trim((string) $m[1]);
        }

        if (preg_match('/^(?:tell|check|show|give)\s+(?:me\s+)?(.+?)\s+(ingredients?|inside|what\s+is\s+inside|what\s+is\s+in)\b/iu', $segment, $m) === 1) {
            return 'tell me ingredients of ' . trim((string) $m[1]);
        }

        if (preg_match('/^(?:tell|check|show|give)\s+(?:me\s+)?(.+?)\s+(alcohol|gelatin|gelatine|animal[-\s]*derived|palm\s+oil|pork|lard|carmine|rennet|enzymes?)\b/iu', $segment, $m) === 1) {
            $term = str_replace('gelatine', 'gelatin', mb_strtolower(trim((string) $m[2])));
            return 'does ' . trim((string) $m[1]) . ' contain ' . $term;
        }

        return $segment;
    }

    /**
     * Converts long shopping/example-list prompts into clean independent intents.
     * Example:
     * "I am going shopping, suggest grocery items like halal pasta, halal chocolates cakes and halal drinks and also tell me almond milk is halal"
     * becomes:
     * "halal pasta", "halal chocolates", "halal cakes", "halal drinks", "tell me almond milk is halal".
     */
    protected function buildListLikeMultiIntentSegments(string $message, ?string $originalMessage = null): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        $originalMessage = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($originalMessage ?? $message)));
        if ($normalized === '') {
            return [];
        }

        $lower = mb_strtolower($normalized);

        if (preg_match('/\b(?:like|such\s+as|including|include)\b\s+(.+)$/iu', $normalized, $matches) !== 1) {
            return [];
        }

        // Use this only for browse/recommendation/list contexts. A product name
        // can contain "like" in rare cases, so do not trigger without list intent.
        if (preg_match('/\b(?:suggest|recommend|need|want|shopping|shop|buy|grocery|groceries|items?|products?|options?|list)\b/iu', $lower) !== 1) {
            return [];
        }

        $listText = trim((string) ($matches[1] ?? ''));
        if ($listText === '') {
            return [];
        }

        return $this->buildSegmentsFromCategoryListText($listText, $originalMessage);
    }

    /**
     * Removes personal/situational prefaces before the real product request.
     * These clauses are useful for UX, but dangerous for DB lookup because words
     * like "lunch", "children", "shopping", or "grocery store" can be misread as
     * product names or broad search text.
     */
    protected function stripLeadingContextForSegmentation(string $message): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/\b(?:grocer(?:y|ies)(?:\s+for\s+(?:home|house|family))?|grocery\s+items?|shopping\s+list|list|items?|products?|options?)\s*:\s*(.+)$/iu', $normalized, $colonMatch) === 1) {
            return trim((string) $colonMatch[1]);
        }

        $parts = preg_split('/\s*,\s*/u', $normalized) ?: [];
        while (count($parts) > 1 && $this->isContextOnlySegment((string) $parts[0])) {
            array_shift($parts);
        }

        $stripped = trim(implode(', ', $parts));

        $patterns = [
            '/^(?:i\s+am|i\'m|im|we\s+are|my\s+(?:son|daughter|kid|kids|child|children|family|mother|mom|mum|father|dad|wife|husband|friend|guest|guests)\s+(?:is|are|wants?|needs?|asked\s+me\s+to\s+buy))[^,.;!?]{0,220}?\b(?:suggest|recommend|show|list|give|find|check|tell|need|want)\b/iu',
            '/^(?:for\s+(?:a\s+)?[^,.;!?]{2,160}?|before\s+i\s+(?:buy|purchase|shop)[^,.;!?]{0,160}?)\s*,?\s*\b(?:suggest|recommend|show|list|give|find|check|tell|need|want)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $stripped, $m, PREG_OFFSET_CAPTURE) === 1) {
                $matched = (string) $m[0][0];
                if (preg_match('/\b(?:suggest|recommend|show|list|give|find|check|tell|need|want)\b/iu', $matched, $action, PREG_OFFSET_CAPTURE) === 1) {
                    $actionOffset = (int) $action[0][1];
                    $stripped = trim(mb_substr($stripped, $actionOffset));
                    break;
                }
            }
        }

        return $stripped !== '' ? $stripped : $normalized;
    }

    /**
     * Only nutrition/medical context should be carried into the next segment.
     * Shopping, family, party, school, lunch, and store context should be dropped.
     */
    protected function shouldPreserveContextForRequest(string $context): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($context))));

        return preg_match('/\b(deficien(?:cy|t)|low\s+in|vitamin|folic\s+acid|iron|calcium|protein|fiber|fibre|nutrition|nutrients?|allerg(?:y|ic))\b/iu', $lower) === 1;
    }

    /**
     * Removes trailing list-wide modifiers from a "like/such as" list.
     * The modifier is applied later to each category segment.
     */
    protected function stripTrailingGlobalListConstraints(string $listText): string
    {
        $clean = trim($listText);
        if ($clean === '') {
            return '';
        }

        $clean = preg_replace('/\s*,?\s*\bbut\b\s+(?:only\s+)?(?:show|give|list|include|suggest|recommend)?\s*(?:me\s+)?(?:halal|not\s+haram|not-haram|muslim[-\s]*friendly|safe\s+for\s+muslims?)(?:\s+or\s+(?:halal|not\s+haram|not-haram|muslim[-\s]*friendly|safe\s+for\s+muslims?))*\s+(?:products?|items?|options?)?.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*,?\s*\bbut\b\s+(?:avoid|exclude|without|no|free\s+from)\s+[^.?!;]+[.?!;]*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*,?\s*(?:and)?\s*(?:only\s+)?(?:show|give|list|include|suggest|recommend)?\s*(?:me\s+)?(?:halal|not\s+haram|not-haram|muslim[-\s]*friendly|safe\s+for\s+muslims?)(?:\s+or\s+(?:halal|not\s+haram|not-haram|muslim[-\s]*friendly|safe\s+for\s+muslims?))*\s+(?:products?|items?|options?)?.*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*,?\s*(?:and)?\s*(?:avoid|exclude|without|no|free\s+from)\s+[^.?!;]+[.?!;]*$/iu', '', $clean) ?? $clean;

        return trim($clean, " \t\n\r\0\x0B,.;:!?&");
    }

    /**
     * Applies status/origin/exclusion words that the user stated once for a whole list.
     */
    protected function applySharedListModifiersToSegments(array $segments, string $originalMessage): array
    {
        if (empty($segments)) {
            return $segments;
        }

        $sharedStatus = $this->extractSharedStatusModifier($originalMessage);
        $sharedOrigins = $this->extractOriginsFromSegment($originalMessage);
        $sharedExcludes = $this->extractSharedExcludedIngredientsModifier($originalMessage);

        if ($sharedStatus === null && empty($sharedOrigins) && empty($sharedExcludes)) {
            return $segments;
        }

        foreach ($segments as $index => $segment) {
            $message = trim((string) ($segment['message'] ?? ''));
            if ($message === '' || ! $this->isCatalogSegmentForSharedModifier($message)) {
                continue;
            }

            $segmentCategoryForSharedModifier = $this->extractCategoryFromSegment($message);
            $skipSharedStatusForSegment = $segmentCategoryForSharedModifier === 'household'
                || preg_match('/\b(?:household|cleaning|cleaner|bathroom|kitchen|washroom|hand\s*washes?|handwashes?|soap|soaps|antiseptic|disinfectant|detto?l|carex)\b/iu', $message) === 1;

            if ($sharedStatus !== null && ! $skipSharedStatusForSegment && ! $this->segmentHasExplicitStatus($message)) {
                $message = $this->stripLeadingCatalogActionForSharedModifier($message);
                $message = trim($sharedStatus . ' ' . $message);
            }

            if (! empty($sharedOrigins) && ! $this->segmentHasExplicitOrigin($message)) {
                $message .= ' from ' . $sharedOrigins[0];
            }

            if (! empty($sharedExcludes) && ! $this->segmentHasExplicitIngredientExclusion($message)) {
                $message .= ' without ' . implode(', ', $sharedExcludes);
            }

            $segments[$index]['message'] = trim($message);
            $segments[$index]['label'] = $this->makeSegmentLabel($message);
        }

        return $segments;
    }

    protected function extractSharedStatusModifier(string $message): ?string
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return null;
        }

        // Do not treat a trailing product-detail status question as a list-wide filter.
        // Example: "UK grocery options: crisps, biscuits, fizzy drinks, sweets,
        // and check almond milk halal status" means UK categories + almond milk check,
        // not "halal crisps/biscuits/drinks/sweets".
        $listScope = preg_replace(
            '/\s*(?:,?\s*(?:and\s+also|and|also|plus)?\s*)?(?:tell|check)\s+(?:me\s+)?[a-z0-9][a-z0-9\s&\-]{1,80}\s+(?:halal\s+status|status|is\s+halal|halal|safe\s+for\s+muslims?|gelatin|alcohol|ingredients?).*$/iu',
            '',
            $lower
        ) ?? $lower;
        $listScope = trim($listScope);

        // If the user says "halal or not-haram" / "halal or not marked haram",
        // treat the list as a strict halal catalog request. In this product app,
        // the combined wording means confirmed halal options, not unknown/null/out-of-scope rows.
        $statusScope = preg_replace('/not\s*[-\s]+haram/iu', 'not haram', $listScope) ?? $listScope;
        $statusScope = preg_replace('/muslim[-\s]*friendly/iu', 'muslim friendly', $statusScope) ?? $statusScope;

        if ((preg_match('/\bhalal\b/iu', $statusScope) === 1
                && preg_match('/\b(?:not\s+haram|not\s+marked\s+haram|muslim\s+friendly|safe\s+for\s+muslims?)\b/iu', $statusScope) === 1)
            || preg_match('/\b(?:only\s+)?show\s+(?:me\s+)?(?:only\s+)?halal\b/iu', $statusScope) === 1
            || preg_match('/\b(?:halal|confirmed\s+halal)\s+(?:products?|items?|options?)\b/iu', $statusScope) === 1) {
            return 'halal';
        }

        // Halal is shared only when it clearly modifies list/category requests.
        if (preg_match('/\b(?:halal\s+(?:(?:grocery|bakery|breakfast|lunch\s+box)\s+)?(?:products?|items?|options?|foods?)|(?:only\s+)?show\s+(?:me\s+)?halal|(?:all|everything)\s+(?:should|must)\s+be\s+halal|halal\s+(?:pasta|pastas|noodles?|spaghetti|macaroni|chocolates?|cakes?|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|snacks?|chips|crisps|biscuits?|cookies?|sweets?|candies|candy|bread|sauces?|spices?))\b/iu', $listScope) === 1) {
            return 'halal';
        }

        if (preg_match('/\b(?:not\s+haram|not-haram|avoid\s+haram|exclude\s+haram|do\s+not\s+show[^.?!;]{0,80}haram|don\'t\s+show[^.?!;]{0,80}haram|dont\s+show[^.?!;]{0,80}haram|not\s+marked\s+haram|marked\s+haram|muslim[-\s]*friendly|safe\s+for\s+muslims?)\b/iu', $listScope) === 1) {
            return 'not haram';
        }

        return null;
    }

    protected function extractSharedExcludedIngredientsModifier(string $message): array
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '' || $this->hasDependentResultCheckWording($lower)) {
            return [];
        }

        if (preg_match('/\b(?:avoid|exclude|without|no|free\s+from|do\s+not\s+contain|does\s+not\s+contain|don\'t\s+contain|dont\s+contain|not\s+contain)\b/iu', $lower) !== 1) {
            return [];
        }

        $direct = [];
        $known = ['alcohol', 'gelatin', 'gelatine', 'pork', 'lard', 'animal derived', 'animal-derived', 'animal driven', 'carmine', 'rennet'];
        foreach ($known as $term) {
            if (preg_match('/\b(?:avoid|exclude|without|no|free\s+from|do\s+not\s+contain|does\s+not\s+contain|don\'t\s+contain|dont\s+contain|not\s+contain)\b[^.?!;]{0,160}\b' . preg_quote($term, '/') . '\b/iu', $lower) === 1) {
                $direct[] = str_replace(['gelatine', 'animal-derived', 'animal driven'], ['gelatin', 'animal derived', 'animal derived'], $term);
            }
        }

        $filters = $this->extractIngredientFiltersFromSegment($lower);
        return array_values(array_unique(array_filter(array_merge($direct, $filters['exclude'] ?? []))));
    }

    protected function isCatalogSegmentForSharedModifier(string $segment): bool
    {
        $hasCategory = $this->extractCategoryFromSegment($segment) !== null || $this->isPluralCatalogText($segment);

        if ($hasCategory && $this->hasDependentResultCheckWording($segment)) {
            return true;
        }

        if ($this->looksLikeSpecificProductDetailRequest($segment)) {
            return false;
        }

        return $hasCategory;
    }

    protected function stripLeadingCatalogActionForSharedModifier(string $segment): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $segment));
        $clean = preg_replace('/^(?:please\s+)?(?:show|list|give|suggest|recommend|find|search)\s+(?:me\s+)?(?:some\s+|any\s+)?/iu', '', $clean) ?? $clean;
        return trim($clean);
    }

    protected function segmentHasExplicitStatus(string $segment): bool
    {
        return preg_match('/\b(halal|haram|mushbooh|mashbooh|unknown|not\s+haram|not-haram|muslim[-\s]*friendly|safe\s+for\s+muslims?)\b/iu', mb_strtolower($segment)) === 1;
    }

    protected function segmentHasExplicitOrigin(string $segment): bool
    {
        return ! empty($this->extractOriginsFromSegment($segment));
    }

    protected function segmentHasExplicitIngredientExclusion(string $segment): bool
    {
        return preg_match('/\b(?:without|avoid|exclude|no|free\s+from|not\s+contain|does\s+not\s+contain|do\s+not\s+contain)\b/iu', mb_strtolower($segment)) === 1;
    }

    /**
     * Splits compact grocery/category phrases such as "halal chocolates cakes"
     * into separate category intents without affecting normal product names.
     */
    protected function expandCompoundShoppingListSegment(string $segment): array
    {
        $segment = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($segment)));
        if ($segment === '') {
            return [];
        }

        $statusPrefix = '';
        if (preg_match('/\b(not\s+haram|halal|haram|mushbooh|mashbooh|unknown)\b/iu', $segment, $statusMatch) === 1) {
            $statusPrefix = mb_strtolower((string) $statusMatch[1]);
        }

        $categoryMap = [
            'pasta' => '/\b(pasta|pastas|noodles?|instant\s+noodles?|spaghetti|macaroni)\b/iu',
            'chocolates' => '/\b(chocolates?|cocoa|confectionery)\b/iu',
            'cakes' => '/\b(cakes?|cupcakes?)\b/iu',
            'drinks' => '/\b(drinks?|beverages?|fizzy\s+drinks?|soda|soft\s+drinks?|pop)\b/iu',
            'juices' => '/\b(juices?|fruit\s+juice|apple\s+juice|orange\s+juice|mango\s+juice)\b/iu',
            'snacks' => '/\b(snacks?|chips|crisps)\b/iu',
            'biscuits' => '/\b(biscuits?|cookies?|crackers?|wafers?)\b/iu',
            'candies' => '/\b(candies|candy|sweets?|gumm(?:y|ies))\b/iu',
            'bread' => '/\b(breads?|loaves|loaf|toast)\b/iu',
            'breakfast' => '/\b(breakfast|cereals?|oats?|granola)\b/iu',
            'tea' => '/\b(tea\s*bags?|green\s+tea|black\s+tea|tea)\b/iu',
            'dairy_alternatives' => '/\b(dairy\s+alternatives?|milk\s+alternatives?|plant\s*based\s+milk|almond\s+milk|soy\s+milk|oat\s+milk|non\s*dairy)\b/iu',
            'sauces' => '/\b(sauces?|condiments?|mayonnaise|mayo|ketchup)\b/iu',
            'spices' => '/\b(spices?|masala|seasonings?)\b/iu',
        ];

        $found = [];
        foreach ($categoryMap as $canonical => $pattern) {
            if (preg_match($pattern, $segment) === 1) {
                $found[] = $canonical;
            }
        }

        $found = array_values(array_unique($found));
        if (count($found) <= 1) {
            return [$segment];
        }

        return array_map(function (string $category) use ($statusPrefix): string {
            return trim(($statusPrefix !== '' ? $statusPrefix . ' ' : '') . $category);
        }, $found);
    }

    /**
     * Detects a context/reason clause that should be attached to the next real request,
     * not handled as its own database search.
     */
    protected function isContextOnlySegment(string $segment): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($segment))));
        if ($lower === '') {
            return false;
        }

        if (preg_match('/\b(?:grocery\s+list|shopping\s+list|lunch\s+box|school\s+lunch)\b/iu', $lower) !== 1
            && preg_match('/\b(?:show|list|give|suggest|recommend|find|search|check|scan|barcode|bar\s*code|ingredients?|halal\s+status)\b/iu', $lower) === 1) {
            return false;
        }

        if (preg_match('/\b(?:i|we|my\s+(?:body|kid|kids|son|daughter|child|children|family|wife|husband|friend|guest|guests|mum|mom|mother|dad|father|parents?))\b[^.?!;]{0,120}\b(?:have|has|am|are|is|need|needs|want|wants|like|likes|going|shopping|preparing|making|buying|asked|told)\b[^.?!;]{0,160}\b(?:deficien(?:cy|t)|vitamin|nutrient|folic\s+acid|iron|calcium|protein|fiber|fibre|health|party|picnic|lunch|dinner|school|guests?|ramadan|gym|workout|supermarket|store|grocery|shopping|chocolate|snack|drink|food|sweets?)\b/iu', $lower) === 1) {
            return true;
        }

        if (preg_match('/\b(?:grocery\s+list|shopping\s+list|going\s+(?:for\s+)?shopping|at\s+(?:the\s+)?(?:grocery\s+store|supermarket)|buying\s+food\s+for|food\s+for\s+guests|lunch\s+box|school\s+lunch|kids\s+party|family\s+picnic)\b/iu', $lower) === 1) {
            return true;
        }

        if (preg_match('/^(?:because|as|since|for|before)\b[^.?!;]{3,160}$/iu', $lower) === 1) {
            return true;
        }

        return preg_match('/\b(?:i\s+am|i\'m|my\s+body\s+is|body\s+is)\s+(?:deficient|low)\s+(?:in\s+)?[a-z0-9\s-]{2,80}$/iu', $lower) === 1;
    }

    /**
     * Keeps context + request as a single intent instead of splitting on commas.
     */
    protected function shouldKeepAsSingleContextualRequest(string $message): bool
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        $lower = mb_strtolower($normalized);

        if ($lower === '' || ! str_contains($lower, ',')) {
            return false;
        }

        $parts = preg_split('/\s*,\s*/u', $normalized) ?: [];
        if (count($parts) < 2) {
            return false;
        }

        $first = trim((string) $parts[0]);
        $rest = trim(implode(', ', array_slice($parts, 1)));

        if ($first === '' || $rest === '') {
            return false;
        }

        return $this->isContextOnlySegment($first)
            && preg_match('/\b(?:show|list|give|suggest|recommend|find|search|check|need|want|tell|please)\b/iu', $rest) === 1;
    }

    /**
     * Detects general ingredient explanation questions such as "What is gelatin?"
     * without turning them into product or catalog searches.
     */
    protected function extractIngredientExplanationTerm(string $message): ?string
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return null;
        }

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

    /**
     * Normalizes the selected location/origin preference. When this value is present,
     * the assistant must only show products from that origin. Passing null/empty keeps
     * the normal global search behavior.
     */
    protected function normalizePreferredOriginLock(?string $preferredOrigin): ?string
    {
        $origin = trim((string) ($preferredOrigin ?? ''));
        if ($origin === '') {
            return null;
        }

        $origin = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($origin)));
        return $origin === '' ? null : $origin;
    }

    /**
     * Detects explicit origin requests that conflict with the user's enabled location.
     * Example: selected location Pakistan + prompt "products from UK" should not show
     * Pakistan results; it should ask the user to disable location for other regions.
     */
    protected function preferredOriginConflict(string $message, array $arguments, ?string $preferredOrigin): ?array
    {
        $preferredOrigin = $this->normalizePreferredOriginLock($preferredOrigin);
        if ($preferredOrigin === null) {
            return null;
        }

        $explicitOrigins = $this->extractOriginsFromSegment($message);
        $messageHasOrigin = ! empty($explicitOrigins) || $this->messageContainsExplicitOrigin($message);

        if ($messageHasOrigin) {
            $argumentOrigins = [];
            $originArg = $arguments['origin'] ?? null;
            if (is_string($originArg) && trim($originArg) !== '') {
                $argumentOrigins[] = trim($originArg);
            }
            if (is_array($arguments['origins'] ?? null)) {
                foreach ($arguments['origins'] as $origin) {
                    if (is_string($origin) && trim($origin) !== '') {
                        $argumentOrigins[] = trim($origin);
                    }
                }
            }
            $explicitOrigins = array_values(array_unique(array_filter(array_merge($explicitOrigins, $argumentOrigins))));
        }

        if (empty($explicitOrigins)) {
            return null;
        }

        $conflicts = [];
        foreach ($explicitOrigins as $origin) {
            if (! $this->originMatchesPreferredOrigin((string) $origin, $preferredOrigin)) {
                $conflicts[] = (string) $origin;
            }
        }

        return empty($conflicts) ? null : array_values(array_unique($conflicts));
    }

    /**
     * Applies the selected location as a hard DB search filter. Resolver/Gemini may
     * extract another origin, but location enabled must win until the user disables it.
     */
    protected function applyPreferredOriginLockToArguments(string $toolName, array $arguments, ?string $preferredOrigin): array
    {
        $preferredOrigin = $this->normalizePreferredOriginLock($preferredOrigin);
        if ($preferredOrigin === null) {
            return $arguments;
        }

        $arguments['preferred_origin_lock'] = $preferredOrigin;

        if (in_array($toolName, ['search_products', 'find_similar_by_category'], true)) {
            $arguments['origin'] = $preferredOrigin;
            $arguments['origins'] = [$preferredOrigin];
        }

        return $arguments;
    }

    /**
     * Final safety guard over all lookup tools. This is required because product-name
     * and barcode lookups do not natively accept origin filters, and recovery paths can
     * otherwise return products outside the enabled location.
     */
    protected function enforcePreferredOriginOnLookup(
        array $lookup,
        ?string $preferredOrigin,
        string $message = '',
        string $toolName = '',
        array $arguments = []
    ): array {
        $preferredOrigin = $this->normalizePreferredOriginLock($preferredOrigin);
        if ($preferredOrigin === null) {
            return $lookup;
        }

        $products = is_array($lookup['products'] ?? null) ? $lookup['products'] : [];
        $lookup['meta'] = is_array($lookup['meta'] ?? null) ? $lookup['meta'] : [];
        $lookup['meta']['preferred_origin_lock'] = $preferredOrigin;
        $lookup['meta']['location_lock_enabled'] = true;

        if (empty($products)) {
            return $lookup;
        }

        $filtered = [];
        $blockedOrigins = [];
        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $origin = trim((string) ($product['origin'] ?? ''));
            if ($this->originMatchesPreferredOrigin($origin, $preferredOrigin)) {
                $filtered[] = $product;
                continue;
            }

            if ($origin !== '') {
                $blockedOrigins[] = $origin;
            }
        }

        if (! empty($filtered)) {
            $lookup['products'] = array_values($filtered);
            $lookup['meta']['location_lock_filtered_count'] = count($products) - count($filtered);
            $lookup['meta']['location_lock_allowed_count'] = count($filtered);
            if (! empty($blockedOrigins)) {
                $lookup['meta']['location_lock_blocked_origins'] = array_values(array_unique($blockedOrigins));
            }
            return $lookup;
        }

        return $this->buildPreferredOriginUnavailableLookup(
            preferredOrigin: $preferredOrigin,
            message: $message,
            toolName: $toolName,
            arguments: $arguments,
            originalLookup: $lookup,
            blockedOrigins: $blockedOrigins
        );
    }

    protected function shouldRunPreferredOriginNameRecovery(string $toolName, array $lookup, array $arguments, ?string $preferredOrigin): bool
    {
        $preferredOrigin = $this->normalizePreferredOriginLock($preferredOrigin);
        if ($preferredOrigin === null || $toolName !== 'find_product_by_name') {
            return false;
        }

        if (($lookup['status'] ?? 'not_found') === 'found' && ! empty($lookup['products'] ?? [])) {
            return false;
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        return $name !== '';
    }

    /**
     * If a normal name lookup found only out-of-location products, try a location-locked
     * product_names search so a same-name local record is not missed by ranking.
     */
    protected function attemptPreferredOriginNameRecovery(array $arguments, ?string $preferredOrigin, ?array $imageContext = null): array
    {
        $preferredOrigin = $this->normalizePreferredOriginLock($preferredOrigin);
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($preferredOrigin === null || $name === '') {
            return $this->buildPreferredOriginUnavailableLookup($preferredOrigin, '', 'find_product_by_name', $arguments);
        }

        $searchArguments = [
            'query' => '',
            'category' => null,
            'brand' => null,
            'origin' => $preferredOrigin,
            'origins' => [$preferredOrigin],
            'product_names' => [$name],
            'ingredients_include' => [],
            'ingredients_exclude' => [],
            'status_include' => [],
            'status_exclude' => [],
            'limit' => 8,
            'image_context' => $imageContext ?? [],
            'preferred_origin_lock' => $preferredOrigin,
        ];

        $lookup = $this->productLookup->executeTool('search_products', $searchArguments);
        $lookup = $this->enforcePreferredOriginOnLookup($lookup, $preferredOrigin, $name, 'search_products', $searchArguments);
        if (($lookup['status'] ?? 'not_found') === 'found' && ! empty($lookup['products'])) {
            $lookup['products'] = [is_array($lookup['products'][0] ?? null) ? $lookup['products'][0] : $lookup['products'][0]];
            $lookup['meta'] = array_merge(is_array($lookup['meta'] ?? null) ? $lookup['meta'] : [], [
                'tool' => 'find_product_by_name',
                'location_name_recovery' => true,
                'name' => $name,
            ]);
        }

        return $lookup;
    }

    protected function buildPreferredOriginBlockedLookup(
        ?string $preferredOrigin,
        array $requestedOrigins = [],
        string $message = '',
        string $toolName = '',
        array $arguments = [],
        ?array $imageContext = null
    ): array {
        $preferredOrigin = $this->normalizePreferredOriginLock($preferredOrigin);
        $requestedOrigins = array_values(array_unique(array_filter(array_map('strval', $requestedOrigins))));
        $requestedText = ! empty($requestedOrigins) ? implode(', ', $requestedOrigins) : 'another region';
        $preferredText = $preferredOrigin ?? 'your selected region';

        return [
            'status' => 'not_found',
            'message' => "Location filter is on for {$preferredText}. I can only show {$preferredText}-origin products. Disable location to search {$requestedText} or other regions.",
            'products' => [],
            'meta' => [
                'tool' => $toolName,
                'location_lock_enabled' => true,
                'preferred_origin_lock' => $preferredOrigin,
                'requested_origins_blocked' => $requestedOrigins,
                'tool_arguments' => $arguments,
                'image_context' => $imageContext,
            ],
        ];
    }

    protected function buildPreferredOriginUnavailableLookup(
        ?string $preferredOrigin,
        string $message = '',
        string $toolName = '',
        array $arguments = [],
        array $originalLookup = [],
        array $blockedOrigins = []
    ): array {
        $preferredOrigin = $this->normalizePreferredOriginLock($preferredOrigin);
        $preferredText = $preferredOrigin ?? 'your selected region';

        return [
            'status' => 'not_found',
            'message' => $this->buildPreferredOriginUnavailableMessage($preferredOrigin),
            'products' => [],
            'meta' => array_merge(is_array($originalLookup['meta'] ?? null) ? $originalLookup['meta'] : [], [
                'tool' => $toolName,
                'location_lock_enabled' => true,
                'preferred_origin_lock' => $preferredOrigin,
                'location_lock_filtered_empty' => true,
                'location_lock_blocked_origins' => array_values(array_unique(array_filter($blockedOrigins))),
                'tool_arguments' => $arguments,
                'original_lookup_status' => $originalLookup['status'] ?? null,
                'original_lookup_message' => $originalLookup['message'] ?? null,
                'message_checked' => $message,
            ]),
        ];
    }

    protected function buildPreferredOriginUnavailableMessage(?string $preferredOrigin): string
    {
        $preferredOrigin = $this->normalizePreferredOriginLock($preferredOrigin);
        $preferredText = $preferredOrigin ?? 'your selected region';

        return "I could not find this in {$preferredText}-origin products. Disable location to search other regions.";
    }

    protected function isPreferredOriginLockNotFound(array $lookup): bool
    {
        $meta = is_array($lookup['meta'] ?? null) ? $lookup['meta'] : [];
        return ! empty($meta['location_lock_enabled'])
            && (($lookup['status'] ?? 'not_found') !== 'found' || empty($lookup['products'] ?? []));
    }

    protected function originMatchesPreferredOrigin(string $origin, string $preferredOrigin): bool
    {
        $origin = trim($origin);
        $preferredOrigin = trim($preferredOrigin);
        if ($origin === '' || $preferredOrigin === '') {
            return false;
        }

        $originForms = $this->originLockForms($origin);
        $preferredForms = $this->originLockForms($preferredOrigin);

        foreach ($originForms as $originForm) {
            foreach ($preferredForms as $preferredForm) {
                if ($originForm !== '' && $preferredForm !== '' && $originForm === $preferredForm) {
                    return true;
                }
            }
        }

        $originCompact = $this->compactOriginLockForms($originForms);
        $preferredCompact = $this->compactOriginLockForms($preferredForms);
        foreach ($originCompact as $originForm) {
            foreach ($preferredCompact as $preferredForm) {
                if ($originForm !== '' && $preferredForm !== '' && $originForm === $preferredForm) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function originLockForms(string $origin): array
    {
        $origin = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($origin))));
        $origin = trim($origin, " \t\n\r\0\x0B,.;:!?؟()[]{}");
        if ($origin === '') {
            return [];
        }

        $space = str_replace(['_', '-'], ' ', $origin);
        $hyphen = str_replace(['_', ' '], '-', $origin);
        $forms = [$origin, $space, $hyphen];

        $compact = preg_replace('/[^a-z0-9]+/iu', '', $origin) ?? '';
        $aliasMap = [
            'pakistan' => ['pakistan', 'pakistani', 'pk'],
            'pakistani' => ['pakistan', 'pakistani', 'pk'],
            'pk' => ['pakistan', 'pakistani', 'pk'],
            'uk' => ['uk', 'u k', 'u.k', 'united kingdom', 'united-kingdom', 'great britain', 'britain'],
            'unitedkingdom' => ['uk', 'u k', 'u.k', 'united kingdom', 'united-kingdom', 'great britain', 'britain'],
            'greatbritain' => ['uk', 'u k', 'u.k', 'united kingdom', 'united-kingdom', 'great britain', 'britain'],
            'britain' => ['uk', 'u k', 'u.k', 'united kingdom', 'united-kingdom', 'great britain', 'britain'],
            'england' => ['england', 'english'],
            'english' => ['england', 'english'],
            'usa' => ['usa', 'u s a', 'u.s.a', 'us', 'u s', 'u.s', 'united states', 'united-states', 'united states of america', 'america', 'american'],
            'us' => ['usa', 'u s a', 'u.s.a', 'us', 'u s', 'u.s', 'united states', 'united-states', 'united states of america', 'america', 'american'],
            'unitedstates' => ['usa', 'u s a', 'u.s.a', 'us', 'u s', 'u.s', 'united states', 'united-states', 'united states of america', 'america', 'american'],
            'america' => ['usa', 'u s a', 'u.s.a', 'us', 'u s', 'u.s', 'united states', 'united-states', 'united states of america', 'america', 'american'],
        ];

        if (isset($aliasMap[$compact])) {
            $forms = array_merge($forms, $aliasMap[$compact]);
        }

        $expanded = [];
        foreach ($forms as $form) {
            $form = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $form)));
            if ($form === '') {
                continue;
            }
            $expanded[] = $form;
            $expanded[] = str_replace(['_', '-'], ' ', $form);
            $expanded[] = str_replace(['_', ' '], '-', $form);
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    protected function compactOriginLockForms(array $forms): array
    {
        $compact = [];
        foreach ($forms as $form) {
            $value = preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower((string) $form)) ?? '';
            if ($value !== '') {
                $compact[] = $value;
            }
        }

        return array_values(array_unique($compact));
    }

    /**
     * Helper method for "message contains explicit origin".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function messageContainsExplicitOrigin(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return false;
        }

        // Generic explicit origin marker. This works for future DB origins without code edits.
        if (preg_match('/\b(?:from|made\s+in|origin(?:\s+is|\s+from)?|country(?:\s+is|\s+from)?)\s+[\pL\pN][\pL\pN\s._\-\'’]{1,100}/iu', $lower) === 1) {
            return true;
        }

        return ! empty($this->extractOriginsFromSegment($lower));
    }

    /**
     * Local high-confidence router for short segment queries.
     *
     * Gemini is still used for complex wording, but obvious segment queries must
     * not depend on AI interpretation. This fixes the exact class of bugs where
     * a phrase works alone ("chocolates from australia", "sprite barcode") but
     * fails after multi-intent splitting.
     */
    /**
     * Rule-based resolver for high-confidence cases that should not rely only on Gemini.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function resolveDeterministicSegmentIntent(string $message, ?array $imageContext = null): ?array
    {
        $message = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($message === '') {
            return null;
        }

        $focuses = $this->extractFocusesFromSegment($message);
        $focuses = $focuses === [] ? ['details'] : $focuses;

        $ingredientToExplain = $this->extractIngredientExplanationTerm($message);
        if ($ingredientToExplain !== null) {
            return $this->buildDeterministicIntent(
                toolName: 'explain_ingredient',
                arguments: [
                    'ingredient' => $ingredientToExplain,
                    'image_context' => $imageContext ?? [],
                ],
                questionFocuses: ['ingredient_explanation'],
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.98,
                source: 'deterministic_ingredient_explanation'
            );
        }

        $barcodeCandidate = $this->extractBarcodeCandidateFromMessage($message);
        if ($barcodeCandidate !== null) {
            return $this->buildDeterministicIntent(
                toolName: 'find_product_by_barcode',
                arguments: [
                    'barcode' => $barcodeCandidate,
                    'image_context' => $imageContext ?? [],
                ],
                questionFocuses: $focuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.99,
                source: 'deterministic_barcode_segment'
            );
        }

        // Image + prompt follow-ups must stay anchored to the detected image product.
        // Example: user uploads Panko image and asks "does it contain harmful substance?"
        // This should find the detected DB product first, then answer from its ingredients/status.
        $imageAnchoredIntent = $this->resolveImageAnchoredFollowUpIntent($message, $imageContext, $focuses);
        if ($imageAnchoredIntent !== null) {
            return $imageAnchoredIntent;
        }

        $explicitIngredientFilters = $this->extractIngredientFiltersFromSegment($message);
        $statusFiltersForIngredientCatalog = $this->extractStatusFiltersFromSegment($message);
        $hasExplicitIngredientFilters = ! empty($explicitIngredientFilters['include']) || ! empty($explicitIngredientFilters['exclude']);
        if ($hasExplicitIngredientFilters
            && ! $this->hasDependentResultCheckWording($message)
            && ($this->segmentHasCatalogBrowseIntent($message) || $this->looksLikeNutritionCatalogRequest($message))) {
            // Preserve explicit category/origin words for combo ingredient filters.
            // Examples: "snacks with sugar but without gelatin", "drinks containing vitamin but no alcohol",
            // "biscuits with wheat but not eggs" must stay category searches, not global ingredient searches.
            $ingredientCatalogCategory = $this->extractCategoryFromSegment($message);
            $ingredientCatalogOrigins = $this->extractOriginsFromSegment($message);

            return $this->buildDeterministicIntent(
                toolName: 'search_products',
                arguments: [
                    'query' => '',
                    'category' => $ingredientCatalogCategory,
                    'brand' => null,
                    'origin' => $ingredientCatalogOrigins[0] ?? null,
                    'origins' => $ingredientCatalogOrigins,
                    'product_names' => [],
                    'ingredients_include' => $explicitIngredientFilters['include'],
                    'ingredients_exclude' => $explicitIngredientFilters['exclude'],
                    'ingredient_query' => $message,
                    'status_include' => $statusFiltersForIngredientCatalog['include'],
                    'status_exclude' => $statusFiltersForIngredientCatalog['exclude'],
                    'match_mode' => $explicitIngredientFilters['match_mode'] ?? 'all',
                    'limit' => 12,
                    'image_context' => $imageContext ?? [],
                ],
                questionFocuses: array_values(array_unique(array_merge($focuses, ['ingredients', 'filters']))),
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: 0.995,
                source: 'deterministic_explicit_ingredient_catalog_segment'
            );
        }

        // Generic multi-product detail/safety/compare routing.
        // This must run before the single-product override; otherwise prompts like
        // "Check Sooper and Cheetos for Muslim safety" become one fake product name
        // "Sooper and Cheetos" and the DB lookup fails.
        $multiProductNames = $this->extractMultiProductNamesForDetailRequest($message);
        if (! empty($multiProductNames)) {
            return $this->buildDeterministicIntent(
                toolName: 'search_products',
                arguments: [
                    'query' => '',
                    'category' => null,
                    'brand' => null,
                    'origin' => null,
                    'origins' => [],
                    'product_names' => $multiProductNames,
                    'ingredients_include' => [],
                    'ingredients_exclude' => [],
                    'status_include' => [],
                    'status_exclude' => [],
                    'limit' => max(12, count($multiProductNames) * 3),
                    'image_context' => $imageContext ?? [],
                ],
                questionFocuses: $focuses,
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: 0.995,
                source: 'deterministic_multi_product_detail_segment'
            );
        }

        $nutritionIngredients = $this->extractNutritionIngredientFilters($message);
        if (! empty($nutritionIngredients) && $this->looksLikeNutritionCatalogRequest($message)) {
            $statusFiltersForNutritionCatalog = $this->extractStatusFiltersFromSegment($message);
            $nutritionCategory = $this->extractCategoryFromSegment($message);
            $nutritionOrigins = $this->extractOriginsFromSegment($message);

            return $this->buildDeterministicIntent(
                toolName: 'search_products',
                arguments: [
                    'query' => '',
                    'category' => $nutritionCategory,
                    'brand' => null,
                    'origin' => $nutritionOrigins[0] ?? null,
                    'origins' => $nutritionOrigins,
                    'product_names' => [],
                    'ingredients_include' => $nutritionIngredients,
                    'ingredients_exclude' => [],
                    'ingredient_query' => $message,
                    'status_include' => $statusFiltersForNutritionCatalog['include'],
                    'status_exclude' => $statusFiltersForNutritionCatalog['exclude'],
                    'match_mode' => $this->resolveSegmentIngredientMatchMode($nutritionIngredients, $message, 'any'),
                    'limit' => 12,
                    'image_context' => $imageContext ?? [],
                ],
                questionFocuses: array_values(array_unique(array_merge($focuses, ['ingredients', 'filters']))),
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: 0.99,
                source: 'deterministic_nutrition_catalog_segment'
            );
        }

        $category = $this->extractCategoryFromSegment($message);
        $origins = $this->extractOriginsFromSegment($message);
        $statusFilters = $this->extractStatusFiltersFromSegment($message);
        $ingredientFilters = $this->extractIngredientFiltersFromSegment($message);
        if ($this->hasDependentResultCheckWording($message)) {
            // A phrase like "check if any returned products contain gelatin"
            // is a post-check over returned cards, not a DB ingredient filter.
            $ingredientFilters = ['include' => [], 'exclude' => [], 'match_mode' => 'all'];
        }
        $dietFilters = $this->extractDietFiltersFromSegment($message);
        $genericBrowseQuery = $this->extractGenericCatalogBrowseQueryFromSegment($message);
        $directProductName = $this->extractDirectProductNameForDetail($message);
        $brandHint = $this->extractBrandHintFromSegment($message);

        // If wording is "products from X", generic brand extraction can mistake X
        // for a brand. Origin must win when both resolve to the same phrase.
        if ($brandHint !== null && ! empty($origins) && $this->brandHintMatchesAnyOrigin($brandHint, $origins)) {
            $brandHint = null;
        }

        // In category-list prompts like "suggest halal breakfast items like cereal",
        // generic words before "items/products" are not brands. Keep brand only for
        // explicit brand-catalog wording such as "Nestle products" or "products by X".
        if ($brandHint !== null && $category !== null && ! $this->looksLikeBrandCatalogRequest($message)) {
            $brandHint = null;
        }

        // Brand catalog requests must be list/search intents, not fuzzy product-name
        // details. Examples: "Nestle brand products", "all Nestle products",
        // "show me products of Cocol", "products by Haribo".
        if ($brandHint !== null && $this->looksLikeBrandCatalogRequest($message)) {
            return $this->buildDeterministicIntent(
                toolName: 'search_products',
                arguments: [
                    'query' => '',
                    'category' => null,
                    'brand' => $brandHint,
                    'origin' => $origins[0] ?? null,
                    'origins' => $origins,
                    'product_names' => [],
                    'ingredients_include' => $ingredientFilters['include'],
                    'ingredients_exclude' => $ingredientFilters['exclude'],
                    'status_include' => $statusFilters['include'],
                    'status_exclude' => $statusFilters['exclude'],
                    'diet_include' => $dietFilters['include'] ?? [],
                    'diet_exclude' => $dietFilters['exclude'] ?? [],
                    'limit' => 12,
                    'image_context' => $imageContext ?? [],
                ],
                questionFocuses: array_values(array_unique(array_merge($focuses, ['brand']))),
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: 0.99,
                source: 'deterministic_brand_catalog_segment'
            );
        }

        // Strong product-detail override.
        // Product names can contain category words ("Sticks Potato Snacks...", "Super Cake",
        // "Chicken Noodle Soup", "Peanut Butter"). If the segment is asking for barcode,
        // ingredients, details, or halal status of a named item, do not route it as a
        // broad category search just because words like snack/cake/chicken/butter appear.
        if ($directProductName !== null && $this->looksLikeSpecificProductDetailRequest($message)) {
            return $this->buildDeterministicIntent(
                toolName: 'find_product_by_name',
                arguments: [
                    'name' => $directProductName,
                    'image_context' => $imageContext ?? [],
                ],
                questionFocuses: $focuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.99,
                source: 'deterministic_specific_product_detail_segment'
            );
        }

        $bareProductName = $this->extractBareProductNameForLookup($message);
        if ($bareProductName !== null) {
            return $this->buildDeterministicIntent(
                toolName: 'find_product_by_name',
                arguments: [
                    'name' => $bareProductName,
                    'image_context' => $imageContext ?? [],
                ],
                questionFocuses: $focuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.96,
                source: 'deterministic_bare_product_name_segment'
            );
        }

        $hasIngredientFilters = ! empty($ingredientFilters['include']) || ! empty($ingredientFilters['exclude']);
        $hasDietCatalogFilter = (! empty($dietFilters['include']) || ! empty($dietFilters['exclude']))
            && ($this->segmentHasCatalogBrowseIntent($message) || $this->isPluralCatalogText($message));
        $hasStatusCatalogFilter = (! empty($statusFilters['include']) || ! empty($statusFilters['exclude']))
            && ($this->segmentHasCatalogBrowseIntent($message) || $this->isPluralCatalogText($message));

        $hasCatalogSignals = $this->segmentHasCatalogBrowseIntent($message)
            || $category !== null
            || $brandHint !== null
            || ! empty($origins)
            || $hasIngredientFilters
            || $hasDietCatalogFilter
            || $hasStatusCatalogFilter
            || $genericBrowseQuery !== null;

        // Direct product checks must beat ingredient catalog routing.
        // Examples: "Does Sprite contain alcohol?", "Nibb-it ... contain gelatin?",
        // and "Check if Kinder Bueno contains palm oil" are single-product questions,
        // not requests to list all alcohol/gelatin/palm-oil products.
        $isDirectProductCheck = $directProductName !== null
            && $this->looksLikeSpecificProductDetailRequest($message)
            && ! $this->segmentHasCatalogBrowseIntent($message);

        if ($isDirectProductCheck) {
            return $this->buildDeterministicIntent(
                toolName: 'find_product_by_name',
                arguments: [
                    'name' => $directProductName,
                    'image_context' => $imageContext ?? [],
                ],
                questionFocuses: $focuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.99,
                source: 'deterministic_direct_product_check'
            );
        }

        // Prefer catalog/search when the user asked for a list/filter. This fixes
        // prompts like "Find drinks without animal derived ingredients" where the
        // old router treated "drinks without animal derived" as a product name.
        $shouldRunCatalog = $hasCatalogSignals
            && ($category !== null || $brandHint !== null || ! empty($origins) || $hasIngredientFilters || $hasDietCatalogFilter || $hasStatusCatalogFilter || $genericBrowseQuery !== null)
            && ! ($directProductName !== null
                && $category === null
                && $brandHint === null
                && empty($origins)
                && ! $hasIngredientFilters
                && ! $hasDietCatalogFilter
                && ! $hasStatusCatalogFilter
                && ! $this->segmentHasCatalogBrowseIntent($message));

        if ($shouldRunCatalog) {
            $arguments = [
                'query' => $genericBrowseQuery ?? ($this->stripDependentResultCheckFromSearchQuery($message) ?: $message),
                'category' => $category,
                'brand' => $brandHint,
                'origin' => $origins[0] ?? null,
                'origins' => $origins,
                'product_names' => [],
                'ingredients_include' => $ingredientFilters['include'],
                'ingredients_exclude' => $ingredientFilters['exclude'],
                'status_include' => $statusFilters['include'],
                'status_exclude' => $statusFilters['exclude'],
                'diet_include' => $dietFilters['include'] ?? [],
                'diet_exclude' => $dietFilters['exclude'] ?? [],
                'limit' => 12,
                'image_context' => $imageContext ?? [],
            ];

            if (! empty($ingredientFilters['match_mode'])) {
                $arguments['match_mode'] = $ingredientFilters['match_mode'];
            }

            $catalogFocuses = array_values(array_unique(array_merge(
                $focuses,
                ! empty($statusFilters['include']) ? ['halal_status'] : [],
                ($hasIngredientFilters || $hasDietCatalogFilter || $hasStatusCatalogFilter) ? ['filters'] : []
            )));

            return $this->buildDeterministicIntent(
                toolName: 'search_products',
                arguments: $arguments,
                questionFocuses: $catalogFocuses,
                requiresCatalogResponse: true,
                isMultiProduct: true,
                confidence: 0.98,
                source: 'deterministic_catalog_segment'
            );
        }

        if ($directProductName !== null && ! $this->hasExplicitCatalogBrowsePhrase($message)) {
            return $this->buildDeterministicIntent(
                toolName: 'find_product_by_name',
                arguments: [
                    'name' => $directProductName,
                    'image_context' => $imageContext ?? [],
                ],
                questionFocuses: $focuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.98,
                source: 'deterministic_product_detail_segment'
            );
        }

        return null;
    }

    /**
     * Builds "deterministic intent" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildDeterministicIntent(
        string $toolName,
        array $arguments,
        array $questionFocuses,
        bool $requiresCatalogResponse,
        bool $isMultiProduct,
        float $confidence,
        string $source
    ): array {
        $questionFocuses = array_values(array_unique(array_filter($questionFocuses)));
        if ($questionFocuses === []) {
            $questionFocuses = ['details'];
        }

        return [
            'tool_name' => $toolName,
            'arguments' => $arguments,
            'input_arguments' => $arguments,
            'resolution_source' => $source,
            'intent' => $toolName,
            'question_focus' => $questionFocuses[0],
            'question_focuses' => $questionFocuses,
            'requires_catalog_response' => $requiresCatalogResponse,
            'is_multi_product' => $isMultiProduct,
            'needs_db_lookup' => in_array($toolName, ['find_product_by_barcode', 'find_product_by_name', 'search_products', 'explain_ingredient'], true),
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];
    }

    /**
     * Extracts "status filters from segment" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractMultiProductNamesForDetailRequest(string $message): array
    {
        $raw = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($raw === '' || preg_match('/\b\d{8,40}\b/u', $raw) === 1) {
            return [];
        }

        $lower = mb_strtolower($raw);
        $hasMultiConnector = preg_match('/\b(?:and|or|versus|vs)\b|,/iu', $lower) === 1;
        if (! $hasMultiConnector) {
            return [];
        }

        $candidate = null;
        if (preg_match('/^(?:please\s+)?compare\s+(.+?)(?:\s+(?:for|from|in)\b.*)?$/iu', $raw, $matches) === 1) {
            $candidate = (string) $matches[1];
        } elseif (preg_match('/^(?:please\s+)?(?:tell\s+me\s+about|check|lookup|look\s+up|find|search)\s+(.+?)(?:\s+(?:for\s+(?:muslim\s+)?safety|for\s+halal\s+status|for\s+muslims?|safe\s+for\s+muslims?|halal|haram|details?|ingredients?)\b.*)?$/iu', $raw, $matches) === 1) {
            $candidate = (string) $matches[1];
        } elseif (preg_match('/^(?:please\s+)?(?:is|are)\s+(.+?)\s+(?:safe|halal|haram|okay|ok|suitable|vegetarian|vegan|kosher|gluten[-\s]*free)\b.*$/iu', $raw, $matches) === 1) {
            $candidate = (string) $matches[1];
        }

        if ($candidate === null) {
            return [];
        }

        $candidate = preg_replace('/\b(?:for\s+(?:muslim\s+)?safety|for\s+halal\s+status|safe\s+for\s+muslims?|can\s+muslims?\s+(?:eat|use|consume)|halal\s+status|ingredients?|details?)\b.*$/iu', '', $candidate) ?? $candidate;
        $candidate = trim($candidate, " \t\n\r\0\x0B,.;:!?&");
        if ($candidate === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|\b(?:and|or|versus|vs)\b)\s*/iu', $candidate) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $name = $this->cleanupDirectProductCandidate((string) $part);
            if ($name !== null && ! $this->looksLikeNoisyProductName($name)) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique(array_filter($names)));
        return count($names) >= 2 ? $names : [];
    }

    protected function extractDietFiltersFromSegment(string $message): array
    {
        $lower = mb_strtolower($message);
        $include = [];
        $exclude = [];

        $patterns = [
            'vegetarian' => '/\bvegetarian\b/iu',
            'vegan' => '/\bvegan\b/iu',
            'kosher' => '/\bkosher\b/iu',
            'gluten_free' => '/\bgluten[-\s]*free\b/iu',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $lower) !== 1) {
                continue;
            }

            $negativePattern = match ($key) {
                'gluten_free' => '/\b(?:non|not|without|avoid|exclude|no)\s+gluten[-\s]*free\b/iu',
                default => '/\b(?:non|not|without|avoid|exclude|no)\s+' . preg_quote(str_replace('_', ' ', $key), '/') . '\b/iu',
            };

            if (preg_match($negativePattern, $lower) === 1) {
                $exclude[] = $key;
            } else {
                $include[] = $key;
            }
        }

        return [
            'include' => array_values(array_unique($include)),
            'exclude' => array_values(array_unique($exclude)),
        ];
    }

    protected function extractStatusFiltersFromSegment(string $message): array
    {
        $lower = mb_strtolower($message);
        $include = [];
        $exclude = [];

        $statusText = preg_replace('/not\s*[-\s]+haram/iu', 'not haram', $lower) ?? $lower;
        $statusText = preg_replace('/muslim[-\s]*friendly/iu', 'muslim friendly', $statusText) ?? $statusText;

        if ((preg_match('/\bhalal\b/iu', $statusText) === 1
                && preg_match('/\b(?:not\s+haram|not\s+marked\s+haram|muslim\s+friendly|safe\s+for\s+muslims?)\b/iu', $statusText) === 1)
            || preg_match('/\b(?:only\s+)?show\s+(?:me\s+)?(?:only\s+)?halal\b/iu', $statusText) === 1) {
            $include[] = 'halal';
        } elseif (preg_match('/\b(?:not|no|without|avoid|exclude|not\s+marked)\s+haram\b/iu', $statusText) === 1
            || preg_match('/\b(?:safe\s+for\s+muslims?|muslim\s*friendly|suitable\s+for\s+muslims?)\b/iu', $statusText) === 1) {
            $exclude[] = 'haram';
        }
        if (preg_match('/\b(?:not|no|without|avoid|exclude)\s+(?:unknown|pending|doubtful|mushbooh|mashbooh)\b/iu', $lower) === 1) {
            $exclude[] = 'unknown';
            $exclude[] = 'mushbooh';
        }

        if (preg_match('/(?<!not\s)\bhalal\b/iu', $lower) === 1
            && preg_match('/\b(?:or\s+at\s+least\s+not\s+haram|not\s+marked\s+haram)\b/iu', $lower) !== 1) {
            $include[] = 'halal';
        }
        if (preg_match('/(?<!not\s)(?<!not-)\bharam\b/iu', $statusText) === 1 && ! in_array('haram', $exclude, true) && ! in_array('halal', $include, true)) {
            $include[] = 'haram';
        }
        if (preg_match('/\b(mushbooh|mashbooh|doubtful)\b/iu', $lower) === 1 && ! in_array('mushbooh', $exclude, true)) {
            $include[] = 'mushbooh';
        }
        if (preg_match('/\b(unknown|pending|decision\s+pending)\b/iu', $lower) === 1 && ! in_array('unknown', $exclude, true)) {
            $include[] = 'unknown';
        }

        return [
            'include' => array_values(array_unique($include)),
            'exclude' => array_values(array_unique($exclude)),
        ];
    }


    /**
     * Finds included/excluded ingredients such as sugar, salt, alcohol, gelatin, palm oil, and animal-derived terms.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractIngredientFiltersFromSegment(string $message): array
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        $include = [];
        $exclude = [];
        $matchMode = 'all';

        $knownIngredients = [
            'palm oil', 'gelatin', 'gelatine', 'alcohol', 'ethanol', 'beef', 'pork', 'lard', 'chicken',
            'milk', 'whey', 'casein', 'lactose', 'cheese', 'butter', 'egg', 'eggs', 'honey',
            'soy', 'soya', 'wheat', 'gluten', 'rice', 'corn', 'peanut', 'peanuts',
            'almond', 'almonds', 'cocoa', 'chocolate', 'vanilla', 'sugar', 'salt', 'sodium', 'sodium chloride',
            'vegetable oil', 'canola oil', 'sunflower oil', 'olive oil', 'coconut oil', 'citric acid', 'lecithin', 'emulsifier', 'flavour', 'flavor',
            'mayonnaise', 'mayo', 'vitamin', 'vitamins', 'protein', 'fiber', 'fibre', 'calcium', 'iron', 'zinc', 'nutrient', 'nutrients', 'folic acid', 'vitamin b', 'vitamin b1', 'vitamin b2', 'vitamin b3', 'vitamin b6', 'vitamin b12', 'niacin', 'thiamine', 'riboflavin',
            'animal fat', 'rennet', 'carmine', 'enzymes', 'animal derived', 'animal-derived', 'animal driven', 'animal based',
            'spice', 'spices', 'spicy', 'masala', 'seasoning', 'seasonings', 'curry', 'curry spice', 'curry spice mix', 'chili', 'chilli', 'red chilli', 'paprika', 'pepper', 'black pepper', 'garlic powder', 'onion powder', 'turmeric', 'ginger',
        ];

        $hasIngredientSearchSignal = preg_match('/\b(?:contain|contains|containing|with|inside|without|with\s+no|no|free\s+from|free\s+of|ingredient|ingredients|has|have|having|include|includes|including|avoid|exclude|excluding|except|except\s+for|minus|omit|remove|leave\s+out|keep\s+out|but\s+not|but\s+no|not\s+with|not\s+having|not\s+have|not\s+inside|not\s+in|do\s+not\s+(?:contain|have|include)|does\s+not\s+(?:contain|have|include)|don\'t\s+(?:contain|have|include)|dont\s+(?:contain|have|include)|not\s+(?:contain|containing|have|having|include|including)|must\s+not\s+(?:contain|have|include)|should\s+not\s+(?:contain|have|include)|cannot\s+contain|can\'t\s+contain)\b/iu', $lower) === 1;
        $dependentAnimalCheckOnly = $this->hasDependentResultCheckWording($lower);
        $ingredientParseText = $dependentAnimalCheckOnly
            ? ($this->stripDependentResultCheckFromSearchQuery($lower) ?: $lower)
            : $lower;

        if (! $hasIngredientSearchSignal && preg_match('/\b(?:spicy|spiced|masala|seasoned|seasoning|curry\s+flavou?r|hot\s+flavou?r|chilli?|chili|paprika|pepper)\b/iu', $lower) === 1) {
            $include = array_merge($include, $this->expandIngredientConceptForSegment('spices'));
            $matchMode = 'any';
        }

        // DB-aware generic spicy-food rule:
        // "spicy items", "spicy options", "items that contain spices" should become
        // an ingredient search, not a Spices-category-only lookup.
        if (preg_match('/\b(?:spicy|spiced|spices?|masala|seasoning|seasonings|curry|chilli?|chili|paprika|pepper)\b/iu', $ingredientParseText) === 1
            && preg_match('/\b(?:items?|products?|options?|foods?|snacks?|chips|crisps|list|suggest|recommend|find|search|looking\s+for)\b/iu', $ingredientParseText) === 1) {
            $include = array_merge($include, $this->expandIngredientConceptForSegment('spices'));
            $matchMode = 'any';
        }

        if ($hasIngredientSearchSignal) {
            foreach ($knownIngredients as $ingredient) {
                $pattern = '/(?<![a-z0-9])' . preg_quote($ingredient, '/') . '(?![a-z0-9])/iu';
                if (preg_match($pattern, $ingredientParseText) !== 1) {
                    continue;
                }

                $isExcluded = preg_match('/\b(?:without|with\s+no|no|not|free\s+from|free\s+of|but\s+not|but\s+no|not\s+with|not\s+having|not\s+have|not\s+inside|not\s+in|does\s+not\s+(?:contain|have|include)|do\s+not\s+(?:contain|have|include)|don\'t\s+(?:contain|have|include)|dont\s+(?:contain|have|include)|not\s+(?:contain|containing|have|having|include|including)|must\s+not\s+(?:contain|have|include)|should\s+not\s+(?:contain|have|include)|cannot\s+contain|can\'t\s+contain|exclude|excluding|avoid|except|except\s+for|minus|omit|remove|leave\s+out|keep\s+out)\b.{0,90}' . preg_quote($ingredient, '/') . '/iu', $ingredientParseText) === 1;
                $isBroadAnimalTerm = preg_match('/\banimal\s*(?:derived|driven|based)?\b|animal-derived/iu', $ingredient) === 1;
                if (!$isExcluded && $isBroadAnimalTerm && $dependentAnimalCheckOnly) {
                    continue;
                }

                $expanded = $this->expandIngredientConceptForSegment($ingredient);

                if ($isExcluded) {
                    $exclude = array_merge($exclude, $expanded);
                } else {
                    $include = array_merge($include, $expanded);
                    if (count($expanded) > 1) {
                        $matchMode = 'any';
                    }
                }
            }
        }

        if (preg_match_all('/\b(?:contain|contains|containing|with|has|have|having|include|includes|including|must\s+have|must\s+be\s+having)\s+(.+?)(?=\s+(?:but|without|with\s+no|no|free\s+from|free\s+of|exclude|excluding|avoid|except|except\s+for|minus|omit|remove|leave\s+out|keep\s+out|from|made\s+in|inside|in\s+it|in\s+them|for\s+me|please|that\s+are|which\s+are|category|products?$)|[.?!;]|$)/iu', $ingredientParseText, $matches)) {
            foreach ($matches[1] as $phrase) {
                foreach ($this->splitIngredientSegmentTerms($phrase) as $term) {
                    $term = $this->cleanupIngredientSegmentPhrase($term);
                    if ($term !== '') {
                        $expanded = $this->expandIngredientConceptForSegment($term);
                        $include = array_merge($include, $expanded);
                        if (count($expanded) > 1) {
                            $matchMode = 'any';
                        }
                    }
                }
            }
        }

        if (preg_match_all('/\b(?:without|with\s+no|no|free\s+from|free\s+of|exclude|excluding|avoid|except|except\s+for|minus|omit|remove|leave\s+out|keep\s+out|but\s+not|but\s+no|not\s+with|not\s+having|not\s+have|not\s+inside|not\s+in|do\s+not\s+(?:contain|have|include)|does\s+not\s+(?:contain|have|include)|don\'t\s+(?:contain|have|include)|dont\s+(?:contain|have|include)|not\s+(?:contain|containing|have|having|include|including)|must\s+not\s+(?:contain|have|include)|should\s+not\s+(?:contain|have|include)|cannot\s+contain|can\'t\s+contain)\s+(.+?)(?=\s+(?:but|with|contain|contains|containing|include|includes|including|from|made\s+in|inside|in\s+it|in\s+them|for\s+me|please|that\s+are|which\s+are|category|products?$)|[.?!;]|$)/iu', $ingredientParseText, $matches)) {
            foreach ($matches[1] as $phrase) {
                foreach ($this->splitIngredientSegmentTerms($phrase) as $term) {
                    $term = $this->cleanupIngredientSegmentPhrase($term);
                    if ($term !== '') {
                        $exclude = array_merge($exclude, $this->expandIngredientConceptForSegment($term));
                    }
                }
            }
        }

        $include = array_values(array_unique(array_filter($include)));
        $exclude = array_values(array_unique(array_filter($exclude)));

        // If the same term appears as negative and positive, the negative wording wins.
        $include = array_values(array_diff($include, $exclude));

        // Connector-aware final mode. The earlier extraction can correctly find
        // terms like sugar/salt but default to ALL; this preserves explicit
        // user wording such as "sugar or salt" as ANY while keeping
        // "sugar and salt" strict.
        $matchMode = $this->resolveSegmentIngredientMatchMode($include, $message, $matchMode);

        return [
            'include' => $include,
            'exclude' => $exclude,
            'match_mode' => $matchMode,
        ];
    }

    /**
     * Helper method for "split ingredient segment terms".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function splitIngredientSegmentTerms(string $value): array
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
        $value = preg_replace('/\b(?:and\s+also\s+)?(?:tell|check|show|explain)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:if|whether)\s+(?:any\s+of\s+)?(?:them|these|those|products?|items?|results?)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:if|whether)\s+any\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:but|and)?\s*(?:is\s+)?not\s+(?:marked\s+)?haram\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:halal|haram|mushbooh|mashbooh|unknown|pending)\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:in\s+it|inside|as\s+ingredient|as\s+ingredients|products?|items?|things?|please|for\s+me)\b/iu', ' ', $value) ?? $value;
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $value = trim($value, " \t\n\r\0\x0B,.;:!?&");

        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|&|\band\b|\bor\b)\s*/iu', $value) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($part) => $part !== ''));
    }

    /**
     * Helper method for "expand ingredient concept for segment".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function expandIngredientConceptForSegment(string $ingredient): array
    {
        $ingredient = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $ingredient)));
        $ingredient = str_replace(['animal-derived', 'animal driven', 'animal-driven', 'animal based', 'animal-based', 'animal ingredients', 'animal ingredient'], 'animal derived', $ingredient);
        $ingredient = str_replace(['gelatine'], 'gelatin', $ingredient);
        $ingredient = str_replace(['mayonese', 'mayounese', 'mayo'], 'mayonnaise', $ingredient);
        $ingredient = str_replace('sodium chloride', 'salt', $ingredient);
        $ingredient = str_replace('sodium', 'salt', $ingredient);

        if (preg_match('/\banimal\s+(?:derived|driven|based)\b/iu', $ingredient) === 1) {
            return $this->animalDerivedIngredientTerms();
        }

        if (preg_match('/\bvitamin\s*b\b|\bb\s*vitamin\b/iu', $ingredient) === 1) {
            return ['vitamin b', 'vitamin b1', 'vitamin b2', 'vitamin b3', 'vitamin b6', 'vitamin b12', 'niacin', 'thiamine', 'riboflavin'];
        }

        if (preg_match('/\b(spicy|spiced|spice|spices|masala|seasoning|seasonings|curry|curry\s+spice(?:\s+mix)?|chilli?|chili|red\s+chilli|paprika|pepper|black\s+pepper|garlic\s+powder|onion\s+powder|turmeric|ginger)\b/iu', $ingredient) === 1) {
            return ['spices', 'spice', 'spicy', 'masala', 'seasoning', 'seasonings', 'curry', 'curry spice', 'curry spice mix', 'chili', 'chilli', 'red chilli', 'paprika', 'pepper', 'black pepper', 'garlic powder', 'onion powder', 'turmeric', 'ginger'];
        }

        return [$ingredient];
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
     * Cleans "cleanup ingredient segment phrase" before matching, resolving, or replying.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function cleanupIngredientSegmentPhrase(string $phrase): string
    {
        $phrase = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $phrase)));
        $phrase = preg_replace('/\b(?:but|and)?\s*(?:is\s+)?not\s+(?:marked\s+)?haram\b.*$/iu', '', $phrase) ?? $phrase;
        $phrase = preg_replace('/\b(?:products?|items?|options?|things?|that|which|those|contain|contains|containing|with|without|has|have|having|include|includes|including|in|it|inside|ingredient|ingredients|also|please|me|give|show|need|want|list|find|search|must|be|is|not|halal|haram|mushbooh|mashbooh|unknown|pending|deficiency|body|nutrients?|nutrient)\b/iu', ' ', $phrase) ?? $phrase;
        $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));
        $phrase = trim($phrase, " \t\n\r\0\x0B,.;:!?&");

        if ($phrase === '' || mb_strlen($phrase) < 3) {
            return '';
        }

        return $phrase;
    }

    /**
     * Repair high-confidence product/category details from the raw segment.
     * This keeps Gemini useful, but prevents it from losing explicit user filters.
     */
    /**
     * Final correction layer that makes intent fields safer before querying the database.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function applyDeterministicIntentFixes(
        string $message,
        string $toolName,
        array $arguments,
        array $intent,
        ?array $imageContext = null
    ): array {
        $message = trim($message);
        $focuses = $this->extractFocusesFromSegment($message);
        $directProductName = $this->extractDirectProductNameForDetail($message);
        $explicitCategory = $this->extractCategoryFromSegment($message);
        $explicitOrigins = $this->extractOriginsFromSegment($message);
        $explicitIngredientFilters = $this->extractIngredientFiltersFromSegment($message);
        $explicitStatusFilters = $this->extractStatusFiltersFromSegment($message);
        $explicitDietFilters = $this->extractDietFiltersFromSegment($message);

        if (! empty($focuses)) {
            $existingFocuses = is_array($intent['question_focuses'] ?? null)
                ? $intent['question_focuses']
                : array_filter([(string) ($intent['question_focus'] ?? '')]);
            $intent['question_focuses'] = array_values(array_unique(array_merge($existingFocuses, $focuses)));
            $intent['question_focus'] = $intent['question_focuses'][0] ?? ($intent['question_focus'] ?? 'details');
        }

        $barcodeCandidate = $this->extractBarcodeCandidateFromMessage($message);
        $hasNumericBarcode = preg_match('/\b\d{8,40}\b/u', $message) === 1;
        $barcodeArg = trim((string) ($arguments['barcode'] ?? ''));

        if ($barcodeCandidate !== null && $toolName !== 'find_product_by_barcode') {
            $toolName = 'find_product_by_barcode';
            $arguments = [
                'barcode' => $barcodeCandidate,
                'image_context' => $imageContext ?? [],
            ];
            $intent['tool_name'] = $toolName;
            $intent['intent'] = $toolName;
            $intent['is_multi_product'] = false;
            $intent['requires_catalog_response'] = false;
        }

        // "Sprite barcode" means: find product Sprite and answer with its barcode.
        // It does NOT mean an empty barcode lookup.
        if ($toolName === 'find_product_by_barcode' && $barcodeArg === '' && ! $hasNumericBarcode && $directProductName !== null) {
            $toolName = 'find_product_by_name';
            $arguments = [
                'name' => $directProductName,
                'image_context' => $imageContext ?? [],
            ];
            $intent['tool_name'] = $toolName;
            $intent['intent'] = $toolName;
            $intent['is_multi_product'] = false;
            $intent['requires_catalog_response'] = false;
        }

        // If Gemini treated a direct product-detail segment as a catalog search,
        // switch it back to single-product lookup.
        if (in_array($toolName, ['search_products', 'answer_without_db'], true)
            && $directProductName !== null
            && (
                $this->looksLikeSpecificProductDetailRequest($message)
                || (! $this->hasExplicitCatalogBrowsePhrase($message) && $explicitCategory === null)
            )
            && empty($explicitOrigins)
            && ! $hasNumericBarcode) {
            $toolName = 'find_product_by_name';
            $arguments = [
                'name' => $directProductName,
                'image_context' => $imageContext ?? [],
            ];
            $intent['tool_name'] = $toolName;
            $intent['intent'] = $toolName;
            $intent['is_multi_product'] = false;
            $intent['requires_catalog_response'] = false;
        }

        if ($toolName === 'find_product_by_name') {
            $name = trim((string) ($arguments['name'] ?? ''));
            if (($name === '' || $this->looksLikeNoisyProductName($name)) && $directProductName !== null) {
                $arguments['name'] = $directProductName;
            }
            if (! empty($imageContext) && ! isset($arguments['image_context'])) {
                $arguments['image_context'] = $imageContext;
            }
        }

        if ($toolName === 'search_products') {
            $nutritionIngredients = $this->extractNutritionIngredientFilters($message);
            $isNutritionCatalogRequest = ! empty($nutritionIngredients) && $this->looksLikeNutritionCatalogRequest($message);
            if ($this->explicitIngredientFiltersShouldBeatNutrition(is_array($explicitIngredientFilters['include'] ?? null) ? $explicitIngredientFilters['include'] : [])) {
                $isNutritionCatalogRequest = false;
            }

            $hasIngredientFiltersAtArgumentLevel = ! empty($arguments['ingredients_include'])
                || ! empty($arguments['ingredients_exclude'])
                || ! empty($explicitIngredientFilters['include'])
                || ! empty($explicitIngredientFilters['exclude']);

            // Important: ingredient/catalog prompts often contain conversational wrappers like
            // "I need items..." or "Can you show me some items...". Generic brand
            // extraction can otherwise capture "i need" / "you" as a fake brand, which
            // adds a hard brand filter and hides real ingredient matches from the database.
            // When ingredient filters are present, ingredient search must win unless a brand
            // was already explicitly resolved by the intent layer.
            if (! $isNutritionCatalogRequest && ! $hasIngredientFiltersAtArgumentLevel) {
                $brandHint = $this->extractBrandHintFromSegment($message);
                if ($brandHint !== null && (empty($arguments['brand']) || trim((string) $arguments['brand']) === '')) {
                    $arguments['brand'] = $brandHint;
                }
            } elseif ($isNutritionCatalogRequest) {
                $arguments['brand'] = null;
            }

            if (($arguments['category'] ?? null) === null || trim((string) ($arguments['category'] ?? '')) === '') {
                if ($explicitCategory !== null) {
                    $arguments['category'] = $explicitCategory;
                }
            }

            $existingOrigins = $arguments['origins'] ?? [];
            $hasOrigins = is_array($existingOrigins) ? ! empty($existingOrigins) : trim((string) $existingOrigins) !== '';
            if (! $hasOrigins && (empty($arguments['origin']) || trim((string) $arguments['origin']) === '') && ! empty($explicitOrigins)) {
                $arguments['origin'] = $explicitOrigins[0];
                $arguments['origins'] = $explicitOrigins;
            }

            if (empty($arguments['status_include']) && ! empty($explicitStatusFilters['include'] ?? [])) {
                $arguments['status_include'] = $explicitStatusFilters['include'];
            }

            if (empty($arguments['status_exclude']) && ! empty($explicitStatusFilters['exclude'] ?? [])) {
                $arguments['status_exclude'] = $explicitStatusFilters['exclude'];
            }

            if (empty($arguments['ingredients_include']) && ! empty($explicitIngredientFilters['include'])) {
                $arguments['ingredients_include'] = $explicitIngredientFilters['include'];
            }

            if (empty($arguments['ingredients_exclude']) && ! empty($explicitIngredientFilters['exclude'])) {
                $arguments['ingredients_exclude'] = $explicitIngredientFilters['exclude'];
            }

            if (empty($arguments['diet_include']) && ! empty($explicitDietFilters['include'])) {
                $arguments['diet_include'] = $explicitDietFilters['include'];
            }

            if (empty($arguments['diet_exclude']) && ! empty($explicitDietFilters['exclude'])) {
                $arguments['diet_exclude'] = $explicitDietFilters['exclude'];
            }

            if (! empty($arguments['product_names'] ?? [])) {
                $arguments['query'] = '';
                $arguments['brand'] = null;
                $arguments['category'] = null;
            }

            if (empty($arguments['match_mode']) && ! empty($explicitIngredientFilters['match_mode'])) {
                $arguments['match_mode'] = $explicitIngredientFilters['match_mode'];
            }

            if ($hasIngredientFiltersAtArgumentLevel && empty($arguments['ingredient_query'])) {
                $arguments['ingredient_query'] = $message;
            }

            if ($isNutritionCatalogRequest) {
                $arguments['query'] = '';
                $arguments['brand'] = null;
                $arguments['category'] = $explicitCategory ?? ($arguments['category'] ?? null);
                if (! empty($explicitOrigins)) {
                    $arguments['origin'] = $explicitOrigins[0];
                    $arguments['origins'] = $explicitOrigins;
                } else {
                    $arguments['origin'] = $arguments['origin'] ?? null;
                    $arguments['origins'] = is_array($arguments['origins'] ?? null) ? $arguments['origins'] : [];
                }
                $arguments['product_names'] = [];
                $arguments['ingredients_include'] = $nutritionIngredients;
                $arguments['ingredients_exclude'] = $arguments['ingredients_exclude'] ?? [];
                $arguments['ingredient_query'] = $message;
                $arguments['match_mode'] = 'any';
                $intent['question_focuses'] = array_values(array_unique(array_merge(
                    is_array($intent['question_focuses'] ?? null) ? $intent['question_focuses'] : [],
                    ['ingredients', 'filters']
                )));
                $intent['question_focus'] = $intent['question_focuses'][0] ?? 'ingredients';
            }

            // Ensure ProductLookup can recover filters from the raw segment even
            // if Gemini left query empty, but strip dependent result-set checks so
            // "find products with palm oil and tell me if any contain gelatin" does
            // not become a DB filter for both palm oil AND gelatin.
            $safeQuery = $this->stripDependentResultCheckFromSearchQuery($message);
            $hasExplicitIngredientFilters = ! empty($arguments['ingredients_include']) || ! empty($arguments['ingredients_exclude']);

            // If filters were already extracted, especially nutrition filters, do not put
            // the long human sentence back into query. The raw sentence can contain
            // words like "you", "items", "hungry", "gym" which pollute brand/query matching.
            if ($isNutritionCatalogRequest || ($hasExplicitIngredientFilters && empty($arguments['query']))) {
                $arguments['query'] = '';
            } elseif ($safeQuery !== '' && $safeQuery !== $message) {
                $arguments['query'] = $safeQuery;
            } elseif (empty($arguments['query'])) {
                $arguments['query'] = $message;
            }
        }

        return [
            'tool_name' => $toolName,
            'arguments' => $arguments,
            'intent' => $intent,
        ];
    }

    /**
     * Extracts "focuses from segment" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    /**
     * Routes generic image follow-up questions to the product detected from the image.
     * These prompts usually contain pronouns or generic wording, so without this guard
     * they can become ingredient/category searches instead of a product-specific check.
     */
    protected function resolveImageAnchoredFollowUpIntent(string $message, ?array $imageContext, array $fallbackFocuses = []): ?array
    {
        if (! $this->isImageAnchoredFollowUpQuestion($message, $imageContext)) {
            return null;
        }

        $imageContext = is_array($imageContext) ? $imageContext : [];
        $barcode = trim((string) ($imageContext['barcode'] ?? ''));
        $productName = trim((string) ($imageContext['product_name'] ?? ''));

        $focuses = $this->extractFocusesFromSegment($message);
        if (empty($focuses)) {
            $focuses = ! empty($fallbackFocuses) ? $fallbackFocuses : ['details'];
        }

        if ($productName !== '') {
            return $this->buildDeterministicIntent(
                toolName: 'find_product_by_name',
                arguments: [
                    'name' => $productName,
                    'image_context' => $imageContext,
                ],
                questionFocuses: $focuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.995,
                source: 'deterministic_image_followup_product'
            );
        }

        if ($barcode !== '') {
            return $this->buildDeterministicIntent(
                toolName: 'find_product_by_barcode',
                arguments: [
                    'barcode' => $barcode,
                    'image_context' => $imageContext,
                ],
                questionFocuses: $focuses,
                requiresCatalogResponse: false,
                isMultiProduct: false,
                confidence: 0.995,
                source: 'deterministic_image_followup_barcode'
            );
        }

        return null;
    }

    protected function isImageAnchoredFollowUpQuestion(string $message, ?array $imageContext): bool
    {
        if (empty($imageContext) || ! is_array($imageContext)) {
            return false;
        }

        if (trim((string) ($imageContext['product_name'] ?? '')) === ''
            && trim((string) ($imageContext['barcode'] ?? '')) === '') {
            return false;
        }

        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return false;
        }

        // If the user is asking for a separate catalog/list, do not anchor it to the image.
        // Example: image uploaded + "show me halal drinks" should remain a normal catalog search.
        if (preg_match('/\b(?:show|list|suggest|recommend|find|search|give|need|want)\b[^.?!;]{0,120}\b(?:products?|items?|options?|drinks?|juices?|snacks?|chips|chocolates?|biscuits?|cakes?|pasta|noodles?|sauces?|burgers?|pizzas?)\b/iu', $lower) === 1) {
            return false;
        }

        // Product-detail prompts sent with an image must be anchored to the image,
        // even when the text is generic: "tell me about this product", "what is this?",
        // "check this item", "details", "product name", etc.
        if (preg_match('/\b(?:it|this|that|this\s+product|this\s+item|the\s+product|the\s+item|shown\s+product|uploaded\s+image|image\s+product)\b/iu', $lower) === 1
            && preg_match('/\b(?:about|details?|detail|name|identify|recognize|recognise|what\s+is|which\s+product|product\s+name|tell\s+me|check|lookup|look\s+up|halal|haram|mushbooh|mashbooh|safe|safety|harmful|substances?|ingredients?|inside|barcode|bar\s*code|contain|contains|containing|has|have|alcohol|gelatin|gelatine|pork|animal[-\s]*derived|palm\s+oil|consume|eat|drink|use)\b/iu', $lower) === 1) {
            return true;
        }

        // Very short detail prompts sent with an image: "halal?", "ingredients?", "barcode?", "details?".
        if (preg_match('/^(?:is\s+)?(?:it\s+)?(?:halal|haram|halal\s+or\s+haram|ingredients?|inside|barcode|bar\s*code|safe|harmful|details?|detail|product\s+name|name|identify|contains?\s+alcohol|contains?\s+gelatin)[?؟.!\s]*$/iu', $lower) === 1) {
            return true;
        }

        // Generic safety/ingredient/detail question without product name, but with an uploaded image.
        return preg_match('/\b(?:tell\s+me\s+about|what\s+is\s+this|which\s+product|identify|recognize|recognise|details?|product\s+name|harmful|substances?|suspicious|unsafe|bad\s+for\s+health|ingredients?|inside|halal\s+or\s+haram|safe\s+for\s+muslims?)\b/iu', $lower) === 1
            && ! $this->containsNamedProductOutsideImageFollowUp($lower);
    }

    protected function containsNamedProductOutsideImageFollowUp(string $lower): bool
    {
        // Known direct product names should be resolved normally rather than forced to image context.
        // This keeps prompts like "is Sprite halal?" stable even if an old image context is present.
        return preg_match('/\b(?:sprite|coke|cola|pepsi|dairy\s+milk|dairymilk|kinder\s+bueno|almond\s+milk|dettol)\b/iu', $lower) === 1;
    }

    protected function extractFocusesFromSegment(string $message): array
    {
        $lower = mb_strtolower($this->normalizeIntentText($message));
        $focuses = [];

        if (preg_match('/\b(barcode|bar\s*code)\b/iu', $lower) === 1) {
            $focuses[] = 'barcode';
        }
        if (preg_match('/\b(ingredients?|inside|contains?|containing|made\s+of|what\s+is\s+in|with|without)\b/iu', $lower) === 1) {
            $focuses[] = 'ingredients';
            $focuses[] = 'filters';
        }
        if (preg_match('/\b(animal|derived|driven|gelatin|gelatine|carmine|pork|rennet|vegan|vegetarian)\b/iu', $lower) === 1) {
            $focuses[] = 'animal_derived_check';
        }
        if (preg_match('/\b(alcohol|alcoholic|ethanol|wine|beer|rum|spirit)\b/iu', $lower) === 1) {
            $focuses[] = 'alcohol_check';
        }
        if (preg_match('/\b(?:halal|haram|mushbooh|mashbooh|permissible|halal\s+status|status)\b/iu', $lower) === 1) {
            $focuses[] = 'halal_status';
        }
        if (preg_match('/\b(safe|safety|okay|ok|suitable|muslim[-\s]*friendly|safe\s+for\s+muslims?)\b/iu', $lower) === 1) {
            $focuses[] = 'halal_status';
            $focuses[] = 'ingredients';
            $focuses[] = 'suspicious_check';
        }
        if (preg_match('/\b(harmful|unsafe|unhealthy|bad\s+for\s+health|suspicious|problematic|substances?|anything\s+bad|avoid)\b/iu', $lower) === 1) {
            $focuses[] = 'ingredients';
            $focuses[] = 'suspicious_check';
            $focuses[] = 'health_check';
        }
        if (preg_match('/\b(origin|country|made\s+in)\b/iu', $lower) === 1) {
            $focuses[] = 'origin';
        }
        if (preg_match('/\bbrand\b/iu', $lower) === 1) {
            $focuses[] = 'brand';
        }

        return array_values(array_unique($focuses));
    }

    /**
     * Boolean helper that checks whether the current request or product matches "looks like specific product detail request".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function looksLikeSpecificProductDetailRequest(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return false;
        }

        $candidate = $this->extractDirectProductNameForDetail($message);
        if ($candidate === null) {
            return false;
        }

        $hasDirectProductWording = preg_match('/\b(?:scanned|scan|selected|picked|before\s+i\s+(?:buy|purchase|take)|want\s+to\s+check|need\s+to\s+check|does|do|is|are|check|tell|tell\s+me|give\s+me|about)\b/iu', $lower) === 1;

        if (! $hasDirectProductWording && $this->hasExplicitCatalogBrowsePhrase($lower)) {
            return false;
        }

        if (! $hasDirectProductWording
            && preg_match('/\b(show|list|suggest|recommend|need|want|available|have|fetch|bring)\b.*\b(products?|items?|options?|burgers?|pizzas?|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|burgers?|pizzas?|snacks?|chocolates?|biscuits?|cookies?|cakes?|(?:candy|candies)|bakery|bread|breads?|loaves|loaf|toast|breakfast|cereals?|oats?|granola|tea\s*bags?|tea|dairy|dairy\s+alternatives?|milk\s+alternatives?|household|cleaning|cleaner|hand\s*washes?|handwashes?|soap|antiseptic|oils?|sweeteners?|condiments?|sauces?|mayou?n+ai?se|mayonese|mayounese|beef|meat|chicken|spices?|spicy|masala|seasonings?|bread)\b/iu', $lower) === 1) {
            return false;
        }

        $hasDetailWord = preg_match('/\b(barcode|bar\s*code|ingredients?|ingredient|details?|origin|brand|status|halal|haram|safe|safety|okay|ok|about|tell|tell\s+me|contain|contains|containing|has|have|with|alcohol|gelatin|gelatine|animal[-\s]*derived|palm\s+oil)\b/iu', $lower) === 1;
        if (! $hasDetailWord) {
            return false;
        }

        $tokens = preg_split('/[^\pL\pN]+/u', mb_strtolower($candidate)) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($token) => mb_strlen($token) >= 2));

        return count($tokens) >= 1;
    }

    /**
     * Extracts "bare product name for lookup" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractBareProductNameForLookup(string $message): ?string
    {
        $raw = trim((string) preg_replace('/\s+/u', ' ', $message));
        if ($raw === '' || preg_match('/\b\d{8,14}\b/u', $raw) === 1) {
            return null;
        }

        $lower = mb_strtolower($raw);

        // Bare product lookup is only for short item names such as "peanut butter",
        // "penut butter", "sprite", or "kinder bueno". It must not steal list/filter
        // requests like "show snacks", "products with sugar", or "drinks from UK".
        if (preg_match('/\b(show|list|suggest|recommend|need|want|available|have|give|find|search|fetch|bring|products?|items?|from|made\s+in|with|without|contain|contains|containing|ingredient|ingredients|halal|haram|barcode|bar\s*code|safe|muslim\s*friendly)\b/iu', $lower) === 1) {
            return null;
        }

        $tokens = preg_split('/[^\pL\pN]+/u', $lower) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($token) => $token !== ''));
        if (empty($tokens) || count($tokens) > 5) {
            return null;
        }

        if ($this->looksLikeNoisyProductName($raw)) {
            return null;
        }

        // Avoid treating pure category words as products.
        $categoryOnlyWords = [
            'drink', 'drinks', 'juice', 'juices', 'burger', 'burgers', 'pizza', 'pizzas', 'snack', 'snacks', 'chips', 'crisps',
            'chocolate', 'chocolates', 'biscuit', 'biscuits', 'cookie', 'cookies',
            'dairy', 'beef', 'meat', 'chicken', 'cake', 'cakes', 'candy', 'candies',
            'bakery', 'household', 'cleaning', 'cleaner', 'soap', 'oil', 'oils',
            'sweetener', 'sweeteners', 'condiment', 'condiments', 'mayonnaise',
            'sauce', 'sauces', 'spice', 'spices', 'bread', 'butter', 'milk',
        ];

        $hasNonCategoryToken = false;
        foreach ($tokens as $token) {
            if (! in_array($token, $categoryOnlyWords, true)) {
                $hasNonCategoryToken = true;
                break;
            }
        }

        return $hasNonCategoryToken ? $raw : null;
    }

    /**
     * Extracts "direct product name for detail" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractDirectProductNameForDetail(string $message): ?string
    {
        $raw = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($raw === '' || preg_match('/\b\d{8,14}\b/u', $raw) === 1) {
            return null;
        }

        $lower = mb_strtolower($raw);
        $hasDetailWord = preg_match('/\b(barcode|bar\s*code|ingredients?|ingredient|status|halal|haram|safe|safety|okay|ok|details?|origin|brand|about|tell|tell\s+me|contain|contains|containing|has|have|with|alcohol|gelatin|gelatine|animal[-\s]*derived|palm\s+oil|buy|purchase|eat|consume|use|muslim|vegetarian|vegan|kosher|gluten[-\s]*free)\b/iu', $lower) === 1;
        if (! $hasDetailWord) {
            return null;
        }

        // Natural scanned/selected wording: "I scanned Sprite and now tell me if it contains alcohol".
        if (preg_match('/\b(?:scanned|scan|selected|picked|chose|choose|checking|buying)\s+(.+?)(?=\s+(?:and\s+(?:now\s+)?(?:tell|check|show|explain)|before\b|because\b|for\b)|[,.;?!]|$)/iu', $raw, $scanMatches) === 1) {
            $scanCandidate = $this->cleanupDirectProductCandidate($scanMatches[1]);
            if ($scanCandidate !== null) {
                return $scanCandidate;
            }
        }

        if (preg_match('/\b(?:want|need)\s+to\s+check\s+(.+?)(?=\s+(?:before|because|and\s+(?:tell|check|show|explain))\b|[,.;?!]|$)/iu', $raw, $wantMatches) === 1) {
            $wantCandidate = $this->cleanupDirectProductCandidate($wantMatches[1]);
            if ($wantCandidate !== null) {
                return $wantCandidate;
            }
        }

        if (preg_match('/^(?:please\s+)?(?:can|could|would)\s+i\s+(?:buy|purchase|eat|consume|use|take)\s+(.+?)(?=\s*(?:\?|[.;!]|$))/iu', $raw, $buyMatches) === 1) {
            $buyCandidate = $this->cleanupDirectProductCandidate($buyMatches[1]);
            if ($buyCandidate !== null) {
                return $buyCandidate;
            }
        }

        if (preg_match('/\b(?:i\s+am\s+muslim|as\s+a\s+muslim|muslim)\b.*?\b(?:can|could)\s+i\s+(?:buy|purchase|eat|consume|use|take)\s+(.+?)(?=\s*(?:\?|[.;!]|$))/iu', $raw, $muslimUseMatches) === 1) {
            $muslimUseCandidate = $this->cleanupDirectProductCandidate($muslimUseMatches[1]);
            if ($muslimUseCandidate !== null) {
                return $muslimUseCandidate;
            }
        }

        if (preg_match('/\bbefore\s+i\s+(?:buy|purchase|take)\s+(.+?)(?=\s*,|\s+(?:tell|check|show|explain)\b|[.;?!]|$)/iu', $raw, $beforeMatches) === 1) {
            $beforeCandidate = $this->cleanupDirectProductCandidate($beforeMatches[1]);
            if ($beforeCandidate !== null) {
                return $beforeCandidate;
            }
        }

        if (preg_match('/^(?:please\s+)?(?:is|are)\s+(.+?)\s+(?:safe|okay|ok|suitable|halal|haram|mushbooh|mashbooh)\b/iu', $raw, $safeMatches) === 1) {
            $safeCandidate = $this->cleanupDirectProductCandidate($safeMatches[1]);
            if ($safeCandidate !== null) {
                return $safeCandidate;
            }
        }

        // Conversational direct checks: "Can you check Dairy Milk and tell me if it has gelatin?"
        // This must become a single-product lookup for Dairy Milk, not a fuzzy lookup for
        // the noisy phrase "can you check dairy milk".
        if (preg_match('/^(?:please\s+)?(?:can|could|would)\s+you\s+(?:please\s+)?(?:check|tell\s+me\s+about|tell\s+me|look\s+up|lookup)\s+(.+?)\s+(?:and\s+)?(?:tell\s+me\s+)?(?:if|whether)\s+(?:it|this|that|the\s+product)?\s*(?:contains?|has|have|with|includes?)\s+(?:any\s+)?(?:alcohol(?:ic)?|ethanol|gelatin|gelatine|palm\s+oil|animal[-\s]*derived|animal\s+derived|pork|lard|carmine|rennet|enzymes?)\b/iu', $raw, $conversationalMatches) === 1) {
            $conversationalCandidate = $this->cleanupDirectProductCandidate($conversationalMatches[1]);
            if ($conversationalCandidate !== null) {
                return $conversationalCandidate;
            }
        }

        // Direct yes/no ingredient checks can contain category words inside the product name
        // (for example "Nibb-it Sticks Potato Snacks Plenty of Flavor"). Extract the
        // product before applying catalog-browse rejection.
        if (preg_match('/^(?:please\s+)?(?:does|do|is|are|check\s+(?:if|whether)|tell\s+me\s+(?:if|whether))\s+(.+?)\s+(?:contain|contains|containing|has|have|with|include|includes)\s+(?:any\s+)?(?:alcohol(?:ic)?|ethanol|gelatin|gelatine|palm\s+oil|animal[-\s]*derived|animal\s+derived|pork|lard|carmine|rennet|enzymes?)\b/iu', $raw, $directMatches) === 1) {
            $directCandidate = $this->cleanupDirectProductCandidate($directMatches[1]);
            if ($directCandidate !== null) {
                return $directCandidate;
            }
        }

        // Do not reject a direct product just because its name contains a category word.
        // Examples: "Chicken Noodle Soup ingredients", "Chicken Tikka Mix details",
        // "Super Cake barcode", and "Kinder Bueno ingredients" are single-product
        // detail requests, not category browsing requests. Only reject clear browse/list
        // phrases such as "chicken products" or "products that contain palm oil".
        if ($this->hasExplicitCatalogBrowsePhrase($raw)) {
            return null;
        }

        $candidate = $raw;
        $candidate = preg_replace('/^(?:please\s+)?(?:give|show|tell|find|check|search|need|want)\s+(?:me\s+)?(?:the\s+)?/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^(?:tell\s+me\s+(?:about|if|whether)|about|details?\s+of|ingredients?\s+of|barcode\s+of|bar\s*code\s+of)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^(?:if|whether)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:give|show|tell)\s+(?:me\s+)?(?:its|the)?\s*(?:barcode|bar\s*code|ingredients?|details?|origin|brand).*$/iu', ' ', $candidate) ?? $candidate;
        // Strip safety and ingredient-check tails while preserving the product name.
        $candidate = preg_replace('/\b(?:safe|safety|okay|ok|suitable)\b.*$/iu', ' ', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:contain|contains|containing|has|have|with|include|includes)\s+(?:any\s+)?(?:alcohol(?:ic)?|ethanol|gelatin|gelatine|palm\s+oil|animal[-\s]*derived|animal\s+derived|pork|lard|carmine|rennet|enzymes?)\b.*$/iu', ' ', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:barcode|bar\s*code|ingredients?|ingredient|details?|origin|brand|status|halal|haram)\b.*$/iu', ' ', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:separately|separate|if|whether|is|are|does|do|it|its|the|a|an|product|need|want|now|before|buy|buying|purchase|purchasing|for|muslims?)\b/iu', ' ', $candidate) ?? $candidate;

        return $this->cleanupDirectProductCandidate($candidate);
    }

    /**
     * Canonicalizes common product-name spellings/typos after extraction.
     * This keeps fuzzy DB lookup tight and prevents extra unrelated products.
     */
    protected function canonicalProductName(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($name)));
        $lower = mb_strtolower($clean);

        return match ($lower) {
            'sprite' => 'Sprite',
            'dairy milk', 'dairymilk' => 'Dairy Milk',
            'detol', 'dettol' => 'Dettol',
            'kinder bueno', 'kinder' => 'Kinder Bueno',
            'dorito', 'doritos' => 'Doritos',
            'peanut butter', 'penut butter' => 'Peanut Butter',
            default => $clean,
        };
    }

    /**
     * Boolean helper that checks whether the current request or product matches "looks like noisy product name".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function looksLikeNoisyProductName(string $name): bool
    {
        $name = mb_strtolower(trim($name));
        if ($name === '') {
            return true;
        }

        // A product can legitimately contain a category word in its name
        // (example: "Dairy Milk"). Treat it as noisy only when all tokens are
        // request/category words.
        $tokens = preg_split('/[^\pL\pN]+/u', $name) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($token) => $token !== ''));

        if (empty($tokens)) {
            return true;
        }

        $noise = [
            'show', 'list', 'suggest', 'recommend', 'give', 'need', 'want', 'can', 'could', 'would', 'you', 'please', 'check', 'tell', 'me',
            'product', 'products', 'item', 'items', 'drink', 'drinks', 'juice',
            'juices', 'beverage', 'beverages', 'snack', 'snacks', 'chocolate', 'chocolates', 'biscuit',
            'biscuits', 'bakery', 'dairy', 'household', 'cleaning', 'cleaner', 'soap', 'oil', 'oils',
            'sweetener', 'sweeteners', 'condiment', 'condiments', 'beef', 'chicken', 'from', 'barcode', 'bar',
            'code', 'ingredient', 'ingredients', 'about', 'details', 'status',
            'not', 'halal', 'haram', 'safe', 'muslim', 'friendly', 'unknown', 'mushbooh',
        ];

        foreach ($tokens as $token) {
            if (! in_array($token, $noise, true)) {
                return false;
            }
        }

        return true;
    }


    /**
     * Boolean helper that checks whether the current request or product matches "has explicit catalog browse phrase".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function hasExplicitCatalogBrowsePhrase(string $message): bool
    {
        $lower = mb_strtolower($this->normalizeIntentText($message));

        if (preg_match('/\b(products?|items?)\b/iu', $lower) === 1) {
            return true;
        }

        // Include find/search here. Otherwise "Find drinks without animal-derived ingredients"
        // can be misread as a single product named "drinks without animal derived".
        return preg_match('/\b(show|list|suggest|recommend|need|want|available|have|give|find|search|fetch|bring)\b.*\b(drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|burgers?|pizzas?|snacks?|chocolates?|biscuits?|cookies?|cakes?|(?:candy|candies)|bakery|bread|breads?|loaves|loaf|toast|breakfast|cereals?|oats?|granola|tea\s*bags?|tea|dairy|dairy\s+alternatives?|milk\s+alternatives?|household|cleaning|cleaner|bathroom|kitchen|hand\s*washes?|handwashes?|soap|antiseptic|oils?|sweeteners?|condiments?|mayou?n+ai?se|mayonese|mayounese|beef|meat|chicken|sauces?|spices?|spicy|masala|seasonings?|bread)\b/iu', $lower) === 1
            || preg_match('/\b(drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|burgers?|pizzas?|snacks?|chocolates?|biscuits?|cookies?|cakes?|(?:candy|candies)|bakery|bread|breads?|loaves|loaf|toast|breakfast|cereals?|oats?|granola|tea\s*bags?|tea|dairy|dairy\s+alternatives?|milk\s+alternatives?|household|cleaning|cleaner|hand\s*washes?|handwashes?|soap|antiseptic|oils?|sweeteners?|condiments?|mayou?n+ai?se|mayonese|mayounese|beef|meat|chicken|sauces?|spices?|bread)\b.*\b(without|with|contain|contains|containing|do\s+not\s+contain|does\s+not\s+contain|not\s+contain|free\s+from)\b/iu', $lower) === 1;
    }


    /**
     * Extracts a generic catalog browse query when the user asks for options/deals
     * but does not use a known category word. This keeps prompts like
     * "show me some great burger deal options" as a database search instead of
     * a fragile single-product lookup or Gemini-only route.
     */
    protected function extractGenericCatalogBrowseQueryFromSegment(string $message): ?string
    {
        $raw = trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message)));
        if ($raw === '') {
            return null;
        }

        $lower = mb_strtolower($raw);
        if (preg_match('/\b(?:barcode|bar\s*code|ingredients?|halal\s+status|status|safe\s+for\s+muslims?|contain|contains|containing|alcohol|gelatin|gelatine|pork|animal[-\s]*derived)\b/iu', $lower) === 1
            && preg_match('/\b(?:options?|deals?|products?|items?|show|list|suggest|recommend|find|search|give)\b/iu', $lower) !== 1) {
            return null;
        }

        $hasBrowseWording = preg_match('/\b(?:show|list|suggest|recommend|find|search|give|need|want|fetch|bring)\b/iu', $lower) === 1
            && preg_match('/\b(?:options?|deals?|products?|items?|menus?|available|any|some)\b/iu', $lower) === 1;

        if (! $hasBrowseWording) {
            return null;
        }

        $clean = preg_replace('/\b(?:please|show|list|suggest|recommend|find|search|give|need|want|fetch|bring|me|some|any|available|options?|products?|items?|menus?)\b/iu', ' ', $raw) ?? $raw;
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        $clean = trim($clean, " \t\n\r\0\x0B,.;:!?؟");

        if ($clean === '' || mb_strlen($clean) < 2 || $this->looksLikeNoisyProductName($clean)) {
            return null;
        }

        return $clean;
    }

    /**
     * Helper method for "segment has catalog browse intent".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function segmentHasCatalogBrowseIntent(string $message): bool
    {
        $lower = mb_strtolower($this->normalizeIntentText($message));

        return preg_match('/\b(show|list|suggest|recommend|need|want|available|have|give|find|search|fetch|bring)\b.*\b(products?|items?|options?|burgers?|pizzas?|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|burgers?|pizzas?|snacks?|chocolates?|biscuits?|cookies?|cakes?|(?:candy|candies)|bakery|bread|breads?|loaves|loaf|toast|breakfast|cereals?|oats?|granola|tea\s*bags?|tea|dairy|dairy\s+alternatives?|milk\s+alternatives?|household|cleaning|cleaner|bathroom|kitchen|hand\s*washes?|handwashes?|soap|antiseptic|oils?|sweeteners?|condiments?|mayou?n+ai?se|mayonese|mayounese|beef|meat|chicken|sauces?|spices?|spicy|masala|seasonings?|bread)\b/iu', $lower) === 1
            || preg_match('/\b(products?|items?|options?|burgers?|pizzas?|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|burgers?|pizzas?|snacks?|chocolates?|biscuits?|cookies?|cakes?|(?:candy|candies)|bakery|bread|breads?|breakfast|cereals?|dairy\s+products?|dairy\s+alternatives?|milk\s+alternatives?|household\s+products?|cleaning\s+products?|hand\s*washes?|handwashes?|soaps?|oils?|sweeteners?|condiments?|beef\s+products?|chicken\s+products?)\b/iu', $lower) === 1
            || preg_match('/\b(?:looking\s+for|look\s+for|searching\s+for|list\s+down)\b.*\b(products?|items?|options?|burgers?|pizzas?|drinks?|juices?|beverages?|snacks?|chips|crisps|chocolates?|biscuits?|cookies?|cakes?|cand(?:y|ies)|sweets?|pasta|noodles?|spaghetti|sauces?|spices?|spicy|masala|seasonings?)\b/iu', $lower) === 1
            || preg_match('/\b(products?|items?|options?)\b.*\b(contain|contains|containing|with|without|do\s+not\s+contain|does\s+not\s+contain|free\s+from|no\s+)\b/iu', $lower) === 1;
    }

    /**
     * Boolean helper that checks whether the current request or product matches "is plural catalog text".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function isPluralCatalogText(string $message): bool
    {
        return preg_match('/\b(products?|items?|options?|burgers?|pizzas?|drinks?|fizzy\s+drinks?|soft\s+drinks?|soda|pop|juices?|beverages?|burgers?|pizzas?|snacks?|chocolates?|biscuits?|cookies?|cakes?|(?:candy|candies)|bakery|bread|breads?|breakfast|cereals?|milk\s+alternatives?|household\s+products?|cleaning\s+products?|hand\s*washes?|handwashes?|soaps?|oils?|sweeteners?|condiments?|beef\s+products?|chicken\s+products?|dairy\s+products?|dairy\s+alternatives?)\b/iu', mb_strtolower($message)) === 1;
    }

    /**
     * Finds category/type intent, including singular/plural forms like electronic/electronics and snack/snacks.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractCategoryFromSegment(string $message): ?string
    {
        $lower = mb_strtolower($this->normalizeIntentText($message));

        $palmOilIngredientQuery = preg_match('/\b(?:with|contain|contains|containing|has|have|having|include|includes|including)\b[^.?!;]{0,100}\bpalm\s+oil\b|\bpalm\s+oil\b[^.?!;]{0,100}\b(?:inside|in\s+it|as\s+ingredients?|ingredients?)\b/iu', $lower) === 1;
        $spiceIngredientQuery = preg_match('/\b(?:with|contain|contains|containing|has|have|having|include|includes|including)\b[^.?!;]{0,140}\b(?:spicy|spices?|masala|seasonings?|curry|chilli?|chili|paprika|pepper)\b|\b(?:spicy|spices?|masala|seasonings?|curry|chilli?|chili|paprika|pepper)\b[^.?!;]{0,140}\b(?:inside|inside\s+ingredients?|in\s+ingredients?|as\s+ingredients?|ingredients?)\b/iu', $lower) === 1;

        $patterns = [
            'beef'       => '/\bbeef\b/iu',
            'chicken'    => '/\b(chicken|poultry)\b/iu',
            'dairy_alternatives' => '/\b(dairy\s+alternatives?|plant\s*based\s+milk|almond\s+milk|soy\s+milk|oat\s+milk|non\s*dairy)\b/iu',
            'dairy'      => '/\b(dairy\s+products?|dairy\s+items?|cheese|cheddar|butter|yogurts?|yoghurts?|cream)\b/iu',
            'household'  => '/\b(household|cleaning|cleaner|bathroom|kitchen|washroom|hand\s*washes?|handwashes?|soap|soaps|antiseptic|disinfectant|detto?l|carex)\b/iu',
            'oils'       => '/\b(oils?|cooking\s+oil|edible\s+oil|palm\s+oil|canola\s+oil|sunflower\s+oil|olive\s+oil)\b/iu',
            'sweeteners' => '/\b(sweeteners?|sugar\s+substitutes?|honey|syrup|stevia)\b/iu',
            'pasta'      => '/\b(pasta|pastas|noodles?|instant\s+noodles?|spaghetti|macaroni)\b/iu',
            'chocolates' => '/\b(chocolates?|cocoa|confectionery|ferrero|rocher|kinder|dairy\s*milk|dairymilk)\b/iu',
            'burgers'    => '/\b(burgers?|burger\s+deals?|burger\s+options?)\b/iu',
            'pizza'      => '/\b(pizzas?)\b/iu',
            'snacks'     => '/\b(snacks?|chips|crisps)\b/iu',
            'biscuits'   => '/\b(biscuits?|cookies?|crackers?|wafers?)\b/iu',
            'juices'     => '/\bjuices?\b|\b(?:apple|orange|mango|fruit)\s+juice\b/iu',
            'drinks'     => '/\b(drinks?|beverages?|soda|soft\s+drink|energy\s+drink)\b/iu',
            'bakery'     => '/\b(bakery|baked|pastry|pastries)\b/iu',
            'cakes'      => '/\b(cakes?|cupcakes?)\b/iu',
            'candies'    => '/\b(candies|candy|sweets?|gumm(?:y|ies))\b/iu',
            'sauces'     => '/\b(sauces?|ketchup|condiments?|mayou?n+ai?se|mayonese|mayounese|mayo)\b/iu',
            'spices'     => '/\b(spices?|masala|seasonings?|mixes?)\b/iu',
            'bread'      => '/\b(breads?|loaves|loaf|toast|breadcrumbs?)\b/iu',
            'breakfast'  => '/\b(breakfast|cereals?|oats?|granola)\b/iu',
            'tea'        => '/\b(tea\s*bags?|green\s+tea|black\s+tea|tea)\b/iu',
            'pantry'     => '/\b(pantry|stock\s+cubes?|yeast\s+extract|breadcrumbs?)\b/iu',
        ];

        foreach ($patterns as $category => $pattern) {
            if ($category === 'oils' && $palmOilIngredientQuery && ! preg_match('/\b(?:show|list|find|need|want|give)\b[^.?!;]{0,60}\b(?:oils|cooking\s+oil|edible\s+oil|olive\s+oil|sunflower\s+oil|canola\s+oil|coconut\s+oil)\b/iu', $lower)) {
                continue;
            }

            if ($category === 'spices' && $spiceIngredientQuery) {
                continue;
            }

            if (preg_match($pattern, $lower) === 1) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Finds one or multiple country/origin filters from the user message.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractOriginsFromSegment(string $message): array
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return [];
        }

        $found = [];

        // Generic origin extraction: do not require a hardcoded country list.
        // Examples: "products from Montenegro", "from Czech-republic",
        // "made in Democratic-republic-of-the-congo". This keeps future DB origins working.
        foreach ($this->extractLooseOriginPhrasesFromSegment($lower) as $candidate) {
            $normalized = $this->normalizeOriginCandidateForSegment($candidate);
            if ($normalized !== '') {
                $found[] = $normalized;
            }
        }

        // Keep common aliases/demonyms as a convenience layer only.
        $map = [
            'australia'    => ['australia', 'australian', 'austrailian', 'austrelian', 'austrelia', 'autrelia', 'asutrailia', 'austrailia'],
            'new zealand'  => ['new zealand', 'newzealand', 'new-zealand', 'nz', 'n.z.', 'kiwi'],
            'pakistan'     => ['pakistan', 'pakistani', 'pk'],
            'uk'           => ['uk', 'u.k.', 'united kingdom', 'united-kingdom', 'britain', 'great britain', 'england', 'british'],
            'usa'          => ['usa', 'us', 'u.s.', 'united states', 'united-states', 'united states of america', 'america', 'american'],
            'italy'        => ['italy', 'italian', 'itley', 'itely', 'italia'],
            'turkey'       => ['turkey', 'turkish'],
            'france'       => ['france', 'french'],
            'germany'      => ['germany', 'german'],
            'canada'       => ['canada', 'canadian'],
            'uae'          => ['uae', 'u.a.e.', 'united arab emirates', 'united-arab-emirates', 'emirates', 'dubai'],
            'saudi arabia' => ['saudi arabia', 'saudi-arabia', 'saudi', 'ksa'],
            'india'        => ['india', 'indian'],
            'malaysia'     => ['malaysia', 'malaysian'],
            'china'        => ['china', 'chinese'],
            'japan'        => ['japan', 'japanese'],
            'thailand'     => ['thailand', 'thai'],
            'indonesia'    => ['indonesia', 'indonesian'],
            'spain'        => ['spain', 'spanish'],
            'netherlands'  => ['netherlands', 'netherland', 'dutch', 'holland'],
            'switzerland'  => ['switzerland', 'swiss'],
            'belgium'      => ['belgium', 'belgian'],
            'morocco'      => ['morocco', 'moroccan'],
        ];

        $searchable = str_replace(['_', '-'], ' ', $lower);
        $searchable = trim((string) preg_replace('/\s+/u', ' ', $searchable));
        foreach ($map as $origin => $aliases) {
            foreach ($aliases as $alias) {
                $aliasSearch = str_replace(['_', '-'], ' ', mb_strtolower((string) $alias));
                $aliasSearch = trim((string) preg_replace('/\s+/u', ' ', $aliasSearch));
                if ($aliasSearch !== '' && preg_match('/(?<![\pL\pN])' . preg_quote($aliasSearch, '/') . '(?![\pL\pN])/iu', $searchable) === 1) {
                    $found[] = $origin;
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($found)));
    }

    protected function extractLooseOriginPhrasesFromSegment(string $message): array
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));
        if ($lower === '') {
            return [];
        }

        $phrases = [];
        $patterns = [
            '/\bfrom\s+([\pL\pN][\pL\pN\s._\-\'’]{1,100}?)(?=\s*(?:$|[,.?!;]|\b(?:only|with|without|that|which|where|and\s+(?:show|list|find|check|tell|give|also|from)|but|like|for\s+(?:halal|haram|ingredients?|barcode|alcohol|gelatin|gelatine))\b))/iu',
            '/\b(?:made\s+in|origin(?:\s+is|\s+from)?|country(?:\s+is|\s+from)?)\s+([\pL\pN][\pL\pN\s._\-\'’]{1,100}?)(?=\s*(?:$|[,.?!;]|\b(?:only|with|without|that|which|where|and\s+(?:show|list|find|check|tell|give|also|from)|but|like|for\s+(?:halal|haram|ingredients?|barcode|alcohol|gelatin|gelatine))\b))/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $lower, $matches)) {
                foreach ($matches[1] ?? [] as $match) {
                    $candidate = $this->cleanLooseOriginPhraseFromSegment((string) $match);
                    if ($candidate !== '') {
                        $phrases[] = $candidate;
                    }
                }
            }
        }

        // Hyphenated origin shorthand: "Czech-republic products" or just "Czech-republic".
        if (preg_match('/^([\pL\pN]+(?:[-_][\pL\pN]+)+)\s+(?:products?|items?|options?)$/iu', $lower, $m) === 1) {
            $phrases[] = $this->cleanLooseOriginPhraseFromSegment((string) $m[1]);
        } elseif (preg_match('/^[\pL\pN]+(?:[-_][\pL\pN]+)+$/iu', $lower) === 1) {
            $phrases[] = $this->cleanLooseOriginPhraseFromSegment($lower);
        }

        return array_values(array_unique(array_filter($phrases)));
    }

    protected function cleanLooseOriginPhraseFromSegment(string $value): string
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($value))));
        $value = preg_replace('/\b(?:only|products?|items?|foods?|options?|available|origin|country|made|show|list|find|search|give|me|some|any|all|the)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value, " \t\n\r\0\x0B,.;:!?؟");
    }

    protected function normalizeOriginCandidateForSegment(string $value): string
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($value))));
        if ($value === '') {
            return '';
        }

        $space = str_replace(['_', '-'], ' ', $value);
        $space = trim((string) preg_replace('/\s+/u', ' ', $space));

        return match ($space) {
            'america', 'american', 'united states', 'united states of america', 'us', 'u s', 'u.s.' => 'usa',
            'britain', 'british', 'england', 'great britain', 'united kingdom', 'u k', 'u.k.' => 'uk',
            'emirates', 'dubai', 'abu dhabi', 'united arab emirates', 'u a e', 'u.a.e.' => 'uae',
            'saudi', 'ksa' => 'saudi arabia',
            'swiss' => 'switzerland',
            'belgian' => 'belgium',
            'italian', 'itley', 'itely', 'italia' => 'italy',
            'turkish' => 'turkey',
            'french' => 'france',
            'german' => 'germany',
            'canadian' => 'canada',
            'spanish' => 'spain',
            'dutch', 'holland' => 'netherlands',
            'malaysian' => 'malaysia',
            'australian', 'austrailian', 'austrelian', 'austrelia', 'autrelia', 'asutrailia', 'austrailia' => 'australia',
            'indonesian' => 'indonesia',
            'thai' => 'thailand',
            'indian' => 'india',
            'chinese' => 'china',
            'japanese' => 'japan',
            'pakistani' => 'pakistan',
            'moroccan' => 'morocco',
            default => $space,
        };
    }

    protected function textCompactForOriginCompare(string $value): string
    {
        return preg_replace('/[^\pL\pN]+/u', '', mb_strtolower(trim((string) $value))) ?? '';
    }

    protected function brandHintMatchesAnyOrigin(?string $brandHint, array $origins): bool
    {
        $brandCompact = $this->textCompactForOriginCompare((string) $brandHint);
        if ($brandCompact === '' || empty($origins)) {
            return false;
        }

        foreach ($origins as $origin) {
            $originCompact = $this->textCompactForOriginCompare((string) $origin);
            if ($originCompact !== '' && ($brandCompact === $originCompact || str_contains($originCompact, $brandCompact) || str_contains($brandCompact, $originCompact))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts "brand hint from segment" from user text, history, image context, or normalized arguments.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function extractBrandHintFromSegment(string $message): ?string
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));

        if ($lower === '') {
            return null;
        }

        // DB-aware, generic brand/list extraction.
        // Goal: detect the brand entity in natural long wording without hardcoding
        // every brand. Examples:
        // - "Woolworth brand products"
        // - "I am a fan of Woolworth brand products show me all its products"
        // - "show me products of cocol" / "show me products ofcocol"
        // - "all Nestle products" / "products by Haribo"
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
                $brand = $this->cleanupBrandHintCandidate((string) ($matches[1] ?? ''));
                if ($brand !== null) {
                    return $brand;
                }
            }
        }

        // Known DB/product-line aliases are optional helpers; generic extraction above
        // handles future DB brands without code changes when the wording is clean.
        // These aliases are a safety net for long context like:
        // "I am a huge fan of Woolworth brand products show me its all products".
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
            'dettol' => '/(?<![a-z0-9])detto?l(?![a-z0-9])/iu',
            'carex' => '/(?<![a-z0-9])carex(?![a-z0-9])/iu',
            'sprite' => '/(?<![a-z0-9])sprit(?:e)?(?![a-z0-9])/iu',
            'dairy milk' => '/(?<![a-z0-9])(?:dairy\s*milk|dairymilk)(?![a-z0-9])/iu',
            'kinder' => '/(?<![a-z0-9])kinder(?![a-z0-9])/iu',
            'peanut butter' => '/(?<![a-z0-9])pe?nut\s+butter(?![a-z0-9])/iu',
        ];

        foreach ($brandMap as $brand => $pattern) {
            if (preg_match($pattern, $lower) === 1 && $this->looksLikeBrandCatalogRequest($lower)) {
                return $brand;
            }
        }

        return null;
    }

    protected function looksLikeBrandCatalogRequest(string $message): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($message))));

        if ($lower === '') {
            return false;
        }

        return preg_match('/\b(?:brand\s+(?:products?|items?)|(?:products?|items?)\s*(?:of|by|from)|(?:products?|items?)\s+of[a-z0-9]|all\s+[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)|show\s+(?:me\s+)?(?:all\s+)?[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)|(?:fan\s+of|huge\s+fan\s+of|love|like|prefer|interested\s+in|looking\s+for)\s+[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)|[a-z0-9][a-z0-9\s&\-\'’]{1,60}\s+(?:brand\s+)?(?:products?|items?)\s+(?:are\s+|is\s+)?(?:halal|haram|mushbooh|unknown|safe|available|show|list|give|find|search))\b/iu', $lower) === 1;
    }

    protected function cleanupBrandHintCandidate(string $candidate): ?string
    {
        $candidate = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->normalizeIntentText($candidate))));

        // Keep the actual brand token from long human context.
        $candidate = preg_replace('/^.*\b(?:fan\s+of|huge\s+fan\s+of|love|like|prefer|interested\s+in|looking\s+for)\s+/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:so\s+that|because|for\s+my|to\s+add|add\s+it|add\s+them|grocery\s+list|groccry\s+list).*$/iu', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:its|their|all\s+the|all|some|available|brand|brands|product|products|items|item|show|list|give|suggest|recommend|find|search|fetch|bring|me|of|by|from|the|a|an|is|are|do|does|can|could|would|halal|haram|mushbooh|unknown|breakfast|cereal|cereals)\b/iu', ' ', $candidate) ?? $candidate;
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));
        $candidate = trim($candidate, " \t\n\r\0\x0B,.;:!?&");

        if ($candidate === '' || mb_strlen($candidate) < 2) {
            return null;
        }

        // Do not treat categories, statuses, locations, or context phrases as brands.
        $blocked = [
            'you', 'your', 'yours', 'me', 'my', 'mine', 'i', 'we', 'our', 'ours',
            'can you', 'could you', 'would you', 'please', 'pls', 'some', 'any', 'all',
            'need', 'want', 'wanted', 'looking', 'looking for', 'i need', 'i want',
            'halal', 'haram', 'mushbooh', 'unknown', 'safe', 'muslim friendly',
            'grocery', 'groccry', 'groceries', 'shopping', 'store', 'supermarket', 'food',
            'drink', 'drinks', 'beverage', 'beverages', 'snack', 'snacks', 'chips', 'crisps',
            'biscuits', 'cookies', 'cakes', 'chocolates', 'chocolate', 'candy', 'candies', 'sweets',
            'pasta', 'noodles', 'spaghetti', 'sauces', 'spices', 'pantry', 'household',
            'breakfast', 'cereal', 'cereals', 'bread', 'milk alternatives', 'dairy alternatives',
            'usa', 'us', 'uk', 'pakistan', 'australia', 'italy', 'india', 'spain', 'france',
        ];

        return in_array($candidate, $blocked, true) ? null : $candidate;
    }

    /**
     * Boolean helper that checks whether the current request or product matches "should run search recovery".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function shouldRunSearchRecovery(string $toolName, array $lookup, array $arguments): bool
    {
        if ($toolName !== 'search_products') {
            return false;
        }

        $products = is_array($lookup['products'] ?? null) ? $lookup['products'] : [];
        if (!empty($products)) {
            return false;
        }

        return !empty($arguments['ingredients_include'])
            || !empty($arguments['ingredients_exclude'])
            || !empty($arguments['origin'])
            || !empty($arguments['category'])
            || !empty($arguments['status'])
            || !empty($arguments['status_include'])
            || !empty($arguments['status_exclude'])
            || trim((string) ($arguments['query'] ?? '')) !== '';
    }

    /**
     * Runs controlled fallback searches when the first database search returns no useful match.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function attemptSearchRecovery(
        string $message,
        array $arguments,
        array $originalLookup,
        ?string $preferredOrigin = null,
        ?array $imageContext = null
    ): array {
        $preferredOrigin = $this->normalizePreferredOriginLock($preferredOrigin);

        $base = [
            'query'               => trim((string) ($arguments['query'] ?? '')),
            'brand'               => $this->nullableString($arguments['brand'] ?? null),
            'category'            => $this->nullableString($arguments['category'] ?? null),
            'origin'              => $preferredOrigin ?? $this->nullableString($arguments['origin'] ?? null),
            'ingredients_include' => $this->uniqueStrings($arguments['ingredients_include'] ?? []),
            'ingredients_exclude' => $this->uniqueStrings($arguments['ingredients_exclude'] ?? []),
            'match_mode'          => $this->nullableString($arguments['match_mode'] ?? 'all') ?? 'all',
            // FIX: support both old 'status' string and new 'status_include'/'status_exclude' arrays
            'status'              => $this->nullableString($arguments['status'] ?? null),
            'status_include'      => $this->uniqueStrings($arguments['status_include'] ?? []),
            'status_exclude'      => $this->uniqueStrings($arguments['status_exclude'] ?? []),
            'limit'               => max(1, min(30, (int) ($arguments['limit'] ?? 12))),
            'question_focus'      => $this->nullableString($arguments['question_focus'] ?? null),
            'image_context'       => $imageContext,
        ];

        $explicitCategory = $this->extractCategoryFromSegment($message);
        if (($base['category'] === null || $base['category'] === '') && $explicitCategory !== null) {
            $base['category'] = $explicitCategory;
        }

        $explicitOrigins = $this->extractOriginsFromSegment($message);
        if ($preferredOrigin === null && ($base['origin'] === null || $base['origin'] === '') && ! empty($explicitOrigins)) {
            $base['origin'] = $explicitOrigins[0];
        }

        $mustPreserveCategory = $explicitCategory !== null && ! empty($base['category']);
        $mustPreserveOrigin = $preferredOrigin !== null || ! empty($explicitOrigins) || $this->messageContainsExplicitOrigin($message);
        $mustPreserveIngredientFilters = ! empty($base['ingredients_include']) || ! empty($base['ingredients_exclude']);
        $mustPreserveStatusFilters = ! empty($base['status']) || ! empty($base['status_include']) || ! empty($base['status_exclude']);

        $attempts = [];

        // Recovery may remove noisy natural-language query text, but it must not
        // relax explicit ingredient filters. "snacks with gelatin" should return
        // no matches when no snack contains gelatin; it must never degrade to all snacks.
        if (! $mustPreserveIngredientFilters || $base['match_mode'] === 'any') {
            $attempts[] = [
                'name' => 'same_filters_no_noisy_query',
                'args' => array_merge($base, [
                    'query' => '',
                    'match_mode' => $base['match_mode'],
                ]),
            ];
        }

        if ($base['query'] !== '') {
            $attempts[] = [
                'name' => 'drop_noisy_query_keep_filters',
                'args' => array_merge($base, ['query' => '']),
            ];
        }

        if (! $mustPreserveIngredientFilters && !empty($base['ingredients_exclude'])) {
            $attempts[] = [
                'name' => 'keep_include_drop_exclude',
                'args' => array_merge($base, [
                    'query' => '',
                    'ingredients_exclude' => [],
                    'match_mode' => !empty($base['ingredients_include']) ? 'any' : $base['match_mode'],
                ]),
            ];
        }

        if (! $mustPreserveIngredientFilters && ! $mustPreserveStatusFilters && !empty($base['origin']) && !empty($base['category'])) {
            $attempts[] = [
                'name' => 'origin_category_only',
                'args' => array_merge($base, [
                    'query' => '',
                    'ingredients_include' => [],
                    'ingredients_exclude' => [],
                    'status'         => null,
                    'status_include' => [],
                    'status_exclude' => [],
                    'match_mode' => 'all',
                ]),
            ];
        }

        // Do not relax away explicit category/origin filters.
        // Example: "snacks from Pakistan" must not degrade to "all Pakistan products".
        if (! $mustPreserveIngredientFilters && ! $mustPreserveStatusFilters && !empty($base['origin']) && ! $mustPreserveCategory) {
            $attempts[] = [
                'name' => 'origin_only',
                'args' => array_merge($base, [
                    'query' => '',
                    'brand' => null,
                    'category' => null,
                    'ingredients_include' => [],
                    'ingredients_exclude' => [],
                    'status'         => null,
                    'status_include' => [],
                    'status_exclude' => [],
                    'match_mode' => 'all',
                ]),
            ];
        }

        if (! $mustPreserveIngredientFilters && ! $mustPreserveStatusFilters && !empty($base['category']) && ! $mustPreserveOrigin) {
            $attempts[] = [
                'name' => 'category_only',
                'args' => array_merge($base, [
                    'query' => '',
                    'brand' => null,
                    'origin' => null,
                    'ingredients_include' => [],
                    'ingredients_exclude' => [],
                    'status'         => null,
                    'status_include' => [],
                    'status_exclude' => [],
                    'match_mode' => 'all',
                ]),
            ];
        }

        $attempts = $this->deduplicateSearchAttempts($attempts);

        foreach ($attempts as $attempt) {
            try {
                $result = $this->productLookup->executeTool('search_products', $attempt['args']);
                $result = $this->enforcePreferredOriginOnLookup($result, $preferredOrigin, $message, 'search_products', $attempt['args']);
                $products = is_array($result['products'] ?? null) ? $result['products'] : [];

                if (!empty($products)) {
                    $result['meta'] = array_merge(
                        is_array($result['meta'] ?? null) ? $result['meta'] : [],
                        [
                            'tool' => 'search_products',
                            'recovery_mode' => 'filter_relaxation',
                            'recovery_attempt_name' => $attempt['name'],
                            'recovery_attempt_arguments' => $attempt['args'],
                            'original_not_found_message' => $originalLookup['message'] ?? null,
                        ]
                    );

                    if (($result['message'] ?? '') === '' || str_contains(strtolower((string) ($result['message'] ?? '')), 'no products matched')) {
                        $result['message'] = 'I found related products after relaxing the search filters.';
                    }

                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning('Search recovery attempt failed', [
                    'attempt' => $attempt['name'],
                    'arguments' => $attempt['args'],
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $originalLookup;
    }

    /**
     * Removes duplicate entries for "search attempts".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function deduplicateSearchAttempts(array $attempts): array
    {
        $seen = [];
        $unique = [];

        foreach ($attempts as $attempt) {
            $key = md5(json_encode($attempt['args'] ?? []));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $attempt;
        }

        return $unique;
    }

    /**
     * Helper method for "unique strings".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function uniqueStrings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $item = $this->nullableString($item);
            if ($item !== null) {
                $items[] = mb_strtolower($item);
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * Helper method for "nullable string".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * Boolean helper that checks whether the current request or product matches "should run image recovery".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function shouldRunImageRecovery(array $lookup, ?array $imageContext): bool
    {
        if (empty($imageContext) || !is_array($imageContext)) {
            return false;
        }

        $status = (string) ($lookup['status'] ?? 'not_found');
        $products = is_array($lookup['products'] ?? null) ? $lookup['products'] : [];

        // Never hallucinate product details when the database did not return a match.
        if ($status !== 'found' || empty($products)) {
            return true;
        }

        $top = is_array($products[0] ?? null) ? $products[0] : [];
        return $this->scoreCandidateAgainstImageContext($top, $imageContext) < 45;
    }

    /**
     * Uses barcode, brand, product name, and visible text extracted from an image to recover matching products.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function attemptImageRecovery(
        string $message,
        array $intent,
        array $imageContext,
        ?string $preferredOrigin = null
    ): array {
        $attempts = [];

        $barcode = trim((string) ($imageContext['barcode'] ?? ''));
        $brand = trim((string) ($imageContext['brand'] ?? ''));
        $productName = trim((string) ($imageContext['product_name'] ?? ''));
        $variant = trim((string) ($imageContext['variant'] ?? ''));
        $category = trim((string) ($imageContext['category'] ?? ''));
        $visibleText = trim((string) ($imageContext['visible_text'] ?? ''));

        if ($barcode !== '') {
            $attempts[] = [
                'tool' => 'find_product_by_barcode',
                'arguments' => [
                    'barcode' => $barcode,
                    'image_context' => $imageContext,
                ],
                'mode' => 'direct',
            ];
        }

        foreach ($this->buildCandidatePhrases($imageContext) as $phrase) {
            $attempts[] = [
                'tool' => 'find_product_by_name',
                'arguments' => [
                    'name' => $phrase,
                    'image_context' => $imageContext,
                ],
                'mode' => 'direct',
            ];

            $attempts[] = [
                'tool' => 'search_products',
                'arguments' => array_filter([
                    'query' => $phrase,
                    'brand' => $brand !== '' ? $brand : null,
                    'category' => $category !== '' ? $category : null,
                    'origin' => $preferredOrigin,
                    'limit' => 10,
                    'image_context' => $imageContext,
                ], fn ($value) => $value !== null && $value !== ''),
                'mode' => 'scored_search',
            ];
        }

        if ($visibleText !== '') {
            $attempts[] = [
                'tool' => 'search_products',
                'arguments' => array_filter([
                    'query' => $visibleText,
                    'brand' => $brand !== '' ? $brand : null,
                    'category' => $category !== '' ? $category : null,
                    'origin' => $preferredOrigin,
                    'limit' => 10,
                    'image_context' => $imageContext,
                ], fn ($value) => $value !== null && $value !== ''),
                'mode' => 'scored_search',
            ];
        }

        if ($brand !== '' || $category !== '') {
            $attempts[] = [
                'tool' => 'search_products',
                'arguments' => array_filter([
                    'query' => trim(implode(' ', array_filter([$brand, $productName, $variant, $visibleText, $message]))),
                    'brand' => $brand !== '' ? $brand : null,
                    'category' => $category !== '' ? $category : null,
                    'origin' => $preferredOrigin,
                    'limit' => 12,
                    'image_context' => $imageContext,
                ], fn ($value) => $value !== null && $value !== ''),
                'mode' => 'scored_search',
            ];
        }

        $attempts = $this->deduplicateAttempts($attempts);

        foreach ($attempts as $attempt) {
            try {
                $result = $this->productLookup->executeTool($attempt['tool'], $attempt['arguments']);
                $result = $this->rankRecoveryResult($result, $imageContext, $attempt);
                $result = $this->enforcePreferredOriginOnLookup($result, $preferredOrigin, $message, (string) ($attempt['tool'] ?? ''), $attempt['arguments'] ?? []);

                if (($result['status'] ?? 'not_found') === 'found' && !empty($result['products'])) {
                    $top = $result['products'][0] ?? [];
                    $score = (int) ($top['_image_match_score'] ?? 0);

                    if ($score >= 45 || $attempt['tool'] === 'find_product_by_barcode') {
                        $result['meta'] = array_merge(
                            is_array($result['meta'] ?? null) ? $result['meta'] : [],
                            [
                                'tool' => $attempt['tool'],
                                'recovery_mode' => $attempt['mode'] ?? 'direct',
                                'recovery_attempt_arguments' => $attempt['arguments'],
                            ]
                        );

                        return $result;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Image recovery attempt failed', [
                    'tool' => $attempt['tool'],
                    'arguments' => $attempt['arguments'],
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'status' => 'not_found',
            'message' => 'Image recovery did not find a confident database match.',
            'products' => [],
            'meta' => [
                'tool' => 'image_recovery',
                'image_context' => $imageContext,
            ],
        ];
    }

    /**
     * Builds "candidate phrases" used by the next step or final response.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function buildCandidatePhrases(array $imageContext): array
    {
        $brand = $this->cleanImageToken((string) ($imageContext['brand'] ?? ''));
        $productName = $this->cleanImageToken((string) ($imageContext['product_name'] ?? ''));
        $variant = $this->cleanImageToken((string) ($imageContext['variant'] ?? ''));
        $visibleText = $this->cleanImageToken((string) ($imageContext['visible_text'] ?? ''));

        $phrases = [];

        if ($brand !== '' && $productName !== '') {
            $phrases[] = trim($brand . ' ' . $productName . ' ' . $variant);
            $phrases[] = trim($productName . ' ' . $brand . ' ' . $variant);
            $phrases[] = trim($brand . ' ' . $productName);
            $phrases[] = trim($productName . ' ' . $brand);
        }

        if ($productName !== '') {
            $phrases[] = trim($productName . ' ' . $variant);
            $phrases[] = $productName;
        }

        if ($brand !== '') {
            $phrases[] = $brand;
        }

        if ($visibleText !== '') {
            $phrases[] = $visibleText;
        }

        $explodedText = preg_split('/\s+/', $visibleText) ?: [];
        foreach ([$brand, $productName] as $needle) {
            if ($needle === '') {
                continue;
            }

            foreach ($explodedText as $chunk) {
                $chunk = $this->cleanImageToken($chunk);
                if ($chunk !== '' && mb_strlen($chunk) >= 3) {
                    $phrases[] = trim($needle . ' ' . $chunk);
                }
            }
        }

        $phrases = array_values(array_unique(array_filter(array_map(function ($phrase) {
            $phrase = preg_replace('/\s+/u', ' ', trim((string) $phrase)) ?? trim((string) $phrase);
            return $phrase;
        }, $phrases), fn ($phrase) => $phrase !== '')));

        usort($phrases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($phrases, 0, 10);
    }

    /**
     * Removes duplicate entries for "attempts".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function deduplicateAttempts(array $attempts): array
    {
        $seen = [];
        $unique = [];

        foreach ($attempts as $attempt) {
            $key = ($attempt['tool'] ?? '') . '|' . md5(json_encode($attempt['arguments'] ?? []));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $attempt;
        }

        return $unique;
    }

    /**
     * Scores or ranks data for "rank recovery result".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function rankRecoveryResult(array $result, array $imageContext, array $attempt): array
    {
        $products = is_array($result['products'] ?? null) ? $result['products'] : [];

        if (empty($products)) {
            return $result;
        }

        foreach ($products as $index => $product) {
            if (!is_array($product)) {
                continue;
            }

            $products[$index]['_image_match_score'] = $this->scoreCandidateAgainstImageContext($product, $imageContext);
        }

        usort($products, function (array $a, array $b) {
            return ((int) ($b['_image_match_score'] ?? 0)) <=> ((int) ($a['_image_match_score'] ?? 0));
        });

        $result['products'] = $products;
        $result['status'] = !empty($products) ? 'found' : ($result['status'] ?? 'not_found');
        $result['meta'] = array_merge(
            is_array($result['meta'] ?? null) ? $result['meta'] : [],
            [
                'image_recovery_scores' => array_map(fn ($product) => [
                    'name' => $product['name'] ?? null,
                    'barcode' => $product['barcode'] ?? null,
                    'score' => $product['_image_match_score'] ?? 0,
                ], array_slice($products, 0, 5)),
                'recovery_mode' => $attempt['mode'] ?? 'direct',
            ]
        );

        return $result;
    }

    /**
     * Scores or ranks data for "score candidate against image context".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function scoreCandidateAgainstImageContext(array $product, array $imageContext): int
    {
        $score = 0;

        $productName = $this->normalizeForCompare((string) ($product['name'] ?? ''));
        $productBrand = $this->normalizeForCompare((string) ($product['brand'] ?? ''));
        $imageName = $this->normalizeForCompare((string) ($imageContext['product_name'] ?? ''));
        $imageBrand = $this->normalizeForCompare((string) ($imageContext['brand'] ?? ''));
        $imageVisibleText = $this->normalizeForCompare((string) ($imageContext['visible_text'] ?? ''));
        $imageVariant = $this->normalizeForCompare((string) ($imageContext['variant'] ?? ''));
        $imageBarcode = preg_replace('/\D+/', '', (string) ($imageContext['barcode'] ?? '')) ?? '';
        $productBarcode = preg_replace('/\D+/', '', (string) ($product['barcode'] ?? '')) ?? '';

        if ($imageBarcode !== '' && $productBarcode !== '' && $imageBarcode === $productBarcode) {
            $score += 100;
        }

        if ($imageBrand !== '' && $productBrand !== '') {
            if ($imageBrand === $productBrand) {
                $score += 30;
            } elseif (str_contains($productName, $imageBrand)) {
                $score += 18;
            }
        }

        if ($imageName !== '' && $productName !== '') {
            if ($imageName === $productName) {
                $score += 35;
            } elseif (str_contains($productName, $imageName) || str_contains($imageName, $productName)) {
                $score += 28;
            } else {
                $score += $this->sharedTokenScore($imageName, $productName, 6);
            }

            // OCR/vision often detects the short front-label name while the DB keeps a
            // longer commercial name, e.g. image "Shin Ramyun" vs DB "Shin Ramyun Spicy",
            // or image "Panko Breadcrumbs" vs DB "Panko Classic Breadcrumbs 200g".
            // When all meaningful image-name tokens are present in the DB product name,
            // treat it as a strong product-name match even if brand is missing/mismatched.
            $imageNameTokens = $this->tokenizeForCompare($imageName);
            $productNameTokens = $this->tokenizeForCompare($productName);
            $sharedNameTokens = array_values(array_intersect($imageNameTokens, $productNameTokens));
            if (count($imageNameTokens) >= 2 && count($sharedNameTokens) === count($imageNameTokens)) {
                $score += 35;
            } elseif (count($sharedNameTokens) >= 2) {
                $score += 18;
            }
        }

        if ($imageVariant !== '' && str_contains($productName, $imageVariant)) {
            $score += 10;
        }

        if ($imageVisibleText !== '') {
            $score += $this->sharedTokenScore($imageVisibleText, trim($productName . ' ' . $productBrand), 4);
        }

        return min(100, $score);
    }

    /**
     * Helper method for "shared token score".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function sharedTokenScore(string $source, string $target, int $pointsPerToken): int
    {
        $sourceTokens = $this->tokenizeForCompare($source);
        $targetTokens = $this->tokenizeForCompare($target);

        if (empty($sourceTokens) || empty($targetTokens)) {
            return 0;
        }

        $shared = array_intersect($sourceTokens, $targetTokens);

        return count($shared) * $pointsPerToken;
    }

    /**
     * Helper method for "tokenize for compare".
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function tokenizeForCompare(string $value): array
    {
        $value = $this->normalizeForCompare($value);
        $parts = preg_split('/\s+/', $value) ?: [];

        return array_values(array_unique(array_filter($parts, fn ($part) => mb_strlen($part) >= 2)));
    }

    /**
     * Normalizes "for compare" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeForCompare(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['-', '_', '/', '|'], ' ', $value);
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value;
    }

    /**
     * Cleans "image token" before matching, resolving, or replying.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function cleanImageToken(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\b(product|brand|detected|name|barcode)\b[:\-]*/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return $value;
    }

    /**
     * Normalizes "image context" into a consistent internal format.
     *
     * Manager note: purpose/comment only; no logic changed here.
     */
    protected function normalizeImageContext(?array $imageContext): ?array
    {
        if (!$imageContext || !is_array($imageContext)) {
            return $imageContext;
        }

        $normalized = $imageContext;

        foreach (['barcode', 'product_name', 'brand', 'category', 'variant', 'packaging', 'visible_text', 'confidence', 'notes'] as $key) {
            if (array_key_exists($key, $normalized)) {
                $normalized[$key] = is_string($normalized[$key])
                    ? trim($normalized[$key])
                    : $normalized[$key];
            }
        }

        foreach (['product_name', 'brand', 'visible_text'] as $key) {
            $value = (string) ($normalized[$key] ?? '');
            $value = str_replace(['|', '•'], ' ', $value);
            $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
            $normalized[$key] = $value !== '' ? $value : null;
        }

        $barcode = preg_replace('/\D+/', '', (string) ($normalized['barcode'] ?? '')) ?? '';
        $normalized['barcode'] = $barcode !== '' ? $barcode : null;

        return $normalized;
    }
}
