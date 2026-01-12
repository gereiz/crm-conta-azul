<?php

namespace App\Http\Controllers;

use App\Models\MessageLog;
use App\Services\BillingRestrictionService;
use App\Services\WhapiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        $result = $this->whapiService->sendMessage(
            $request->whatsapp_id,
            $request->to,
            $request->message
        );

        // Registrar Log
        MessageLog::create([
            'user_id' => Auth::id(),
            'whatsapp_number_id' => $request->whatsapp_id,
            'to' => $request->to,
            'content' => $request->message,
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
