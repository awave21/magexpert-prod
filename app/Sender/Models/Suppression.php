<?php

namespace App\Sender\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suppression extends SenderModel
{
    public const REASON_BOUNCE = 'bounce';

    public const REASON_COMPLAINT = 'complaint';

    public const REASON_UNSUBSCRIBE = 'unsubscribe';

    public const REASON_MANUAL = 'manual';

    protected $table = 'sender_suppressions';

    protected $fillable = ['organization_id', 'email', 'reason'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
