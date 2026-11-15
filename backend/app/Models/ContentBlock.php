<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContentBlock extends Model
{
    use HasUuids;

    protected $fillable = ['key', 'group', 'data', 'status', 'position', 'updated_by'];

    protected function casts(): array
    {
        return ['data' => 'array', 'position' => 'integer'];
    }
}
