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
        $base64 = base64_encode((string) file_get_contents($realPath));

        $prompt = $this->buildPrompt($userMessage);

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
                'maxOutputTokens' => 220,
                'responseMimeType' => 'application/json',
            ],
        ];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $httpResponse = Http::timeout((int) config('services.gemini.timeout', 45))
            ->acceptJson()
            ->post($url, $payload);

        Log::info('Gemini image resolver response', [
            'status' => $httpResponse->status(),
        ]);

        $response = $httpResponse->throw()->json();
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return $this->sanitizeExtractedData($this->decodeJsonFromText((string) $text));
    }

    protected function buildPrompt(?string $userMessage = null): string
    {
        $userHint = trim((string) $userMessage);

        return <<<TEXT
You are an image-to-structured-data extractor for a halal product assistant.

Primary priority:
1. Detect barcode first.
2. If barcode is confidently visible, return it.
3. Also extract product_name when a clear front label name is visible.
4. If product_name is unclear, try to extract the brand.
5. Prefer the consumer-facing product name from the front of the pack, not ingredients or category words.
6. Never guess a barcode, product name, or brand.

Return ONLY strict JSON with these keys:
{
  "barcode": string|null,
  "product_name": string|null,
  "brand": string|null,
  "confidence": "high"|"medium"|"low",
  "notes": string|null
}

Rules:
- barcode must preserve visible digits exactly as seen.
- barcode should contain digits only unless visible separators are clearly printed.
- if multiple barcodes are visible, return the clearest one.
- if no barcode is visible, barcode must be null.
- product_name should be the clearest packaging product name visible in the image. Avoid generic words like drink, snack, chocolate, biscuits, product, bottle, pack unless they are part of the actual printed name.
- brand should be the visible brand only.
- do not include markdown.
- do not include extra keys.
- do not explain outside JSON.

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
        $barcode = $this->nullableTrim($data['barcode'] ?? null);
        $productName = $this->nullableTrim($data['product_name'] ?? null);
        $brand = $this->nullableTrim($data['brand'] ?? null);
        $notes = $this->nullableTrim($data['notes'] ?? null);
        $confidence = strtolower((string) $this->nullableTrim($data['confidence'] ?? 'low'));

        if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
            $confidence = 'low';
        }

        if ($barcode !== null) {
            $barcode = preg_replace('/[^0-9\-\+\.\seE]/', '', $barcode);
            $barcode = trim((string) $barcode);
            $barcode = $barcode !== '' ? $barcode : null;
        }

        if ($productName !== null) {
            $productName = preg_replace('/\s+/u', ' ', $productName);
            $productName = trim((string) $productName);
            $productName = $productName !== '' ? $productName : null;
        }

        if ($brand !== null) {
            $brand = preg_replace('/\s+/u', ' ', $brand);
            $brand = trim((string) $brand);
            $brand = $brand !== '' ? $brand : null;
        }

        if ($notes !== null) {
            $notes = preg_replace('/\s+/u', ' ', $notes);
            $notes = trim((string) $notes);
            $notes = $notes !== '' ? $notes : null;
        }

        return [
            'barcode' => $barcode,
            'product_name' => $productName,
            'brand' => $brand,
            'confidence' => $confidence,
            'notes' => $notes,
        ];
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
