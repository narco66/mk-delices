<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ContentPage extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'title', 'excerpt', 'body', 'status', 'seo_title', 'seo_description', 'requires_validation', 'published_at', 'updated_by'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'requires_validation' => 'boolean'];
    }
}
