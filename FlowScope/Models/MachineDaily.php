<?php

namespace Plugin\Flowscope\Models;

use Illuminate\Database\Eloquent\Model;

final class MachineDaily extends Model
{
    protected $table = 'flowscope_machine_daily';
    protected $guarded = ['id'];
    protected $casts = ['machine_id' => 'integer', 'record_at' => 'integer', 'rx_bytes' => 'string', 'tx_bytes' => 'string'];
}
