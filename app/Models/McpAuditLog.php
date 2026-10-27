<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpAuditLog extends Model
{
    protected $fillable = ['user_id', 'mcp_token_id', 'tool', 'action', 'subject_type', 'subject_id', 'details', 'ip'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
