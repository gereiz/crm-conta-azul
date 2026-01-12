<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_id',
        'ca_id',
        'status',
        'payment_type',
        'valor_original',
        'saldo_devedor',
        'descricao',
        'data_vencimento',
        'data_emissao',
        'link_boleto',
        'cliente_id',
        'cliente_ca_id',
        'cliente_nome',
    ];

    protected $casts = [
        'data_vencimento' => 'date',
        'data_emissao' => 'date',
        'valor_original' => 'decimal:2',
        'saldo_devedor' => 'decimal:2',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }
}
