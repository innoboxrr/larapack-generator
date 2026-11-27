<?php

namespace App\Models;

use App\Models\Traits\Relations\CommentRelations;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use CommentRelations;

    protected $fillable = ['commentable_type', 'commentable_id', 'site_id', 'body', 'status', 'seen_at', 'position'];
}
