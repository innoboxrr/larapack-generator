<?php

namespace App\Models\Traits\Relations;

use App\Models\Site;

trait CommentRelations
{
    public function commentable()
    {
        return $this->morphTo();
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
