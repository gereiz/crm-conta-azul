<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageCron extends Model
{
    protected $fillable = [
        'name',
        'message_template_id',
        'whatsapp_number_id',
        'connection_id',
        'type',
        'period_value',
        'period_unit',
        'days_before_due',
        'days_after_due',
        'send_time',
        'is_active',
        'limit_link_preview',
        'disable_link_preview',
        'last_run_at',
        'created_by'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'limit_link_preview' => 'boolean',
        'disable_link_preview' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function messageTemplate()
    {
        return $this->belongsTo(WhatsappTemplate::class, 'message_template_id');
    }

    public function whatsappNumber()
    {
        return $this->belongsTo(WhatsappNumber::class);
    }

    public function logs()
    {
        return $this->hasMany(MessageCronLog::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
