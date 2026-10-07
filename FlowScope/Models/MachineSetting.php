<?php

namespace Plugin\Flowscope\Models;

use Illuminate\Database\Eloquent\Model;

final class MachineSetting extends Model
{
    protected $table = 'flowscope_machine_settings';
    protected $guarded = ['id'];
    protected $casts = ['machine_id' => 'integer', 'enabled' => 'boolean', 'cycle_start_day' => 'integer', 'quota_bytes' => 'string'];
}
