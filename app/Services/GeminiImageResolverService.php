<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiImageResolverService
{
    public function extractFromImage(UploadedFile $image, ?string $userMessage = null): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        $model = (string) config('services.gemini.vision_model', config('services.gemini.model', 'gemini-2.5-flash'));

        if ($apiKey === '') {
            throw new \RuntimeException('GEMINI_API_KEY is missing.');
        }

        if (!$image->isValid()) {
            throw new \RuntimeException('Uploaded image is invalid.');
        }

        $realPath = $image->getRealPath();
        if (!$realPath || !is_file($realPath)) {
            throw new \RuntimeException('Image file could not be read.');
        }

        $mimeType = $image->getMimeType() ?: 'image/jpeg';
        $binary = file_get_contents($realPath);

        if ($binary === false) {
            throw new \RuntimeException('Failed to read image binary.');
        }

        $base64 = base64_encode($binary);

        $attempts = [
            'primary' => $this->buildPrompt($userMessage),
            'barcode' => $this->buildBarcodeFocusedPrompt($userMessage),
            'ocr' => $this->buildOcrRescuePrompt($userMessage),
        ];

        $rawResponses = [];
        $decodedResponses = [];

        foreach ($attempts as $label => $prompt) {
            $rawText = $this->callGeminiJsonPrompt($apiKey, $model, $mimeType, $base64, $prompt);
            $decoded = $this->decodeJsonFromText($rawText);

            $rawResponses[$label] = $rawText;
            $decodedResponses[$label] = $decoded;

            if ($label === 'primary' && $this->hasMeaningfulDecodedExtraction($decoded)) {
                break;
            }

            if ($label === 'barcode' && !empty($decoded['barcode'])) {
                break;
            }
        }

        $merged = $this->mergeExtractionAttempts(
            $decodedResponses['primary'] ?? [],
            $decodedResponses['barcode'] ?? [],
            $decodedResponses['ocr'] ?? []
        );

        $sanitized = $this->sanitizeExtractedData($merged);

        Log::info('Gemini image extraction pipeline', [
            'user_message' => $userMessage,
            'raw_text' => $rawResponses,
            'decoded' => $decodedResponses,
            'merged' => $merged,
            'sanitized' => $sanitized,
        ]);

        return $sanitized;
    }

    protected function callGeminiJsonPrompt(
        string $apiKey,
        string $model,
        string $mimeType,
        string $base64,
        string $prompt
    ): string {
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0,
                'topP' => 0.1,
                'maxOutputTokens' => 700,
                'responseMimeType' => 'application/json',
            ],
        ];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $httpResponse = Http::timeout((int) config('services.gemini.timeout', 45))
            ->acceptJson()
            ->post($url, $payload);

        Log::info('Gemini image resolver HTTP status', [
            'status' => $httpResponse->status(),
            'model' => $model,
        ]);

        $response = $httpResponse->throw()->json();

        return (string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    protected function hasMeaningfulDecodedExtraction(array $decoded): bool
    {
        $barcode = $this->extractBarcodeCandidateFromMixedText((string) ($decoded['barcode'] ?? ''));
        $productName = trim((string) ($decoded['product_name'] ?? ''));
        $brand = trim((string) ($decoded['brand'] ?? ''));
        $category = trim((string) ($decoded['category'] ?? ''));
        $visibleText = trim((string) ($decoded['visible_text'] ?? ''));

        return $barcode !== null
            || $productName !== ''
            || $brand !== ''
            || $category !== ''
            || $visibleText !== '';
    }

    protected function mergeExtractionAttempts(array ...$attempts): array
    {
        $merged = [
            'barcode' => null,
            'product_name' => null,
            'brand' => null,
            'category' => null,
            'variant' => null,
            'packaging' => null,
            'visible_text' => null,
            'confidence' => 'low',
            'notes' => null,
        ];

        foreach ($attempts as $attempt) {
            if (!is_array($attempt)) {
                continue;
            }

            foreach (['barcode', 'product_name', 'brand', 'category', 'variant', 'packaging', 'visible_text', 'notes'] as $field) {
                $candidate = $attempt[$field] ?? null;
                if (!is_string($candidate) && !is_numeric($candidate)) {
                    continue;
                }

                $candidate = trim((string) $candidate);
                if ($candidate === '') {
                    continue;
                }

                if ($merged[$field] === null || mb_strlen($candidate) > mb_strlen((string) $merged[$field])) {
                    $merged[$field] = $candidate;
                }
            }

            $attemptConfidence = strtolower(trim((string) ($attempt['confidence'] ?? '')));
            if ($this->confidenceRank($attemptConfidence) > $this->confidenceRank((string) $merged['confidence'])) {
                $merged['confidence'] = $attemptConfidence;
            }
        }

        return $merged;
    }

    protected function confidenceRank(string $value): int
    {
        return match (strtolower(trim($value))) {
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }

    protected function buildBarcodeFocusedPrompt(?string $userMessage = null): string
    {
        $userHint = trim((string) $userMessage);

        return <<<TEXT
You are doing BARCODE-FIRST extraction from a product image.

Read the image carefully and return ONLY strict JSON with exactly these keys:
{
  "barcode": string|null,
  "product_name": string|null,
  "brand": string|null,
  "category": string|null,
  "variant": string|null,
  "packaging": string|null,
  "visible_text": string|null,
  "confidence": "high"|"medium"|"low",
  "notes": string|null
}

Rules:
1. Your first job is to read barcode digits exactly if any barcode is visible.
2. A 12, 13, or 14 digit barcode is very important. Preserve every digit exactly.
3. If the image mostly shows a barcode area, still return the barcode even if other fields are null.
4. Also copy any large nearby readable words into visible_text.
5. If you can read a brand or product word like Anchor, National, Heinz, Tomato, Butter, Ketchup, include it.
6. If unsure between two barcode readings, put the best reading in barcode and mention the uncertainty in notes.
7. Never output markdown or extra keys.

User hint:
{$userHint}
TEXT;
    }

    protected function buildOcrRescuePrompt(?string $userMessage = null): string
    {
        $userHint = trim((string) $userMessage);

        return <<<TEXT
You are doing OCR rescue on a grocery product image.

Return ONLY strict JSON with exactly these keys:
{
  "barcode": string|null,
  "product_name": string|null,
  "brand": string|null,
  "category": string|null,
  "variant": string|null,
  "packaging": string|null,
  "visible_text": string|null,
  "confidence": "high"|"medium"|"low",
  "notes": string|null
}

Rules:
1. Read all visible consumer-facing words.
2. visible_text should be a compact OCR line of the most useful readable words.
3. If exact product_name is unclear, visible_text must still contain the strongest readable text.
4. If you can read words like salted, butter, anchor, tomato, ketchup, classic, breadcrumbs, chocolate, include them.
5. Extract barcode digits if present anywhere.
6. Never return all-null unless absolutely nothing is readable.
7. Never output markdown or extra keys.

User hint:
{$userHint}
TEXT;
    }

    protected function buildPrompt(?string $userMessage = null): string
    {
        $userHint = trim((string) $userMessage);

        return <<<TEXT
You are an image-to-structured-product-data extractor for a halal product assistant.

The user may ask things like:
- tell me about this
- is it halal
- is it haram
- what are its ingredients
- what is this product

Your task is still the same: identify the product in the image as reliably as possible so the app can answer any follow-up product question from the database.

Return ONLY strict JSON with exactly these keys:
{
  "barcode": string|null,
  "product_name": string|null,
  "brand": string|null,
  "category": string|null,
  "variant": string|null,
  "packaging": string|null,
  "visible_text": string|null,
  "confidence": "high"|"medium"|"low",
  "notes": string|null
}

Priority order:
1. Barcode if visible anywhere on the package.
2. Brand text.
3. Product name text.
4. Variant or flavour text.
5. Useful category from visible packaging.
6. Compact OCR summary in visible_text.

Extraction rules:
1. Do not guess hidden text.
2. Read all visible text on the package, not only the most prominent word.
3. Prefer the front-of-pack consumer-facing name.
4. brand should be the visible brand only.
5. product_name should be the visible product name only.
6. category should be short and useful, for example: ketchup, tomato ketchup, sauce, chilli sauce, juice, soft drink, biscuits, noodles, chocolate, chips.
7. variant should capture flavour, subtype, or line name if clearly visible.
8. packaging should be bottle, can, pouch, box, jar, packet, sachet, tub, etc.
9. visible_text should contain the most useful readable words in one compact line, such as brand + product + variant.
10. Preserve barcode digits exactly if visible. If no barcode is visible, return null.
11. Never use generic words alone as product_name: product, item, grocery, drink, food, snack, bottle, pack, pouch, can.
12. If only category is visible, return category and visible_text, but keep product_name null.
13. If you can read words like tomato, ketchup, sauce, chili, original, classic, heinz, national, etc., include them where appropriate.
14. Confidence guide:
   - high: barcode or clear brand+name
   - medium: clear category and some readable text
   - low: product cannot be identified reliably
15. Never output markdown.
16. Never output extra keys.

User hint:
{$userHint}
TEXT;
    }

    protected function decodeJsonFromText(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    protected function sanitizeExtractedData(array $data): array
    {
        $visibleText = $this->sanitizeVisibleText($data['visible_text'] ?? null);
        $notes = $this->sanitizeNotes($data['notes'] ?? null);

        $barcode = $this->sanitizeBarcode(
            $data['barcode']
            ?? $this->extractBarcodeCandidateFromMixedText((string) ($data['visible_text'] ?? ''))
            ?? $this->extractBarcodeCandidateFromMixedText((string) ($data['notes'] ?? ''))
            ?? $this->extractBarcodeCandidateFromMixedText((string) ($data['product_name'] ?? ''))
        );
        $productName = $this->sanitizeProductName($data['product_name'] ?? null);
        $brand = $this->sanitizeBrand($data['brand'] ?? null);
        $category = $this->sanitizeCategory($data['category'] ?? null);
        $variant = $this->sanitizeShortField($data['variant'] ?? null);
        $packaging = $this->sanitizePackaging($data['packaging'] ?? null);
        $confidence = $this->sanitizeConfidence($data['confidence'] ?? null);

        if ($productName !== null && $this->isGenericLabel($productName)) {
            $productName = null;
        }

        if ($brand !== null && $this->isGenericLabel($brand)) {
            $brand = null;
        }

        if ($category !== null && $this->isBadCategory($category)) {
            $category = null;
        }

        if ($variant !== null && $this->isGenericLabel($variant)) {
            $variant = null;
        }

        if ($visibleText !== null && mb_strlen($visibleText) < 2) {
            $visibleText = null;
        }

        $filledCoreFields = count(array_filter([$barcode, $productName, $brand, $category]));
        if ($barcode !== null) {
            $confidence = 'high';
        } elseif ($filledCoreFields === 0) {
            $confidence = 'low';
        } elseif ($filledCoreFields === 1 && $confidence === 'high') {
            $confidence = 'medium';
        }

        return [
            'barcode' => $barcode,
            'product_name' => $productName,
            'brand' => $brand,
            'category' => $category,
            'variant' => $variant,
            'packaging' => $packaging,
            'visible_text' => $visibleText,
            'confidence' => $confidence,
            'notes' => $notes,
        ];
    }

    protected function sanitizeBarcode(mixed $value): ?string
    {
        $value = $this->nullableTrim($value);

        if ($value === null) {
            return null;
        }

        $value = $this->stripCommonFieldPrefix($value);
        $value = $this->extractBarcodeCandidateFromMixedText($value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = preg_replace('/[^A-Za-z0-9\-\+\._\/\s]/u', '', $value);
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $compact = preg_replace('/[^A-Za-z0-9]/', '', $value);
        if ($compact === null || $compact === '' || strlen($compact) < 4) {
            return null;
        }

        $generic = ['barcode', 'code', 'product', 'item', 'number'];
        if (in_array(strtolower($compact), $generic, true)) {
            return null;
        }

        return $value;
    }

    protected function sanitizeProductName(mixed $value): ?string
    {
        $value = $this->sanitizeCoreTextField($value);

        if ($value === null) {
            return null;
        }

        $lower = mb_strtolower($value);

        $badValues = [
            'front',
            'label',
            'product',
            'food',
            'item',
            'package',
            'packaging',
            'bottle',
            'can',
            'pouch',
            'packet',
            'drink',
            'snack',
        ];

        if (in_array($lower, $badValues, true)) {
            return null;
        }

        return $value;
    }

    protected function sanitizeBrand(mixed $value): ?string
    {
        return $this->sanitizeCoreTextField($value);
    }

    protected function sanitizeCategory(mixed $value): ?string
    {
        $value = $this->sanitizeCoreTextField($value);

        if ($value === null) {
            return null;
        }

        $value = mb_strtolower($value);

        $map = [
            'soft drinks' => 'soft drink',
            'beverages' => 'beverage',
            'chips' => 'potato chips',
            'crisps' => 'potato chips',
            'sauces' => 'sauce',
            'ketchups' => 'ketchup',
            'juices' => 'juice',
            'candies' => 'candy',
        ];

        if (isset($map[$value])) {
            $value = $map[$value];
        }

        return $value;
    }

    protected function sanitizePackaging(mixed $value): ?string
    {
        $value = $this->sanitizeCoreTextField($value);

        if ($value === null) {
            return null;
        }

        return mb_strtolower($value);
    }

    protected function sanitizeVisibleText(mixed $value): ?string
    {
        $value = $this->nullableTrim($value);

        if ($value === null) {
            return null;
        }

        $value = $this->stripCommonFieldPrefix($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 180) {
            $value = mb_substr($value, 0, 180);
        }

        return $value;
    }

    protected function sanitizeShortField(mixed $value): ?string
    {
        return $this->sanitizeCoreTextField($value);
    }

    protected function sanitizeNotes(mixed $value): ?string
    {
        $value = $this->nullableTrim($value);

        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function sanitizeCoreTextField(mixed $value): ?string
    {
        $value = $this->nullableTrim($value);

        if ($value === null) {
            return null;
        }

        $value = $this->stripCommonFieldPrefix($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $value;
    }

    protected function stripCommonFieldPrefix(string $value): string
    {
        $value = trim($value);

        $patterns = [
            '/^(barcode|bar code)\s*[:\-]\s*/iu',
            '/^(product name|product|name)\s*[:\-]\s*/iu',
            '/^(brand)\s*[:\-]\s*/iu',
            '/^(category)\s*[:\-]\s*/iu',
            '/^(variant|flavor|flavour)\s*[:\-]\s*/iu',
            '/^(packaging|pack)\s*[:\-]\s*/iu',
            '/^(visible text|text)\s*[:\-]\s*/iu',
        ];

        return (string) preg_replace($patterns, '', $value);
    }

    protected function sanitizeConfidence(mixed $value): string
    {
        $value = strtolower((string) $this->nullableTrim($value));

        return in_array($value, ['high', 'medium', 'low'], true) ? $value : 'low';
    }

    protected function isGenericLabel(string $value): bool
    {
        $value = mb_strtolower(trim($value));

        $generic = [
            'drink',
            'food',
            'snack',
            'product',
            'item',
            'grocery',
            'package',
            'packaging',
            'bottle',
            'pack',
            'pouch',
            'can',
            'box',
            'jar',
            'packet',
            'brand',
            'name',
            'category',
            'barcode',
            'label',
            'front label',
        ];

        return in_array($value, $generic, true);
    }

    protected function isBadCategory(string $value): bool
    {
        $value = mb_strtolower(trim($value));

        $bad = [
            'product',
            'food',
            'item',
            'grocery',
            'packaging',
            'label',
            'brand',
            'name',
        ];

        return in_array($value, $bad, true);
    }

    protected function extractBarcodeCandidateFromMixedText(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/\b\d{12,14}\b/', $value, $matches)) {
            return $matches[0];
        }

        $digitsOnly = preg_replace('/\D+/', '', $value);
        if ($digitsOnly !== null && strlen($digitsOnly) >= 12 && strlen($digitsOnly) <= 14) {
            return $digitsOnly;
        }

        return null;
    }

    protected function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
