<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'barcode',
        'upid',
        'name',
        'name_normalized',
        'brand',
        'brand_normalized',
        'main_category',
        'main_category1',
        'category',
        'categories',
        'origin',
        'status',
        'type',
        'decision_source',
        'image',
        'description',
        'ingredients',
        'notes',
        'allergens',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getDecisionAttribute(): ?string
    {
        $status = $this->normalizeDecisionValue($this->status ?? null);
        if ($status !== null) {
            return $status;
        }

        return $this->normalizeDecisionValue($this->type ?? null);
    }

    protected function normalizeDecisionValue(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return match ($value) {
            'halal', 'approved', 'approve', 'permissible', 'permitted', 'halal certified', 'safe', 'muslim friendly' => 'halal',
            'haram', 'not halal', 'non halal', 'forbidden', 'prohibited' => 'haram',
            'mushbooh', 'mashbooh', 'doubtful', 'suspect', 'questionable' => 'mushbooh',
            'unknown', 'pending', 'unverified', 'needs review', 'needs verification' => 'unknown',
            default => null,
        };
    }
}
