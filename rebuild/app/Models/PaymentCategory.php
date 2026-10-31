<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentCategory extends Model
{
    // Stable keys referenced by payment catalog sync and checkout. Names,
    // descriptions, order, and visibility stay editable in the admin panel.
    public const SYSTEM_CODES = ['wallet', 'qris', 'ewallet', 'va', 'retail', 'other'];

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function channels(): HasMany
    {
        return $this->hasMany(PaymentChannel::class, 'category_code', 'code');
    }
}
