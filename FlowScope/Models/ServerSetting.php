<?php

namespace Plugin\Flowscope\Models;

use Illuminate\Database\Eloquent\Model;

final class ServerSetting extends Model
{
    protected $table = 'flowscope_server_settings';

    protected $fillable = ['server_type', 'server_id', 'enabled', 'display_name', 'quota_bytes', 'traffic_mode', 'cycle_start_day'];

    protected $casts = [
        'server_id' => 'integer',
        'enabled' => 'boolean',
        'quota_bytes' => 'string',
        'cycle_start_day' => 'integer',
    ];
}
