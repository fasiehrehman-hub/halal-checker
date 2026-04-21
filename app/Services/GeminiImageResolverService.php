<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class GeminiImageResolverService
{
    public function __construct(
        protected GeminiClient $geminiClient
    ) {
    }

    public function extractFromImage(UploadedFile $image, ?string $userMessage = null): array
    {
        if (!$image->isValid()) {
            throw new \RuntimeException('Uploaded image is invalid.');
        }

        $realPath = $image->getRealPath();
        if (!$realPath || !is_file($realPath)) {
            throw new \RuntimeException('Image file could not be read.');
        }

        $binary = file_get_contents($realPath);
        if ($binary === false) {
            throw new \RuntimeException('Failed to read image binary.');
        }

        $mimeType = $image->getMimeType() ?: 'image/jpeg';
        $base64 = base64_encode($binary);

        $attempts = [
            'barcode' => $this->buildBarcodePrompt($userMessage),
            'primary' => $this->buildPrimaryPrompt($userMessage),
            'ocr' => $this->buildOcrPrompt($userMessage),
        ];

        $decoded = [];

        foreach ($attempts as $label => $prompt) {
            $raw = $this->callGeminiJsonPrompt($mimeType, $base64, $prompt);
            $decoded[$label] = $this->decodeJsonFromText($raw);

            if ($label === 'barcode' && !empty($decoded[$label]['barcode'])) {
                break;
            }

            if ($label === 'primary' && $this->hasUsefulData($decoded[$label])) {
                break;
            }
        }

        $merged = $this->mergeAttempts(
            $decoded['barcode'] ?? [],
            $decoded['primary'] ?? [],
            $decoded['ocr'] ?? []
        );

        $sanitized = $this->sanitize($merged);

        Log::info('Gemini image extraction pipeline', [
            'user_message' => $userMessage,
            'decoded' => $decoded,
            'sanitized' => $sanitized,
        ]);

        return $sanitized;
    }

    protected function callGeminiJsonPrompt(string $mimeType, string $base64, string $prompt): string
    {
        $response = $this->geminiClient->generate([
            'contents' => [[
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
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'topP' => 0.1,
                'maxOutputTokens' => 700,
                'responseMimeType' => 'application/json',
            ],
        ], (string) config('services.gemini.vision_model', config('services.gemini.model', 'gemini-2.5-flash')));

        return (string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    protected function buildBarcodePrompt(?string $userMessage = null): string
    {
        $hint = trim((string) $userMessage);

        return <<<TEXT
You are doing BARCODE-FIRST extraction from a product image.

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
1. Your first job is to read barcode digits exactly.
2. Preserve all digits.
3. Also include any nearby readable brand or product words.
4. If unsure, put the best barcode guess and mention doubt in notes.

User hint:
{$hint}
TEXT;
    }

    protected function buildPrimaryPrompt(?string $userMessage = null): string
    {
        $hint = trim((string) $userMessage);

        return <<<TEXT
You extract product identity from a grocery image for a halal product bot.

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
1. Preserve barcode digits exactly if visible.
2. Prefer visible brand and consumer-facing product name.
3. Category should be short: chocolate, biscuits, ketchup, juice, chips, noodles, drink, sauce, snacks.
4. visible_text should be one short useful OCR line.
5. Do not invent hidden text.
6. If nothing is reliable, return null fields and confidence=low.

User hint:
{$hint}
TEXT;
    }

    protected function buildOcrPrompt(?string $userMessage = null): string
    {
        $hint = trim((string) $userMessage);

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
1. Read visible consumer-facing words.
2. visible_text must contain the strongest readable text.
3. Include readable product clues even if exact name is unclear.
4. Never return markdown.

User hint:
{$hint}
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

    protected function hasUsefulData(array $decoded): bool
    {
        return !empty($decoded['barcode'])
            || !empty($decoded['product_name'])
            || !empty($decoded['brand'])
            || !empty($decoded['category'])
            || !empty($decoded['visible_text']);
    }

    protected function mergeAttempts(array ...$attempts): array
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
            foreach (array_keys($merged) as $field) {
                $value = $attempt[$field] ?? null;

                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }

                $value = trim((string) $value);

                if ($value === '') {
                    continue;
                }

                if ($merged[$field] === null || strlen($value) > strlen((string) $merged[$field])) {
                    $merged[$field] = $value;
                }
            }
        }

        return $merged;
    }

    protected function sanitize(array $data): array
    {
        $barcode = $this->normalizeBarcode((string) ($data['barcode'] ?? ''));
        $confidence = strtolower(trim((string) ($data['confidence'] ?? 'low')));

        if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
            $confidence = 'low';
        }

        return [
            'barcode' => $barcode !== '' ? $barcode : null,
            'product_name' => $this->cleanText((string) ($data['product_name'] ?? '')) ?: null,
            'brand' => $this->cleanText((string) ($data['brand'] ?? '')) ?: null,
            'category' => $this->cleanText((string) ($data['category'] ?? '')) ?: null,
            'variant' => $this->cleanText((string) ($data['variant'] ?? '')) ?: null,
            'packaging' => $this->cleanText((string) ($data['packaging'] ?? '')) ?: null,
            'visible_text' => $this->cleanText((string) ($data['visible_text'] ?? '')) ?: null,
            'confidence' => $confidence,
            'notes' => $this->cleanText((string) ($data['notes'] ?? '')) ?: null,
        ];
    }

    protected function normalizeBarcode(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = strtr($value, [
            'O' => '0',
            'Q' => '0',
            'D' => '0',
            'I' => '1',
            'L' => '1',
            'S' => '5',
            'B' => '8',
            'Z' => '2',
            'G' => '6',
        ]);
        $value = preg_replace('/\D+/', '', $value) ?? '';
        return trim($value);
    }

    protected function cleanText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }
}