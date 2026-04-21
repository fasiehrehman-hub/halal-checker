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
        $status = strtolower(trim((string) ($this->status ?? '')));
        $type = strtolower(trim((string) ($this->type ?? '')));

        if (in_array($status, ['halal', 'haram', 'mushbooh'], true)) {
            return $status;
        }

        if (in_array($type, ['halal', 'haram', 'mushbooh', 'mashbooh'], true)) {
            return $type === 'mashbooh' ? 'mushbooh' : $type;
        }

        return null;
    }
}