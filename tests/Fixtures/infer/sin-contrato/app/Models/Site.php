<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    protected $guarded = ['id', 'ssl_status'];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'published_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class)->withDefault();
    }

    public function visitsThroughAssets()
    {
        return $this->hasManyThrough(Comment::class, Asset::class);
    }
}
