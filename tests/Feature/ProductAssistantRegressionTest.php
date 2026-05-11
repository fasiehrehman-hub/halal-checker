<?php

namespace Tests\Feature;

use App\Services\ProductAssistantService;
use Tests\TestCase;

class ProductAssistantRegressionTest extends TestCase
{
    public function test_product_assistant_critical_prompts_do_not_break(): void
    {
        $cases = require base_path('tests/Fixtures/product_assistant_regression_cases.php');

        foreach ($cases as $case) {
            $result = app(ProductAssistantService::class)->handle(
                $case['prompt'],
                $case['history'] ?? [],
                null,
                $case['preferred_origin'] ?? null
            );

            $reply = (string) ($result['reply'] ?? '');

            $products = $result['data']['products']
                ?? $result['products']
                ?? [];

            $products = is_array($products) ? $products : [];

            $productNames = array_map(function ($product) {
                return mb_strtolower((string) ($product['name'] ?? ''));
            }, $products);

            if (! empty($case['must_have_products'])) {
                $this->assertNotEmpty(
                    $products,
                    "Case failed [{$case['name']}]: expected products, got none. Reply: {$reply}"
                );
            }

            foreach ($case['reply_must_contain'] ?? [] as $text) {
                $this->assertStringContainsStringIgnoringCase(
                    $text,
                    $reply,
                    "Case failed [{$case['name']}]: reply missing [{$text}]. Reply: {$reply}"
                );
            }

            foreach ($case['reply_must_not_contain'] ?? [] as $text) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $text,
                    $reply,
                    "Case failed [{$case['name']}]: reply contains blocked text [{$text}]. Reply: {$reply}"
                );
            }

            foreach ($case['must_not_contain_products'] ?? [] as $blockedProduct) {
                $blockedLower = mb_strtolower($blockedProduct);

                $found = collect($productNames)->contains(function ($name) use ($blockedLower) {
                    return str_contains($name, $blockedLower);
                });

                $this->assertFalse(
                    $found,
                    "Case failed [{$case['name']}]: blocked product appeared [{$blockedProduct}]. Reply: {$reply}"
                );
            }

            if (! empty($case['origin_must_contain'])) {
                foreach ($products as $product) {
                    $this->assertStringContainsStringIgnoringCase(
                        $case['origin_must_contain'],
                        (string) ($product['origin'] ?? ''),
                        "Case failed [{$case['name']}]: origin mismatch for product " . ($product['name'] ?? 'unknown')
                    );
                }
            }
        }
    }
}