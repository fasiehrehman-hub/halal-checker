<?php

namespace App\Http\Controllers;

use App\Services\ProductAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function index()
    {
        return view('chat');
    }

    public function send(Request $request, ProductAssistantService $assistant): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $message = $this->normalizeMessage((string) ($validated['message'] ?? ''));
        $image = $request->file('image');

        if ($message === '' && !$image) {
            return response()->json([
                'reply' => 'Please type a message or upload a product image.',
                'data' => [
                    'status' => 'not_found',
                    'message' => 'No message or image was provided.',
                    'products' => [],
                ],
            ], 422);
        }

        try {
            $history = $this->normalizeHistory(session('chat_history', []));
            $currentProductContext = $this->normalizeCurrentProductContext(session('chat_current_product_context'));
            // A fresh image upload is a new product context. Do not prepend the previous
            // product context because it can make follow-up handling jump back to an older product.
            if (!$image && $currentProductContext !== null) {
                $history[] = [
                    'role' => 'assistant',
                    'message' => $this->currentProductContextMessage($currentProductContext),
                    'current_product' => $currentProductContext,
                    'image_context' => $currentProductContext,
                ];
            }

            $locationPreference = session('chat_location', []);

            $history[] = [
                'role' => 'user',
                'message' => $message !== '' ? $message : '[image uploaded]',
            ];

            $result = $assistant->handle(
                message: $message,
                history: $history,
                image: $image,
                preferredOrigin: $locationPreference['country'] ?? null
            );

            $reply = trim((string) ($result['reply'] ?? ''));
            $data = is_array($result['data'] ?? null)
                ? $result['data']
                : [
                    'status' => 'error',
                    'message' => 'Invalid service payload.',
                    'products' => [],
                ];

            $detectedProductContext = $this->extractCurrentProductContextFromResult($data);
            if ($detectedProductContext !== null) {
                session(['chat_current_product_context' => $detectedProductContext]);
            }

            $assistantHistoryEntry = [
                'role' => 'assistant',
                'message' => $reply !== '' ? $reply : 'No reply generated.',
            ];

            if ($detectedProductContext !== null) {
                $assistantHistoryEntry['current_product'] = $detectedProductContext;
                $assistantHistoryEntry['image_context'] = $detectedProductContext;
            }

            $history[] = $assistantHistoryEntry;

            session(['chat_history' => array_slice($history, -12)]);

            $data['meta'] = array_merge(
                [
                    'history_count' => count(session('chat_history', [])),
                    'location_preference' => $locationPreference['country'] ?? null,
                ],
                is_array($data['meta'] ?? null) ? $data['meta'] : []
            );

            return response()->json([
                'reply' => $reply !== '' ? $reply : 'I could not prepare a response right now.',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('ChatController failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'reply' => 'Something went wrong while checking the product database.',
                'data' => [
                    'status' => 'error',
                    'message' => 'Something went wrong while checking the product database.',
                    'products' => [],
                ],
            ], 500);
        }
    }

    public function saveLocationPreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => ['required', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        session([
            'chat_location' => [
                'country' => trim((string) $validated['country']),
                'country_code' => strtoupper(trim((string) ($validated['country_code'] ?? ''))) ?: null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Location preference saved successfully.',
            'data' => session('chat_location'),
        ]);
    }

    public function clearLocationPreference(): JsonResponse
    {
        session()->forget('chat_location');

        return response()->json([
            'success' => true,
            'message' => 'Location preference cleared successfully.',
        ]);
    }

    protected function normalizeMessage(string $message): string
    {
        $message = trim($message);
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
        return trim($message);
    }

    protected function normalizeHistory(array $history): array
    {
        $normalized = [];

        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }

            $role = strtolower(trim((string) ($item['role'] ?? '')));
            $message = $this->normalizeMessage((string) ($item['message'] ?? ''));

            if (!in_array($role, ['user', 'assistant'], true) || $message === '') {
                continue;
            }

            $entry = [
                'role' => $role,
                'message' => $message,
            ];

            foreach (['current_product', 'image_context', 'selected_product', 'product'] as $contextKey) {
                $context = $this->normalizeCurrentProductContext($item[$contextKey] ?? null);
                if ($context !== null) {
                    $entry[$contextKey] = $context;
                }
            }

            $normalized[] = $entry;
        }

        return array_slice($normalized, -12);
    }

    protected function extractCurrentProductContextFromResult(array $data): ?array
    {
        $products = is_array($data['products'] ?? null) ? $data['products'] : [];
        foreach ($products as $product) {
            $context = $this->normalizeCurrentProductContext($product);
            if ($context !== null) {
                return $context;
            }
        }

        foreach ([
            $data['meta']['focus_product'] ?? null,
            $data['meta']['image_context'] ?? null,
            $data['meta']['intent']['arguments']['image_context'] ?? null,
            $data['meta']['tool_arguments']['image_context'] ?? null,
        ] as $candidate) {
            $context = $this->normalizeCurrentProductContext($candidate);
            if ($context !== null) {
                return $context;
            }
        }

        return null;
    }

    protected function normalizeCurrentProductContext(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $name = $this->firstNonEmptyContextString($value, ['product_name', 'productName', 'name', 'title']);
        $barcode = $this->firstNonEmptyContextString($value, ['barcode', 'bar_code', 'barCode', 'code']);
        $brand = $this->firstNonEmptyContextString($value, ['brand', 'brand_name', 'brandName']);

        if ($name === null && $barcode === null) {
            return null;
        }

        $context = [];
        if ($name !== null && !$this->isNoisyCurrentProductName($name)) {
            $context['product_name'] = $name;
        }
        if ($barcode !== null) {
            $context['barcode'] = $barcode;
        }
        if ($brand !== null && !$this->isNoisyCurrentProductName($brand)) {
            $context['brand'] = $brand;
        }

        return $context !== [] ? $context : null;
    }

    protected function firstNonEmptyContextString(array $value, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $candidate = trim((string) $value[$key]);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    protected function isNoisyCurrentProductName(string $name): bool
    {
        $lower = strtolower(trim($name));
        return $lower === '' || in_array($lower, [
            'product', 'item', 'image', 'uploaded image', 'image detection', 'no image',
            'unknown', 'n/a', 'not found', 'database', 'ingredients', 'barcode',
        ], true);
    }

    protected function currentProductContextMessage(array $context): string
    {
        $name = trim((string) ($context['product_name'] ?? ''));
        $barcode = trim((string) ($context['barcode'] ?? ''));

        if ($name !== '' && $barcode !== '') {
            return 'Current product: ' . $name . ' barcode: ' . $barcode;
        }

        if ($name !== '') {
            return 'Current product: ' . $name;
        }

        return 'Current product barcode: ' . $barcode;
    }
}