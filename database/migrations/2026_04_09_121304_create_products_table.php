<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'name_normalized')) {
                $table->string('name_normalized')->nullable()->after('name')->index();
            }

            if (!Schema::hasColumn('products', 'brand_normalized')) {
                $table->string('brand_normalized')->nullable()->after('brand')->index();
            }

            if (!Schema::hasColumn('products', 'status')) {
                $table->string('status', 32)->nullable()->after('origin')->index();
            }

            if (!Schema::hasColumn('products', 'decision_source')) {
                $table->string('decision_source')->nullable()->after('status')->index();
            }
        });

        try {
            Schema::table('products', function (Blueprint $table) {
                $table->index(['category', 'status'], 'products_category_status_idx');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('products', function (Blueprint $table) {
                $table->index(['brand', 'status'], 'products_brand_status_idx');
            });
        } catch (\Throwable) {
        }

        DB::table('products')
            ->select(['id', 'name', 'brand', 'type', 'status'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $normalizedName = $this->normalize((string) ($row->name ?? ''));
                    $normalizedBrand = $this->normalize((string) ($row->brand ?? ''));
                    $status = $this->normalizeDecision((string) ($row->status ?: $row->type));

                    DB::table('products')
                        ->where('id', $row->id)
                        ->update([
                            'name_normalized' => $normalizedName !== '' ? $normalizedName : null,
                            'brand_normalized' => $normalizedBrand !== '' ? $normalizedBrand : null,
                            'status' => $status,
                            'decision_source' => $status ? 'migration_backfill' : null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach ([
                'products_category_status_idx',
                'products_brand_status_idx',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }

            foreach (['decision_source', 'status', 'brand_normalized', 'name_normalized'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function normalizeDecision(string $value): ?string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'halal' => 'halal',
            'haram' => 'haram',
            'mushbooh', 'mashbooh' => 'mushbooh',
            default => null,
        };
    }
};