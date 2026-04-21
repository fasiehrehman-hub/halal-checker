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

            $history[] = [
                'role' => 'assistant',
                'message' => $reply !== '' ? $reply : 'No reply generated.',
            ];

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

            $normalized[] = [
                'role' => $role,
                'message' => $message,
            ];
        }

        return array_slice($normalized, -12);
    }
}