<?php

namespace App\Services;

use App\Ai\Agents\GeminiBridgeAgent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files;
use RuntimeException;
use Throwable;

/**
 * GeminiClient
 *
 * Keeps the old Gemini REST-compatible generate(array $payload) API used by the app.
 * Text-only prompts use Laravel AI SDK. Image / inline_data prompts use Gemini REST
 * directly because this project already builds Gemini REST-style multimodal payloads
 * and image extraction must be reliable for barcode / product-label OCR.
 */
class GeminiClient
{
    public function generate(array $payload, ?string $model = null): array
    {
        $model = trim((string) ($model ?: config('services.gemini.model', env('GEMINI_MODEL', 'gemini-2.5-flash'))));
        $timeout = max(5, (int) config('services.gemini.timeout', env('GEMINI_TIMEOUT', 45)));
        $apiKey = trim((string) (config('services.gemini.api_key') ?: env('GEMINI_API_KEY')));

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is missing. Add it to .env before using Gemini.');
        }

        if ($model === '') {
            throw new RuntimeException('Gemini model is missing. Set GEMINI_MODEL in .env.');
        }

        $this->validatePayload($payload);

        // IMPORTANT FIX:
        // Your image resolver sends Gemini REST-style inline_data. Route those calls
        // directly to Gemini REST so image bytes always reach Gemini. This avoids
        // losing images during SDK attachment conversion and keeps text prompts on SDK.
        if ($this->payloadHasInlineData($payload)) {
            return $this->generateViaGeminiRest($payload, $model, $apiKey, $timeout);
        }

