<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductInputField extends Model
{
    protected $fillable = ['product_id', 'field_key', 'label', 'type', 'is_required', 'sort_order'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }
}
