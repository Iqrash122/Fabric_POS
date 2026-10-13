<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $credit_delivery
 * @property bool $cost_reporting
 * @property int $recent_days
 * @property int $refresh_seconds
 */
class BusinessSetting extends Model
{
    protected $table = 'business_settings';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['credit_delivery' => 'boolean', 'cost_reporting' => 'boolean', 'recent_days' => 'integer', 'refresh_seconds' => 'integer'];
    }
}
