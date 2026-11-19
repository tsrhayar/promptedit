<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const PURCHASE = 'purchase';
    public const HOLD = 'hold';
    public const RELEASE = 'release';
    public const CHARGE = 'charge';

    protected $fillable = ['user_id', 'generation_id', 'type', 'amount_nano'];

    protected function casts(): array
    {
        return ['amount_nano' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
