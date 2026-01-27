<?php

namespace App\Http\Controllers;

use App\Models\MessageCron;
use App\Models\ContaAzulConnection;
use App\Models\WhatsappTemplate;
use App\Models\WhatsappNumber;
use App\Services\MessageCronService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class MessageCronController extends Controller
{
    use AuthorizesRequests;

    public function __construct()
    {
        $this->authorizeResource(MessageCron::class, 'cron');
    }

    public function index()
    {
        $crons = MessageCron::with(['messageTemplate', 'whatsappNumber'])->latest()->get();
        return Inertia::render('Settings/Crons/Index', [
            'crons' => $crons,
            'can' => [
                'create' => Auth::user()->can('create', MessageCron::class),
                'update' => Auth::user()->can('create', MessageCron::class), // Policy is same for create/update
                'delete' => Auth::user()->can('create', MessageCron::class), // Policy is same for create/delete
            ]
        ]);
    }

    public function create()
    {
        $templates = WhatsappTemplate::orderBy('name')->get();
        $whatsappNumbers = WhatsappNumber::where('status', 'active')->orderBy('description')->get();
        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();
        return Inertia::render('Settings/Crons/Form', [
            'templates' => $templates,
            'whatsappNumbers' => $whatsappNumbers,
            'connections' => $connections,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'message_template_id' => 'required|exists:whatsapp_templates,id',
            'whatsapp_number_id' => 'required|exists:whatsapp_numbers,id',
            'connection_id' => 'nullable|exists:conta_azul_connections,id',
            'type' => 'required|in:billing,due_date,boleto,birthday',
            'rule_type' => 'nullable|in:daily,monthly_day,weekly_day,interval_days',
            'day_of_month' => 'nullable|array',
            'day_of_month.*' => 'integer|min:1|max:31',
            'day_of_week' => 'nullable|array',
            'day_of_week.*' => 'integer|min:0|max:6',
            'interval_days' => 'nullable|integer|min:1',
            'exclude_weekends' => 'boolean',
            'period_value' => 'nullable|integer',
            'period_unit' => 'nullable|in:days,months,years',
            'days_before_due' => 'nullable|integer',
            'days_after_due' => 'nullable|integer',
            'send_time' => 'required|date_format:H:i',
            'is_active' => 'boolean',
            'limit_link_preview' => 'boolean',
            'disable_link_preview' => 'boolean',
            'run_when_delayed' => 'boolean',
        ]);

        $validated['created_by'] = Auth::id();

        MessageCron::create($validated);

        return redirect()->route('settings.crons.index')->with('success', 'Automação criada com sucesso!');
    }

    public function edit(MessageCron $cron)
    {
        $templates = WhatsappTemplate::orderBy('name')->get();
        $whatsappNumbers = WhatsappNumber::where('status', 'active')->orderBy('description')->get();
        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();
        return Inertia::render('Settings/Crons/Form', [
            'cron' => $cron,
            'templates' => $templates,
            'whatsappNumbers' => $whatsappNumbers,
            'connections' => $connections,
        ]);
    }

    public function update(Request $request, MessageCron $cron)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'message_template_id' => 'required|exists:whatsapp_templates,id',
            'whatsapp_number_id' => 'required|exists:whatsapp_numbers,id',
            'connection_id' => 'nullable|exists:conta_azul_connections,id',
            'type' => 'required|in:billing,due_date,boleto,birthday',
            'rule_type' => 'nullable|in:daily,monthly_day,weekly_day,interval_days',
            'day_of_month' => 'nullable|array',
            'day_of_month.*' => 'integer|min:1|max:31',
            'day_of_week' => 'nullable|array',
            'day_of_week.*' => 'integer|min:0|max:6',
            'interval_days' => 'nullable|integer|min:1',
            'exclude_weekends' => 'boolean',
            'period_value' => 'nullable|integer',
            'period_unit' => 'nullable|in:days,months,years',
            'days_before_due' => 'nullable|integer',
            'days_after_due' => 'nullable|integer',
            'send_time' => 'required|date_format:H:i',
            'is_active' => 'boolean',
            'limit_link_preview' => 'boolean',
            'disable_link_preview' => 'boolean',
            'run_when_delayed' => 'boolean',
        ]);

        $cron->update($validated);

        return redirect()->route('settings.crons.index')->with('success', 'Automação atualizada com sucesso!');
    }

    public function destroy(MessageCron $cron)
    {
        $cron->delete();
        return redirect()->back()->with('success', 'Automação removida com sucesso!');
    }

    public function runNow(MessageCron $cron, MessageCronService $service)
    {
        $this->authorize('update', $cron);

        $start = now();
        $stats = $service->processCron($cron);
        $logs = \App\Models\WhatsappMessageLog::where('message_cron_id', $cron->id)
            ->where('sent_at', '>=', $start)
            ->orderBy('sent_at', 'desc')
            ->get(['client_name', 'phone_original', 'phone_sanitized', 'status', 'error_message', 'sent_at']);
        $report = [
            'stats' => $stats,
            'logs' => $logs->map(function ($l) {
                return [
                    'client_name' => $l->client_name,
                    'phone' => $l->phone_sanitized ?? $l->phone_original,
                    'status' => $l->status,
                    'error_message' => $l->error_message,
                    'sent_at' => $l->sent_at?->format('d/m/Y H:i'),
                ];
            }),
        ];

        $message = "Execução finalizada. Enviadas: {$stats['sent']}, Erros: {$stats['errors']}, Ignoradas: {$stats['skipped']}.";
        
        return redirect()->back()->with('success', $message)->with('cron_report', $report);
    }
}
