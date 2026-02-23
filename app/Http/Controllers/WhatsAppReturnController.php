<?php

namespace App\Http\Controllers;

use App\Models\ContaAzulConnection;
use App\Models\WhatsappMessageLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
            ->orderBy('sent_at', 'desc');

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
}
