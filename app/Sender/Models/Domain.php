<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends SenderModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    protected $table = 'sender_domains';

    protected $fillable = [
        'organization_id',
        'domain',
        'verification_token',
        'status',
        'dkim_selector',
        'dkim_private_key',
        'dkim_public_key',
        'verified_at',
        'last_checked_at',
    ];

    protected $hidden = ['dkim_private_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dkim_private_key' => 'encrypted',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }
}
