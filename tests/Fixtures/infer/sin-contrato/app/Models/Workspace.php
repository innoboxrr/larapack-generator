<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workspace extends Model
{
    use SoftDeletes;

    protected $fillable = ['owner_id', 'name', 'slug', 'credits', 'settings', 'vertical'];

    protected $hidden = ['settings'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'credits' => 'decimal:4',
        ];
    }

    public function sites()
    {
        return $this->hasMany(Site::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }
}
