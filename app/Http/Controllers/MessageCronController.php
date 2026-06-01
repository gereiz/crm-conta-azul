<?php

namespace App\Http\Controllers;

use App\Models\ContaAzulConnection;
use App\Models\MessageCron;
use App\Models\WhatsappNumber;
use App\Models\WhatsappTemplate;
use App\Services\MessageCronService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

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
            ],
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
            'rule_type' => 'required|in:daily,monthly_day,weekly_day,interval_days',
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
            'send_without_boleto' => 'boolean',
            'no_boleto_template_id' => 'nullable|exists:whatsapp_templates,id',
        ]);

        // Normaliza campos opcionais/booleanos
        $validated['send_without_boleto'] = $request->boolean('send_without_boleto', false);
        $validated['no_boleto_template_id'] = $request->input('no_boleto_template_id') ?: null;
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
            'rule_type' => 'required|in:daily,monthly_day,weekly_day,interval_days',
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
            'send_without_boleto' => 'boolean',
            'no_boleto_template_id' => 'nullable|exists:whatsapp_templates,id',
        ]);

        // Normaliza campos opcionais/booleanos
        $validated['send_without_boleto'] = $request->boolean('send_without_boleto', false);
        $validated['no_boleto_template_id'] = $request->input('no_boleto_template_id') ?: null;

        $cron->update($validated);

        return redirect()->route('settings.crons.index')->with('success', "Automação '{$cron->name}' atualizada com sucesso!");
    }

    public function destroy(MessageCron $cron)
    {
        $cron->delete();

        return redirect()->back()->with('success', 'Automação removida com sucesso!');
    }

    public function runNow(MessageCron $cron, MessageCronService $service)
    {
        $this->authorize('update', $cron);

        $allowed = request()->input('contatos_selecionados');
        $allowedIds = is_array($allowed) ? array_values(array_filter($allowed)) : null;
        $start = now();
        $stats = $service->processCron($cron, true, $allowedIds);
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

    public function preview(MessageCron $cron)
    {
        $this->authorize('update', $cron);
        $today = \Carbon\Carbon::today()->format('Y-m-d');
        $items = [];
        switch ($cron->type) {
            case 'billing':
                $daysLate = max(0, (int) ($cron->days_after_due ?? 0));
                $strictThresholdDays = $daysLate + 1;
                $dueDateLimit = \Carbon\Carbon::today()->subDays($strictThresholdDays)->format('Y-m-d');
                $query = \App\Models\Invoice::where('connection_id', $cron->connection_id)
                    ->where('data_vencimento', '<=', $dueDateLimit)
                    ->where('data_vencimento', '<', $today)
                    ->where('saldo_devedor', '>', 0)
                    ->whereNotIn('status', ['PAID', 'PAGO', 'BAIXADO', 'LIQUIDADO', 'CANCELLED', 'CANCELADO', 'PENDING', 'ABERTO']);
                if (!($cron->send_without_boleto ?? false)) {
                    $query->where(function ($q) {
                        $q->where(function ($qq) {
                            $qq->whereNotNull('payment_type')
                                ->where('payment_type', 'LIKE', '%BOLETO%');
                        })->orWhere(function ($qq) {
                            $qq->whereNotNull('link_boleto')->where('link_boleto', '!=', '');
                        });
                    });
                }
                $query->with('cliente');
                $grouped = $query->get()->groupBy('cliente_id');
                // Fallback: se há faturas sem cliente_id, tenta vincular antes de montar itens
                if ($grouped->has(null)) {
                    $missing = $grouped->get(null);
                    $caIds = $missing->pluck('cliente_ca_id')->filter()->unique()->values();
                    if ($caIds->isNotEmpty()) {
                        $clientMap = \App\Models\Cliente::where('connection_id', $cron->connection_id)
                            ->whereIn('ca_id', $caIds)
                            ->get(['id', 'ca_id'])->keyBy('ca_id');
                        foreach ($missing as $inv) {
                            $c = $clientMap->get($inv->cliente_ca_id);
                            if ($c) {
                                \App\Models\Invoice::where('id', $inv->id)->update(['cliente_id' => $c->id]);
                                $inv->cliente_id = $c->id;
                                $inv->setRelation('cliente', $c);
                            }
                        }
                        $grouped = $query->get()->groupBy('cliente_id');
                    }
                }
                foreach ($grouped as $cid => $clientInvoices) {
                    $c = $clientInvoices->first()->cliente;
                    if (! $c) continue;
                    $items[] = [
                        'id' => $c->id,
                        'name' => $c->name,
                        'phone' => $c->mobile_phone ?? $c->phone,
                        'company' => $c->company_name,
                        'count' => count($clientInvoices),
                    ];
                }
                break;
            case 'boleto':
                $days = (int) ($cron->days_before_due ?? $cron->period_value ?? 0);
                $startDate = $today;
                $endDate = \Carbon\Carbon::now()->addDays($days)->format('Y-m-d');
                $invoices = \App\Models\Invoice::where('status', 'PENDING')
                    ->whereDate('data_vencimento', '>=', $startDate)
                    ->whereDate('data_vencimento', '<=', $endDate)
                    ->where('saldo_devedor', '>', 0)
                    ->whereNotNull('link_boleto')->where('link_boleto', '!=', '')
                    ->when($cron->connection_id, fn($q) => $q->where('connection_id', $cron->connection_id))
                    ->with('cliente')
                    ->get();
                foreach ($invoices as $inv) {
                    if (! $inv->cliente) continue;
                    $items[] = [
                        'id' => $inv->cliente->id,
                        'name' => $inv->cliente->name,
                        'phone' => $inv->cliente->mobile_phone ?? $inv->cliente->phone,
                        'company' => $inv->cliente->company_name,
                        'count' => 1,
                    ];
                }
                break;
            case 'due_date':
                $daysBefore = (int) ($cron->days_before_due ?? 3);
                $targetDue = \Carbon\Carbon::today()->addDays($daysBefore)->format('Y-m-d');
                $invoices = \App\Models\Invoice::where('connection_id', $cron->connection_id)
                    ->where('data_vencimento', '>', $today)
                    ->whereDate('data_vencimento', $targetDue)
                    ->where(function ($q) { $q->whereNull('link_boleto')->orWhere('link_boleto', ''); })
                    ->where(function ($q) {
                        $q->whereIn('status', ['PENDING', 'ABERTO'])
                            ->orWhereNull('status');
                    })
                    ->where('saldo_devedor', '>', 0)
                    ->with('cliente')->get();
                foreach ($invoices as $inv) {
                    if (! $inv->cliente) continue;
                    $items[] = [
                        'id' => $inv->cliente->id,
                        'name' => $inv->cliente->name,
                        'phone' => $inv->cliente->mobile_phone ?? $inv->cliente->phone,
                        'company' => $inv->cliente->company_name,
                        'count' => 1,
                    ];
                }
                break;
            case 'birthday':
                $dayMonth = \Carbon\Carbon::today()->format('m-d');
                $clientes = \App\Models\Cliente::where('connection_id', $cron->connection_id)
                    ->whereRaw("DATE_FORMAT(birthdate, '%m-%d') = ?", [$dayMonth])
                    ->get();
                foreach ($clientes as $c) {
                    $items[] = [
                        'id' => $c->id,
                        'name' => $c->name,
                        'phone' => $c->mobile_phone ?? $c->phone,
                        'company' => $c->company_name,
                        'count' => null,
                    ];
                }
                break;
        }
        return response()->json(['success' => true, 'items' => $items]);
    }
}
