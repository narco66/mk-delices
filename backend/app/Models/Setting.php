<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'group', 'value', 'is_public', 'requires_validation', 'description'];

    protected function casts(): array
    {
        return ['value' => 'json', 'is_public' => 'boolean', 'requires_validation' => 'boolean'];
    }
}
