<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderSensitiveInput extends Model
{
    protected $fillable = ['order_id', 'field_key', 'value'];

    protected $hidden = ['value'];

    /**
     * Encrypt sensitive values transparently at the persistence boundary.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
