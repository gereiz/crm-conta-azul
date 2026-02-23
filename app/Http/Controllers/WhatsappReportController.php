<?php

namespace App\Http\Controllers;

use App\Models\ContaAzulConnection;
use App\Models\WhatsappMessageLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WhatsappReportController extends Controller
{
    public function index()
    {
        $connectionId = request()->input('connection_id');
        $startDate = request()->input('start_date');
        $endDate = request()->input('end_date');
        $search = request()->input('search');
        $type = request()->input('type'); // billing | boleto | due_date
        $status = request()->input('status');

        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : ($start ? $start->copy()->endOfDay() : null);

        $query = WhatsappMessageLog::with(['connection', 'template', 'cliente', 'user', 'whatsappNumber'])
            ->orderBy('sent_at', 'desc');

        if ($connectionId) {
            $query->where('connection_id', $connectionId);
        }

        if ($start && $end) {
            $query->whereBetween('sent_at', [$start, $end]);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('client_name', 'like', "%{$search}%")
                    ->orWhere('phone_original', 'like', "%{$search}%")
                    ->orWhere('phone_sanitized', 'like', "%{$search}%");
            });
        }
        if ($type && in_array($type, ['billing', 'boleto', 'due_date'])) {
            $query->where('message_type', $type);
        }
        $baseForStatuses = clone $query;
        // Remove ORDER BY para evitar erro "ORDER BY não está no SELECT" com DISTINCT (MySQL 3065)
        $baseForStatuses->reorder();
        $statuses = $baseForStatuses->select('status')->distinct()->orderBy('status')->pluck('status')->filter()->values();
        if ($status && in_array($status, $statuses->all())) {
            $query->where('status', $status);
        }

        $reports = $query->paginate(20)->withQueryString();

        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();

        return Inertia::render('WhatsApp/Reports/Index', [
            'reports' => $reports,
            'connections' => $connections,
            'selectedConnectionId' => $connectionId,
            'selectedStartDate' => $startDate,
            'selectedEndDate' => $endDate,
            'search' => $search,
            'selectedType' => $type,
            'statuses' => $statuses,
            'selectedStatus' => $status,
        ]);
    }

    public function download($id)
    {
        $log = WhatsappMessageLog::with(['connection', 'template'])->findOrFail($id);

        $date = Carbon::parse($log->sent_at);
        $filenameDate = $date->format('d-m-Y');
        $safeClientName = Str::slug($log->client_name ?? 'desconhecido', '_');
        $filename = "log_whatsapp_{$filenameDate}_{$safeClientName}.xlsx";

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['Empresa', 'Cliente', 'Telefone Original', 'Telefone Sanitizado', 'Provider', 'Número WhatsApp', 'Tipo', 'Template', 'Boletos', 'Status', 'Erro', 'Enviado Em', 'Conteúdo'];
        $sheet->fromArray([$headers], null, 'A1');

        // Data
        $number = $log->whatsappNumber;
        $numberDisplay = $number ? trim(($number->ddi ?? '').' '.($number->ddd ?? '').' '.($number->phone ?? '')) : 'N/A';
        $rowData = [
            $log->connection->empresa_nome ?? 'N/A',
            $log->client_name,
            $log->phone_original,
            $log->phone_sanitized,
            $log->provider ?? 'N/A',
            $numberDisplay,
            $log->message_type,
            $log->template->name ?? 'N/A',
            $log->total_boletos,
            $log->status,
            $log->error_message,
            $date->format('d/m/Y H:i:s'),
            $log->content,
        ];
        $sheet->fromArray([$rowData], null, 'A2');

        foreach (range('A', 'M') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function downloadGroupedAutomations(Request $request)
    {
        $dateParam = $request->input('date');
        $startParam = $request->input('start_date');
        $endParam = $request->input('end_date');
        $connectionId = $request->input('connection_id');
        $search = $request->input('search');
        $type = $request->input('type');
        $status = $request->input('status');

        if ($startParam || $endParam) {
            $start = $startParam ? Carbon::parse($startParam)->startOfDay() : Carbon::today()->startOfDay();
            $end = $endParam ? Carbon::parse($endParam)->endOfDay() : ($start ? $start->copy()->endOfDay() : Carbon::today()->endOfDay());
        } else {
            $date = $dateParam ? Carbon::parse($dateParam) : Carbon::today();
            $start = $date->copy()->startOfDay();
            $end = $date->copy()->endOfDay();
        }

        $query = WhatsappMessageLog::with(['connection', 'template'])
            ->whereBetween('sent_at', [$start, $end]);
        if ($connectionId) {
            $query->where('connection_id', $connectionId);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('client_name', 'like', "%{$search}%")
                    ->orWhere('phone_original', 'like', "%{$search}%")
                    ->orWhere('phone_sanitized', 'like', "%{$search}%");
            });
        }
        if ($type && in_array($type, ['billing', 'boleto', 'due_date'])) {
            $query->where('message_type', $type);
        }
        if ($status) {
            $query->where('status', $status);
        }
        $logs = $query->orderBy('sent_at', 'asc')->get();

        $groups = $logs->groupBy('connection_id');

        $spreadsheet = new Spreadsheet;
        $first = true;

        foreach ($groups as $connectionId => $groupLogs) {
            $companyName = $groupLogs->first()->connection->empresa_nome ?? 'Sem Empresa';
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;
            $sheet->setTitle(Str::substr(Str::slug($companyName), 0, 31));

            $headers = ['Cliente', 'Telefone Original', 'Telefone Sanitizado', 'Provider', 'Número WhatsApp', 'Tipo', 'Template', 'Boletos', 'Status', 'Erro', 'Enviado Em', 'Conteúdo'];
            $sheet->fromArray([$headers], null, 'A1');

            $row = 2;
            foreach ($groupLogs as $log) {
                $num = $log->whatsappNumber;
                $numDisplay = $num ? trim(($num->ddi ?? '').' '.($num->ddd ?? '').' '.($num->phone ?? '')) : 'N/A';
                $rowData = [
                    $log->client_name,
                    $log->phone_original,
                    $log->phone_sanitized,
                    $log->provider ?? 'N/A',
                    $numDisplay,
                    $log->message_type,
                    $log->template->name ?? 'N/A',
                    $log->total_boletos,
                    $log->status,
                    $log->error_message,
                    Carbon::parse($log->sent_at)->format('d/m/Y H:i:s'),
                    $log->content,
                ];
                $sheet->fromArray([$rowData], null, 'A'.$row);
                $row++;
            }

            foreach (range('A', 'L') as $columnID) {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }
        }

        $filename = 'relatorio_whatsapp_agrupado_'.$start->format('d-m-Y').'.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
