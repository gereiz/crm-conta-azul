<?php

namespace App\Http\Controllers;

use App\Models\MessageLog;
use App\Models\Invoice;
use App\Models\Cliente;
use App\Services\BillingRestrictionService;
use App\Services\WhapiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class MessageController extends Controller
{
    protected $whapiService;
    protected $restrictionService;

    public function __construct(WhapiService $whapiService, BillingRestrictionService $restrictionService)
    {
        $this->whapiService = $whapiService;
        $this->restrictionService = $restrictionService;
    }

    public function send(Request $request)
    {
        $request->validate([
            'whatsapp_id' => 'required|exists:whatsapp_numbers,id',
            'to' => 'required|string',
            'message' => 'required|string',
            'connection_id' => 'nullable|exists:conta_azul_connections,id',
            'cliente_nome' => 'nullable|string',
            'cliente_ca_id' => 'nullable|string',
            'invoice_ca_id' => 'nullable|string',
            'descricao' => 'nullable|string',
        ]);

        if ($request->filled('connection_id')) {
            $blocked = $this->restrictionService->isBlocked((int) $request->connection_id, [
                'cliente_nome' => $request->input('cliente_nome'),
                'cliente_ca_id' => $request->input('cliente_ca_id'),
                'invoice_ca_id' => $request->input('invoice_ca_id'),
                'descricao' => $request->input('descricao'),
            ]);
            if ($blocked) {
                return redirect()->back()->with('error', 'Envio bloqueado por regra de restrição.');
            }
        }

        // Substituição de variáveis e agrupamento (manual)
        $messageContent = $request->message;
        try {
            // Determinar cliente (por CA ID ou a partir da fatura)
            $clienteCaId = $request->input('cliente_ca_id');
            if (!$clienteCaId && $request->filled('invoice_ca_id')) {
                $inv = Invoice::where('ca_id', $request->input('invoice_ca_id'))->first();
                if ($inv) $clienteCaId = $inv->cliente_ca_id;
            }
            $cliente = null;
            if ($clienteCaId) {
                $cliente = Cliente::where('ca_id', $clienteCaId)->first();
            }
            // Buscar faturas do cliente para agregação
            $invoices = collect();
            if ($clienteCaId) {
                $query = Invoice::where('cliente_ca_id', $clienteCaId)
                    ->orderBy('data_vencimento', 'asc');
                if ($request->filled('connection_id')) {
                    $query->where('connection_id', $request->input('connection_id'));
                }
                $invoices = $query->get();
            } elseif ($request->filled('invoice_ca_id')) {
                $inv = Invoice::where('ca_id', $request->input('invoice_ca_id'))->first();
                if ($inv) $invoices = collect([$inv]);
            }
            if ($invoices->count() > 0) {
                $dates = $invoices->map(function ($inv) {
                    $date = $inv->data_vencimento instanceof \Carbon\Carbon ? $inv->data_vencimento : Carbon::parse($inv->data_vencimento);
                    return $date->format('d/m/Y');
                })->implode(', ');
                $earliest = $invoices->min(function ($inv) {
                    return $inv->data_vencimento instanceof \Carbon\Carbon ? $inv->data_vencimento : Carbon::parse($inv->data_vencimento);
                });
                $adjustedEarliest = $earliest ? $earliest->copy() : null;
                if ($adjustedEarliest) {
                    if ($adjustedEarliest->isSaturday()) $adjustedEarliest->addDays(2);
                    if ($adjustedEarliest->isSunday()) $adjustedEarliest->addDays(1);
                }
                $totalValue = $invoices->sum(function ($inv) {
                    return (float) ($inv->saldo_devedor ?? $inv->valor_original ?? 0);
                });
                $firstUrl = $invoices->firstWhere('link_boleto', '!=', null);
                $allUrls = $invoices->pluck('link_boleto')->filter()->implode("\n");
                $pairs = $invoices->map(function ($inv) {
                    $date = $inv->data_vencimento instanceof \Carbon\Carbon ? $inv->data_vencimento : Carbon::parse($inv->data_vencimento);
                    $due = $date->format('d/m/Y');
                    $url = $inv->link_boleto ?? '';
                    return $due . ' - ' . $url;
                })->implode("\n");

                $replacements = [
                    '@@clientName@@' => $cliente?->name ?? ($request->input('cliente_nome') ?? 'Cliente'),
                    '@@clientCompany@@' => $cliente?->company_name ?? '',
                    '@@clientEmail@@' => $cliente?->email ?? '',
                    '@@invoicePastDueQuantity@@' => (string) $invoices->count(),
                    '@@invoicePastDueDates@@' => $dates,
                    '@@invoiceTotalValue@@' => number_format($totalValue, 2, ',', '.'),
                    '@@invoiceBoletoUrl@@' => $firstUrl ? ($firstUrl->link_boleto ?? '') : '',
                    '@@invoiceBoletoUrls@@' => $allUrls,
                    '@@invoicePastDuePairs@@' => $pairs,
                    '@@invoiceDueDate@@' => $adjustedEarliest ? $adjustedEarliest->format('d/m/Y') : '',
                    '@@invoiceStrictDueDate@@' => $earliest ? $earliest->format('d/m/Y') : '',
                    '@@invoiceOpenValue@@' => number_format($totalValue, 2, ',', '.'),
                    '@@invoiceLateDays@@' => $earliest ? Carbon::now()->diffInDays($earliest) : 0,
                ];
                foreach ($replacements as $key => $value) {
                    $messageContent = str_replace($key, (string) $value, $messageContent);
                }
            }
        } catch (\Throwable $e) {
            // Silencioso: em caso de erro nas substituições, segue com o conteúdo original
        }

        $result = $this->whapiService->sendMessage(
            $request->whatsapp_id,
            $request->to,
            $messageContent
        );

        // Registrar Log
        MessageLog::create([
            'user_id' => Auth::id(),
            'whatsapp_number_id' => $request->whatsapp_id,
            'to' => $request->to,
            'content' => $messageContent,
            'status' => $result['success'] ? 'success' : 'error',
            'error_message' => $result['success'] ? null : $result['message'],
            'message_id' => $result['success'] ? ($result['data']['message_id'] ?? null) : null,
        ]);

        if ($result['success']) {
            return redirect()->back()->with('success', 'Mensagem enviada com sucesso!');
        } else {
            return redirect()->back()->with('error', $result['message']);
        }
    }
}
