<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GeminiClient
{
    public function generate(array $payload, ?string $model = null): array
    {
        $apiKey = trim((string) config('services.gemini.api_key'));
        $model = trim((string) ($model ?: config('services.gemini.model', 'gemini-2.5-flash')));
        $timeout = max(5, (int) config('services.gemini.timeout', 45));

        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is missing.');
        }

        if ($model === '') {
            throw new RuntimeException('Gemini model is missing.');
        }

        $this->validatePayload($payload);

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $model,
            $apiKey
        );

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(15)
                ->timeout($timeout)
                ->retry(
                    2,
                    700,
                    function (Throwable $exception): bool {
                        return $exception instanceof ConnectionException;
                    }
                )
                ->post($url, $payload);

            $response->throw();

            $data = $response->json();

            if (! is_array($data) || empty($data)) {
                Log::warning('Gemini returned an empty or invalid JSON response.', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new RuntimeException('Gemini returned an empty or invalid response.');
            }

            if (isset($data['error'])) {
                $message = (string) Arr::get($data, 'error.message', 'Gemini API returned an error.');

                Log::error('Gemini API logical error.', [
                    'model' => $model,
                    'error' => $data['error'],
                ]);

                throw new RuntimeException($message);
            }

            return $data;
        } catch (ConnectionException $e) {
            Log::error('Gemini connection failed.', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Unable to connect to Gemini right now. Please try again.');
        } catch (RequestException $e) {
            $response = $e->response;
            $body = $response ? ($response->json() ?: $response->body()) : null;
            $message = is_array($body)
                ? (string) Arr::get($body, 'error.message', 'Gemini request failed.')
                : 'Gemini request failed.';

            Log::error('Gemini request exception.', [
                'model' => $model,
                'status' => $response?->status(),
                'body' => $body,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException($message);
        } catch (Throwable $e) {
            Log::error('Unexpected Gemini client error.', [
                'model' => $model,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new RuntimeException('Unexpected error while talking to Gemini.');
        }
    }

    private function validatePayload(array $payload): void
    {
        $contents = $payload['contents'] ?? null;

        if (! is_array($contents) || $contents === []) {
            throw new RuntimeException('Gemini payload must contain at least one contents entry.');
        }
    }
}
