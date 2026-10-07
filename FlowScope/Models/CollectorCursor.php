<?php

namespace Plugin\Flowscope\Models;

use Illuminate\Database\Eloquent\Model;

final class CollectorCursor extends Model
{
    protected $table = 'flowscope_collector_cursors';
    protected $guarded = ['id'];
    protected $casts = ['machine_id' => 'integer', 'sequence' => 'string', 'interfaces' => 'array', 'first_sample_at' => 'integer', 'last_sample_at' => 'integer', 'last_report_at' => 'integer', 'resets' => 'integer', 'boot_changes' => 'integer'];
}
