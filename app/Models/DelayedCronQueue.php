<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelayedCronQueue extends Model
{
    protected $table = 'delayed_cron_queue';

    protected $fillable = [
        'message_cron_id',
        'expected_run_at',
        'processed_at',
    ];

    protected $casts = [
        'expected_run_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function cron()
    {
        return $this->belongsTo(MessageCron::class, 'message_cron_id');
    }
}