        return $this->generateViaLaravelAiSdk($payload, $model, $timeout);
    }

    private function validatePayload(array $payload): void
    {
        $contents = $payload['contents'] ?? null;

        if (! is_array($contents) || $contents === []) {
            throw new RuntimeException('Gemini payload must contain at least one contents entry.');
        }
    }

    private function payloadHasInlineData(array $payload): bool
    {
        foreach (($payload['contents'] ?? []) as $content) {
            if (! is_array($content)) {
                continue;
            }

            foreach (($content['parts'] ?? []) as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (isset($part['inline_data']) || isset($part['inlineData'])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function generateViaGeminiRest(array $payload, string $model, string $apiKey, int $timeout): array
    {
        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/' . $model;
        $url = 'https://generativelanguage.googleapis.com/v1beta/' . $modelPath . ':generateContent';

        // Normalize inlineData to inline_data because the app uses both names in places.
        $payload = $this->normalizeGeminiRestPayload($payload);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($url . '?key=' . urlencode($apiKey), $payload);

            if (! $response->successful()) {
                Log::error('Gemini REST call failed.', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new RuntimeException('Gemini REST returned HTTP ' . $response->status() . '.');
            }

            $decoded = $response->json();
            if (! is_array($decoded)) {
                throw new RuntimeException('Gemini REST returned an invalid response.');
            }

            return $decoded;
        } catch (Throwable $e) {
            Log::error('Gemini REST image call failed.', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Unexpected error while talking to Gemini vision endpoint: ' . $e->getMessage());
        }
    }

    private function normalizeGeminiRestPayload(array $payload): array
    {
        $normalized = $payload;

        foreach (($normalized['contents'] ?? []) as $contentIndex => $content) {
            if (! is_array($content)) {
                continue;
            }

            foreach (($content['parts'] ?? []) as $partIndex => $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (isset($part['inlineData']) && ! isset($part['inline_data'])) {
                    $normalized['contents'][$contentIndex]['parts'][$partIndex]['inline_data'] = $part['inlineData'];
                    unset($normalized['contents'][$contentIndex]['parts'][$partIndex]['inlineData']);
                }

                if (isset($normalized['contents'][$contentIndex]['parts'][$partIndex]['inline_data']['mimeType'])
                    && ! isset($normalized['contents'][$contentIndex]['parts'][$partIndex]['inline_data']['mime_type'])) {
                    $normalized['contents'][$contentIndex]['parts'][$partIndex]['inline_data']['mime_type'] =
                        $normalized['contents'][$contentIndex]['parts'][$partIndex]['inline_data']['mimeType'];
                    unset($normalized['contents'][$contentIndex]['parts'][$partIndex]['inline_data']['mimeType']);
                }
            }
        }

        return $normalized;
    }

    private function generateViaLaravelAiSdk(array $payload, string $model, int $timeout): array
    {
        $temporaryFiles = [];

        try {
            [$prompt, $attachments, $temporaryFiles] = $this->convertGeminiPayloadToSdkPrompt($payload);

            $response = (new GeminiBridgeAgent)->prompt(
                $prompt,
                provider: Lab::Gemini,
                model: $model,
                timeout: $timeout,
                attachments: $attachments,
            );

            $text = $this->responseToText($response);

            if ($text === '') {
                Log::warning('Laravel AI SDK returned an empty Gemini response.', [
                    'model' => $model,
                    'payload_keys' => array_keys($payload),
                ]);

                throw new RuntimeException('Gemini returned an empty response.');
            }

            return $this->toGeminiCompatibleResponse($text, $model, $response);
        } catch (Throwable $e) {
            Log::error('Laravel AI SDK Gemini call failed.', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Unexpected error while talking to Gemini through Laravel AI SDK: ' . $e->getMessage());
        } finally {
            foreach ($temporaryFiles as $file) {
                if (is_string($file) && $file !== '' && is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Converts the old Gemini REST payload shape into Laravel AI SDK prompt input.
     */
    private function convertGeminiPayloadToSdkPrompt(array $payload): array
    {
        $promptParts = [];
        $attachments = [];
        $temporaryFiles = [];

        foreach (($payload['contents'] ?? []) as $content) {
            if (! is_array($content)) {
                continue;
            }

            $role = trim((string) ($content['role'] ?? 'user'));
            $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];

            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (isset($part['text'])) {
                    $text = trim((string) $part['text']);
                    if ($text !== '') {
                        $promptParts[] = strtoupper($role) . ': ' . $text;
                    }
                    continue;
                }

                $inline = $part['inline_data'] ?? $part['inlineData'] ?? null;
                if (is_array($inline)) {
                    $attachment = $this->inlineDataToImageAttachment($inline, $temporaryFiles);
                    if ($attachment !== null) {
                        $attachments[] = $attachment;
                    }
                }
            }
        }

        $prompt = trim(implode("\n\n", $promptParts));
        if ($prompt === '') {
            $prompt = 'Respond to the attached input.';
        }

        $generationConfig = is_array($payload['generationConfig'] ?? null) ? $payload['generationConfig'] : [];
        $responseMimeType = strtolower(trim((string) ($generationConfig['responseMimeType'] ?? $generationConfig['response_mime_type'] ?? '')));

        if ($responseMimeType === 'application/json') {
            $prompt .= "\n\nReturn ONLY valid JSON. Do not wrap it in markdown fences.";
        }

        return [$prompt, $attachments, $temporaryFiles];
    }

    private function inlineDataToImageAttachment(array $inline, array &$temporaryFiles): mixed
    {
        $base64 = (string) ($inline['data'] ?? '');
        $mimeType = strtolower(trim((string) ($inline['mime_type'] ?? $inline['mimeType'] ?? 'image/jpeg')));

        if ($base64 === '') {
            return null;
        }

        $binary = base64_decode($base64, true);
        if ($binary === false) {
            return null;
        }

        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $path = tempnam(sys_get_temp_dir(), 'gemini-ai-sdk-image-');
        if ($path === false) {
            return null;
        }

        $imagePath = $path . '.' . $extension;
        @rename($path, $imagePath);
        file_put_contents($imagePath, $binary);
        $temporaryFiles[] = $imagePath;

        return Files\Image::fromPath($imagePath);
    }

    private function responseToText(mixed $response): string
    {
        if (is_string($response)) {
            return trim($response);
        }

        if (is_object($response)) {
            foreach (['text', 'content', 'message', 'outputText'] as $property) {
                if (isset($response->{$property}) && is_scalar($response->{$property})) {
                    return trim((string) $response->{$property});
                }
            }

            foreach (['text', 'content', 'message', 'toText', 'value'] as $method) {
                if (method_exists($response, $method)) {
                    $value = $response->{$method}();
                    if (is_scalar($value)) {
                        return trim((string) $value);
                    }
                }
            }

            if (method_exists($response, '__toString')) {
                return trim((string) $response);
            }
        }

        if (is_array($response)) {
            return trim((string) Arr::get($response, 'text', Arr::get($response, 'content', Arr::get($response, 'message', ''))));
        }

        return '';
    }

    private function toGeminiCompatibleResponse(string $text, string $model, mixed $sdkResponse): array
    {
        $usage = [];

        if (is_object($sdkResponse)) {
            foreach (['usage', 'usageMetadata'] as $property) {
                if (isset($sdkResponse->{$property}) && is_array($sdkResponse->{$property})) {
                    $usage = $sdkResponse->{$property};
                    break;
                }
            }
        }

        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'index' => 0,
                ],
            ],
            'usageMetadata' => $usage,
            'modelVersion' => $model,
            'meta' => [
                'provider' => 'laravel_ai_sdk_or_gemini_rest',
                'lab' => 'gemini',
            ],
        ];
    }
}
