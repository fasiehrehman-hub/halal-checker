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
        'main_category_1',
        'category',
        'categories',
        'origin',
        'status',
        'type',
        'decision_source',
        'image',
        'custom_image',
        'web_image',
        'description',
        'ingredients',
        'notes',
        'allergens',
        'points',
        'prohibitions',
        'fatwa',
        'web_link',
        'change_reason',
        'halal_prohibitions',
        'haram_prohibitions',
        'mushbooh_prohibitions',
        'pending_prohibitions',
        'is_kosher',
        'is_vegetarian',
        'is_vegan',
        'is_gluten_free',
        'label',
        'all_labels',
        'manufactured_by',
        'feedback_path',
        'favourite_count',
        'locked_at',
        'locked_type',
        'is_multi_lang',
        'parent_temp_id',
        'parent_id',
        'is_suggested',
        'merged_in',
        'merged_user_id',
        'log_status',
        'scanned_count',
        'buy_count',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'locked_at' => 'datetime',
        'is_kosher' => 'boolean',
        'is_vegetarian' => 'boolean',
        'is_vegan' => 'boolean',
        'is_gluten_free' => 'boolean',
        'is_multi_lang' => 'boolean',
        'is_suggested' => 'boolean',
        'favourite_count' => 'integer',
        'scanned_count' => 'integer',
        'buy_count' => 'integer',
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
