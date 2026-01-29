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
        'rule_type',
        'day_of_month',
        'day_of_week',
        'interval_days',
        'exclude_weekends',
        'period_value',
        'period_unit',
        'days_before_due',
        'days_after_due',
        'send_time',
        'is_active',
        'limit_link_preview',
        'disable_link_preview',
        'last_run_at',
        'run_when_delayed',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'exclude_weekends' => 'boolean',
        'limit_link_preview' => 'boolean',
        'disable_link_preview' => 'boolean',
        'run_when_delayed' => 'boolean',
        'last_run_at' => 'datetime',
        'day_of_month' => 'array',
        'day_of_week' => 'array',
        'days_before_due' => 'integer',
        'days_after_due' => 'integer',
        'period_value' => 'integer',
        'interval_days' => 'integer',
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

    public function connection()
    {
        return $this->belongsTo(ContaAzulConnection::class, 'connection_id');
    }
}
