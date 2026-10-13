<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string|int, mixed> $changes
 */
class AuditEvent extends Model
{
    protected $table = 'audit_events';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
