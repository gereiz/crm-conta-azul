<?php

namespace App\Http\Controllers;

use App\Models\ContaAzulConnection;
use App\Models\WhatsappMessageLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WhatsAppReturnController extends Controller
{
    public function index(Request $request)
    {
        $connectionId = $request->input('connection_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $search = $request->input('search');
        $senderNumberId = $request->input('whatsapp_number_id');
        $status = $request->input('status');
        $provider = $request->input('provider');
        $messageType = $request->input('message_type');
        $responded = $request->input('responded');
        $providerMessageId = $request->input('provider_message_id');

        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : ($start ? $start->copy()->endOfDay() : null);

        $query = WhatsappMessageLog::with(['connection', 'cliente', 'user', 'whatsappNumber'])
            ->select('whatsapp_message_logs.*')
            ->orderBy('sent_at', 'desc')
            ->addSelect([
                'last_response_text' => DB::raw("(SELECT COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(im.payload_json, '$.text.body')),
                    JSON_UNQUOTE(JSON_EXTRACT(im.payload_json, '$.body')),
                    JSON_UNQUOTE(JSON_EXTRACT(im.payload_json, '$.caption'))
                )
                FROM incoming_messages im
                WHERE (
                    im.numero_origem = whatsapp_message_logs.phone_sanitized
                    OR im.numero_destino = whatsapp_message_logs.phone_sanitized
                    OR im.numero_origem = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp_message_logs.phone_original,''),' ',''),'-',''),'(',''),')',''),'.',''),'+','')
                    OR im.numero_destino = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp_message_logs.phone_original,''),' ',''),'-',''),'(',''),')',''),'.',''),'+','')
                )
                ORDER BY im.created_at DESC
                LIMIT 1)")
            ]);

        if ($connectionId) $query->where('connection_id', $connectionId);
        if ($senderNumberId) $query->where('whatsapp_number_id', $senderNumberId);
        if ($start && $end) $query->whereBetween('sent_at', [$start, $end]);
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('client_name', 'like', "%{$search}%")
                    ->orWhere('phone_original', 'like', "%{$search}%")
                    ->orWhere('phone_sanitized', 'like', "%{$search}%");
            });
        }
        if ($messageType) $query->where('message_type', $messageType);
        if ($provider) $query->where('provider', $provider);
        if ($providerMessageId) $query->where('provider_message_id', $providerMessageId);
        if ($status) $query->where('delivery_status', $status);
        if ($responded !== null && $responded !== '') $query->where('responded', filter_var($responded, FILTER_VALIDATE_BOOLEAN));

        $items = $query->paginate(20)->withQueryString();

        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();

        return Inertia::render('WhatsApp/Returns/Index', [
            'items' => $items,
            'connections' => $connections,
            'filters' => [
                'connection_id' => $connectionId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'search' => $search,
                'whatsapp_number_id' => $senderNumberId,
                'status' => $status,
                'provider' => $provider,
                'message_type' => $messageType,
                'responded' => $responded,
                'provider_message_id' => $providerMessageId,
            ],
        ]);
    }

    public function webhookDebug(Request $request)
    {
        $phone = preg_replace('/\D+/', '', (string) $request->input('phone'));
        $limit = (int) ($request->input('limit') ?? 100);
        $query = \App\Models\WebhookEventLog::orderByDesc('created_at');
        if ($phone) {
            $query->where('phone', $phone);
        }
        $logs = $query->limit(max(10, min(500, $limit)))->get();
        return response()->json(['items' => $logs]);
    }
}
