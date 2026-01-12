<?php

namespace App\Http\Controllers;

use App\Models\MessageCronLog;
use App\Models\MessageLog;
use App\Models\ContaAzulConnection;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WhatsappReportController extends Controller
{
    public function index()
    {
        $connectionId = request()->input('connection_id');

        // Manual Logs (associados via telefone ao cliente)
        $manual = DB::table('message_logs')
            ->leftJoin('clientes', 'message_logs.to', '=', 'clientes.mobile_phone')
            ->select(
                'message_logs.id',
                DB::raw("'manual' as type"),
                'message_logs.created_at as date',
                'message_logs.to as recipient_phone',
                'message_logs.status',
                'clientes.name as client_name',
                DB::raw("NULL as cron_name"),
                DB::raw("clientes.connection_id as connection_id")
            )
            ->when($connectionId, function ($q) use ($connectionId) {
                // Filtra apenas logs que conseguiram associar com cliente da empresa selecionada
                return $q->where('clientes.connection_id', $connectionId);
            });

        // Cron Logs (associados ao cliente por ID)
        $cron = DB::table('message_cron_logs')
            ->join('message_crons', 'message_cron_logs.message_cron_id', '=', 'message_crons.id')
            ->leftJoin('clientes', 'message_cron_logs.cliente_id', '=', 'clientes.id')
            ->select(
                'message_cron_logs.id',
                DB::raw("'cron' as type"),
                'message_cron_logs.sent_at as date',
                'message_cron_logs.phone as recipient_phone',
                'message_cron_logs.status',
                DB::raw("COALESCE(message_cron_logs.client_name, clientes.name) as client_name"),
                'message_crons.name as cron_name',
                DB::raw("message_crons.connection_id as connection_id")
            )
            ->when($connectionId, function ($q) use ($connectionId) {
                // Filtra por empresa usando o cliente associado
                return $q->where('clientes.connection_id', $connectionId);
            });

        // Union and paginate
        $reports = $manual->union($cron)
            ->orderBy('date', 'desc')
            ->paginate(20);

        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();

        return Inertia::render('WhatsApp/Reports/Index', [
            'reports' => $reports,
            'connections' => $connections,
            'selectedConnectionId' => $connectionId,
        ]);
    }

    public function download($type, $id)
    {
        if ($type === 'manual') {
            $log = MessageLog::find($id);
            if (!$log) abort(404);

            // Try to find client name
            $client = Cliente::where('mobile_phone', $log->to)->first();
            $clientName = $client ? $client->name : 'Desconhecido';
            $date = Carbon::parse($log->created_at);
            
            $filenameDate = $date->format('d-m-Y');
            $safeClientName = Str::slug($clientName, '_');
            $filename = "envio_manual_{$filenameDate}_{$safeClientName}.xlsx";

            $data = [
                'contact' => $log->to,
                'client' => $clientName,
                'status' => $log->status,
                'message' => $log->content,
                'sentAt' => $date->format('d/m/Y H:i:s'),
                'messageId' => $log->message_id,
                'whatsappNumber' => $log->whatsapp_number_id,
            ];

        } elseif ($type === 'cron') {
            $log = MessageCronLog::with('messageCron')->find($id);
            if (!$log) abort(404);

            $clientName = $log->client_name ?? 'Desconhecido';
            $companyName = $log->messageCron->name ?? 'Automacao'; // Using Cron Name as "Company/Entity"
            
            $date = Carbon::parse($log->sent_at);
            $filenameDate = $date->format('d-m-Y');
            $safeCompanyName = Str::slug($companyName, '_');
            
            $filename = "envio_manual_{$filenameDate}_{$safeCompanyName}.xlsx";

            $data = [
                'contact' => $log->phone,
                'client' => $clientName,
                'status' => $log->status,
                'message' => 'Template: ' . ($log->messageCron->messageTemplate->name ?? 'N/A'),
                'sentAt' => $date->format('d/m/Y H:i:s'),
                'messageId' => null,
                'whatsappNumber' => $log->messageCron->whatsapp_number_id,
            ];
        } else {
            abort(404);
        }

        // Generate XLSX content
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $headers = ['Contato', 'Cliente', 'Status', 'Mensagem', 'Enviado Em', 'ID Mensagem', 'WhatsApp ID'];
        $sheet->fromArray([$headers], NULL, 'A1');

        // Data
        $rowData = [
            $data['contact'],
            $data['client'],
            $data['status'],
            $data['message'],
            $data['sentAt'],
            $data['messageId'],
            $data['whatsappNumber']
        ];
        $sheet->fromArray([$rowData], NULL, 'A2');

        // Auto size columns
        foreach (range('A', 'G') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function() use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
