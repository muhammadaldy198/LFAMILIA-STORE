<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderSensitiveInput extends Model
{
    protected $fillable = ['order_id', 'field_key', 'value'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
