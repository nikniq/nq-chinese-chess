<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Game extends Model
{
    protected $fillable = ['result', 'difficulty', 'moves', 'duration_seconds', 'points'];

    public const DIFFICULTIES = [1 => 'Easy', 2 => 'Medium', 3 => 'Hard'];

    /** Points are computed server-side so clients cannot submit arbitrary scores. */
    public static function pointsFor(string $result, int $difficulty): int
    {
        return match ($result) {
            'win' => 10 * $difficulty,
            'draw' => 3 * $difficulty,
            default => 0,
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
