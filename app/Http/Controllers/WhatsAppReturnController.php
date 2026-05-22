<?php

namespace App\Http\Controllers;

use App\Models\ContaAzulConnection;
use App\Models\WhatsappMessageLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WhatsAppReturnController extends Controller
{
    public function index(Request $request)
    {
        $items = $this->buildFilteredQuery($request)->paginate(20)->withQueryString();

        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();

        return Inertia::render('WhatsApp/Returns/Index', [
            'items' => $items,
            'connections' => $connections,
            'filters' => $this->extractFilters($request),
        ]);
    }

    public function downloadXlsx(Request $request)
    {
        $logs = $this->buildFilteredQuery($request)->get();

        $service = new \App\Services\WhatsAppReturnExportService();
        $spreadsheet = $service->build($logs);
        $writer = new Xlsx($spreadsheet);

        $dateSuffix = now()->format('Y-m-d_H-i');
        $filename = "retorno_mensagens_{$dateSuffix}.xlsx";

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
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

    public function getByIds(Request $request)
    {
        $idsParam = (string) $request->input('ids', '');
        $ids = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', $idsParam) ?: [])));
        if (empty($ids)) {
            return response()->json(['items' => []]);
        }
        $items = \App\Models\WhatsappMessageLog::with(['connection'])
            ->whereIn('id', $ids)
            ->orderByDesc('sent_at')
            ->get();
        return response()->json(['items' => $items]);
    }

    protected function buildFilteredQuery(Request $request)
    {
        $filters = $this->extractFilters($request);

        $start = $filters['start_date'] ? Carbon::parse($filters['start_date'])->startOfDay() : null;
        $end = $filters['end_date'] ? Carbon::parse($filters['end_date'])->endOfDay() : ($start ? $start->copy()->endOfDay() : null);

        $query = WhatsappMessageLog::with(['connection', 'cliente', 'user', 'whatsappNumber'])
            ->orderBy('sent_at', 'desc');

        if ($filters['connection_id']) {
            $query->where('connection_id', $filters['connection_id']);
        }
        if ($filters['whatsapp_number_id']) {
            $query->where('whatsapp_number_id', $filters['whatsapp_number_id']);
        }
        if ($start && $end) {
            $query->whereBetween('sent_at', [$start, $end]);
        }
        if ($filters['search']) {
            $query->where(function ($q) use ($filters) {
                $q->where('client_name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('phone_original', 'like', '%'.$filters['search'].'%')
                    ->orWhere('phone_sanitized', 'like', '%'.$filters['search'].'%');
            });
        }
        if ($filters['message_type']) {
            $query->where('message_type', $filters['message_type']);
        }
        if ($filters['provider']) {
            $query->where('provider', $filters['provider']);
        }
        if ($filters['provider_message_id']) {
            $query->where('provider_message_id', $filters['provider_message_id']);
        }
        if ($filters['status']) {
            $query->where('delivery_status', $filters['status']);
        }
        if ($filters['responded'] !== null && $filters['responded'] !== '') {
            $query->where('responded', filter_var($filters['responded'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query;
    }

    protected function extractFilters(Request $request): array
    {
        return [
            'connection_id' => $request->input('connection_id'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'search' => $request->input('search'),
            'whatsapp_number_id' => $request->input('whatsapp_number_id'),
            'status' => $request->input('status'),
            'provider' => $request->input('provider'),
            'message_type' => $request->input('message_type'),
            'responded' => $request->input('responded'),
            'provider_message_id' => $request->input('provider_message_id'),
        ];
    }
}
