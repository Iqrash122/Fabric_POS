<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property bool $active */
class Customer extends Model
{
    protected $table = 'customers';
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
