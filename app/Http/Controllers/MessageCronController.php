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
        $crons = MessageCron::with('messageTemplate')->latest()->get();
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
            'period_value' => 'nullable|integer',
            'period_unit' => 'nullable|in:days,months,years',
            'days_before_due' => 'nullable|integer',
            'days_after_due' => 'nullable|integer',
            'send_time' => 'required|date_format:H:i',
            'is_active' => 'boolean',
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
            'period_value' => 'nullable|integer',
            'period_unit' => 'nullable|in:days,months,years',
            'days_before_due' => 'nullable|integer',
            'days_after_due' => 'nullable|integer',
            'send_time' => 'required|date_format:H:i',
            'is_active' => 'boolean',
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

        $stats = $service->processCron($cron);

        $message = "Execução finalizada. Enviadas: {$stats['sent']}, Erros: {$stats['errors']}, Ignoradas: {$stats['skipped']}.";
        
        return redirect()->back()->with('success', $message);
    }
}
