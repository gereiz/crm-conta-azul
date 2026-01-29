<?php

namespace App\Http\Controllers;

use App\Models\CompanyCronRule;
use App\Models\CompanyMessageSetting;
use App\Models\ContaAzulConnection;
use App\Services\ContaAzulService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmpresaController extends Controller
{
    protected $contaAzulService;

    public function __construct(ContaAzulService $contaAzulService)
    {
        $this->contaAzulService = $contaAzulService;
    }

    public function index(Request $request)
    {
        $search = $request->input('search');
        $size = $request->input('size', 10);

        $connections = ContaAzulConnection::query()
            ->when($search, fn ($q) => $q->where('empresa_nome', 'like', "%{$search}%"))
            ->orderBy('empresa_nome')
            ->paginate($size)
            ->withQueryString();

        return Inertia::render('Empresas/Index', [
            'connections' => $connections,
            'filters' => $request->only(['search', 'size']),
        ]);
    }

    public function show($id)
    {
        $connection = ContaAzulConnection::find($id);

        if (! $connection) {
            return redirect()->route('empresas.index')->with('error', 'Empresa não encontrada.');
        }

        $messageTypes = [
            ['key' => 'billing', 'label' => 'Cobrança (Atraso)'],
            ['key' => 'due_date', 'label' => 'Aviso de Vencimento'],
            ['key' => 'boleto', 'label' => 'Emissão de Boleto'],
            ['key' => 'birthday', 'label' => 'Aniversariantes'],
        ];

        $settings = CompanyMessageSetting::where('conta_azul_connection_id', $connection->id)->get();
        $messageSettings = [];
        foreach ($messageTypes as $t) {
            $found = $settings->firstWhere('message_type', $t['key']);
            $messageSettings[$t['key']] = (bool) ($found?->is_enabled ?? false);
        }
        // Flag global: ignorar verificação de "já enviado hoje"
        $ignoreFlag = $settings->firstWhere('message_type', 'ignore_sent_today');
        $messageSettings['ignore_sent_today'] = (bool) ($ignoreFlag?->is_enabled ?? false);
        // Flag global: limitar preview de links
        $limitPreviewFlag = $settings->firstWhere('message_type', 'limit_link_preview');
        $messageSettings['limit_link_preview'] = (bool) ($limitPreviewFlag?->is_enabled ?? false);
        $disablePreviewFlag = $settings->firstWhere('message_type', 'disable_link_preview');
        $messageSettings['disable_link_preview'] = (bool) ($disablePreviewFlag?->is_enabled ?? false);

        $specificRules = CompanyCronRule::where('conta_azul_connection_id', $connection->id)->get();
        $globalRules = CompanyCronRule::whereNull('conta_azul_connection_id')->get();

        $cronRules = [];
        foreach ($messageTypes as $t) {
            $found = $specificRules->firstWhere('message_type', $t['key']);
            $isGlobal = false;

            if (! $found) {
                $found = $globalRules->firstWhere('message_type', $t['key']);
                $isGlobal = (bool) $found;
            }

            $cronRules[$t['key']] = $found ? [
                'rule_type' => $found->rule_type,
                'day_of_month' => $found->day_of_month,
                'day_of_week' => $found->day_of_week,
                'interval_days' => $found->interval_days,
                'exclude_weekends' => $found->exclude_weekends,
                'is_active' => $found->is_active,
                'is_global' => $isGlobal,
            ] : null;
        }

        return Inertia::render('Empresas/Show', [
            'connection' => $connection,
            'messageTypes' => $messageTypes,
            'messageSettings' => $messageSettings,
            'cronRules' => $cronRules,
        ]);
    }

    public function updateMessageSettings(Request $request, ContaAzulConnection $connection)
    {
        $data = $request->validate([
            'type' => 'required|string|in:billing,due_date,boleto,birthday,ignore_sent_today,limit_link_preview,disable_link_preview',
            'enabled' => 'required|boolean',
        ]);

        CompanyMessageSetting::updateOrCreate(
            [
                'conta_azul_connection_id' => $connection->id,
                'message_type' => $data['type'],
            ],
            [
                'is_enabled' => $data['enabled'],
            ]
        );

        return redirect()->back()->with('success', 'Configuração de mensagem atualizada.');
    }

    public function saveCronRule(Request $request, ContaAzulConnection $connection)
    {
        $data = $request->validate([
            'message_type' => 'required|string|in:billing,due_date,boleto,birthday',
            'rule_type' => 'required|string|in:monthly_day,weekly_day,interval_days',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'interval_days' => 'nullable|integer|min:1|max:365',
            'exclude_weekends' => 'boolean',
            'is_active' => 'boolean',
            'apply_to_all' => 'boolean',
        ]);

        $payload = [
            'rule_type' => $data['rule_type'],
            'day_of_month' => $data['day_of_month'] ?? null,
            'day_of_week' => $data['day_of_week'] ?? null,
            'interval_days' => $data['interval_days'] ?? null,
            'exclude_weekends' => (bool) ($data['exclude_weekends'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'message_type' => $data['message_type'],
        ];

        if ($request->boolean('apply_to_all')) {
            // Salva como regra global (para todas as empresas)
            CompanyCronRule::updateOrCreate(
                [
                    'conta_azul_connection_id' => null,
                    'message_type' => $data['message_type'],
                ],
                $payload
            );
            $msg = 'Regra salva para TODAS as empresas.';
        } else {
            // Salva regra específica para esta empresa
            CompanyCronRule::updateOrCreate(
                [
                    'conta_azul_connection_id' => $connection->id,
                    'message_type' => $data['message_type'],
                ],
                $payload
            );
            $msg = 'Regra salva com sucesso.';
        }

        return redirect()->back()->with('success', $msg);
    }
}
