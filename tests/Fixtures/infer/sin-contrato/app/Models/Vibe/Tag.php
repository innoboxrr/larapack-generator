<?php

namespace App\Models\Vibe;

use Illuminate\Database\Eloquent\Model;

/**
 * Otro Tag, en un subnamespace (como los Message de brandai/vibe): el
 * laraimport exige nombres únicos, y es una decisión.
 */
class Tag extends Model
{
    protected $table = 'vibe_tags';
}
