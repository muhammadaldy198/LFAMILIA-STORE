<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedGameAccount extends Model
{
    protected $fillable = ['user_id', 'product_id', 'label', 'customer_input', 'nickname'];

    protected function casts(): array
    {
        return ['customer_input' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
