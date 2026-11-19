<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    /** @use HasFactory<\Database\Factories\GenerationFactory> */
    use HasFactory;

    public const PENDING = 'pending';       // created locally, not yet accepted by Pixelforge
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';     // Pixelforge refused the create request: nothing created, nothing billed

    public const IN_FLIGHT = [self::QUEUED, self::PROCESSING];
    public const ACTIVE = [self::PENDING, self::QUEUED, self::PROCESSING];
    public const FINAL = [self::SUCCEEDED, self::FAILED, self::REJECTED];

    protected $fillable = [
        'uuid', 'user_id', 'provider_id', 'prompt', 'size', 'quality', 'reference_images',
        'status', 'error_code', 'error_message', 'submit_attempts',
        'estimated_min_nano', 'estimated_max_nano', 'rates', 'usage', 'provider_cost_nano', 'price_nano',
        'image_url', 'image_url_expires_at', 'provider_created_at', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'submit_attempts' => 'integer',
            'estimated_min_nano' => 'integer',
            'estimated_max_nano' => 'integer',
            'provider_cost_nano' => 'integer',
            'price_nano' => 'integer',
            'image_url_expires_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    /** The exact body sent to POST /v1/generations (only documented parameters). */
    public function toPixelforgePayload(): array
    {
        $payload = [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'metadata' => [
                'app_generation_id' => $this->uuid,
                'user_id' => (string) $this->user_id,
            ],
        ];

        if (! empty($this->reference_images)) {
            $payload['reference_images'] = array_values($this->reference_images);
        }

        return $payload;
    }
}
