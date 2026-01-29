<?php

namespace App\Http\Controllers;

use App\Models\ContaAzulConnection;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\User;
use App\Models\WhatsappNumber;
use App\Services\ContaAzulApiService;
use App\Services\ContaAzulAuthService;
use App\Services\ContaAzulService;
use App\Services\EvolutionWhatsAppService;
use App\Services\WhapiService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class DashboardController extends Controller
{
    protected $contaAzulService;

    protected $whapiService;

    protected $contaAzulAuthService;

    protected $contaAzulApiService;

    protected $evolutionService;

    public function __construct(ContaAzulService $contaAzulService, WhapiService $whapiService, ContaAzulAuthService $contaAzulAuthService, ContaAzulApiService $contaAzulApiService, EvolutionWhatsAppService $evolutionService)
    {
        $this->contaAzulService = $contaAzulService;
        $this->whapiService = $whapiService;
        $this->contaAzulAuthService = $contaAzulAuthService;
        $this->contaAzulApiService = $contaAzulApiService;
        $this->evolutionService = $evolutionService;
    }

    public function checkWhapiHealth()
    {
        $health = $this->whapiService->checkHealth();

        return response()->json($health);
    }

    public function checkEvolutionHealth(Request $request)
    {
        try {
            $numberId = $request->input('whatsapp_number_id');
            $query = WhatsappNumber::where('provider', 'evolution')->where('status', 'active');
            if ($numberId) {
                $whatsapp = $query->where('id', $numberId)->first();
            } else {
                $whatsapp = $query->first();
            }

            if (! $whatsapp) {
                return response()->json(['status' => 'down', 'error' => 'Nenhum número Evolution ativo configurado.']);
            }

            $result = $this->evolutionService->checkConnection($whatsapp);
            if (($result['connected'] ?? false) === true) {
                return response()->json([
                    'status' => 'operational',
                    'details' => array_merge($result, [
                        'number' => $whatsapp->description,
                        'instance' => $whatsapp->provider_instance,
                    ]),
                ]);
            }

            return response()->json([
                'status' => 'down',
                'error' => $result['error'] ?? 'Desconectado',
                'details' => array_merge($result, [
                    'number' => $whatsapp->description,
                    'instance' => $whatsapp->provider_instance,
                ]),
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao verificar saúde Evolution: '.$e->getMessage());

            return response()->json(['status' => 'down', 'error' => $e->getMessage()]);
        }
    }

    public function evolutionQr(Request $request)
    {
        try {
            $numberId = $request->input('whatsapp_number_id');
            $query = WhatsappNumber::where('provider', 'evolution')->where('status', 'active');
            if ($numberId) {
                $whatsapp = $query->where('id', $numberId)->first();
            } else {
                $whatsapp = $query->first();
            }
            if (! $whatsapp) {
                return response()->json(['success' => false, 'error' => 'Nenhum número Evolution ativo configurado.'], 404);
            }
            $qr = $this->evolutionService->getQrCode($whatsapp);
            if (($qr['success'] ?? false) === true) {
                return response()->json(['success' => true, 'qr' => $qr]);
            }

            return response()->json(['success' => false, 'error' => $qr['error'] ?? 'QR não disponível'], 400);
        } catch (\Exception $e) {
            Log::error('Erro ao obter QR Evolution: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function syncFinancials(Request $request)
    {
        try {
            $connectionId = $request->input('connection_id');
            $count = 0;
            if ($connectionId) {
                $connection = ContaAzulConnection::find($connectionId);
                if ($connection) {
                    $count = $this->contaAzulApiService->syncOverdueInvoices($connection);
                }
            } else {
                $count = $this->contaAzulService->syncOverdueInvoices();
            }

            // Recalcula totais baseados no banco
            $overdueCount = \App\Models\Invoice::when($connectionId, fn ($q) => $q->where('connection_id', $connectionId))->count();
            $overdueValue = \App\Models\Invoice::when($connectionId, fn ($q) => $q->where('connection_id', $connectionId))->sum('saldo_devedor');

            Log::info('Dashboard sync completed', [
                'count_synced' => $count,
                'overdue_count' => $overdueCount,
                'overdue_value' => $overdueValue,
            ]);

            return response()->json([
                'success' => true,
                'count' => $count,
                'stats' => [
                    'cobrancas_overdue_count' => $overdueCount,
                    'cobrancas_overdue_value' => $overdueValue,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao sincronizar financeiro no dashboard: '.$e->getMessage());

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function chartData(Request $request)
    {
        $periodDays = (int) $request->input('period', 7);
        $allowedPeriods = [7, 15, 30];
        if (! in_array($periodDays, $allowedPeriods)) {
            $periodDays = 7;
        }

        $endDate = Carbon::today();
        $startDate = Carbon::today()->subDays($periodDays - 1);

        $logs = \App\Models\WhatsappMessageLog::with(['messageCron.connection', 'connection'])
            ->whereBetween('sent_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->where('status', 'success')
            ->get();

        $chartData = [
            'labels' => [],
            'datasets' => [],
        ];

        $period = \Carbon\CarbonPeriod::create($startDate, $endDate);
        foreach ($period as $date) {
            $chartData['labels'][] = $date->format('d/m');
        }

        $connections = ContaAzulConnection::all();
        $companiesData = [];

        foreach ($logs as $log) {
            $dateKey = $log->sent_at ? Carbon::parse($log->sent_at)->format('d/m') : null;
            if (! $dateKey) {
                continue;
            }

            $companyName = 'Desconhecida';

            // 1. Tenta pegar da conexão associada ao log (se existir)
            if ($log->connection) {
                $companyName = $log->connection->empresa_nome;
            }
            // 2. Se não tiver conexão direta, tenta via MessageCron (se houver relação)
            elseif ($log->messageCron && $log->messageCron->connection) {
                $companyName = $log->messageCron->connection->empresa_nome;
            }
            // 3. Tenta via ID da conexão salvo no log (fallback)
            elseif ($log->connection_id) {
                $c = $connections->firstWhere('id', $log->connection_id);
                $companyName = $c ? $c->empresa_nome : 'Empresa ID '.$log->connection_id;
            }

            if (! isset($companiesData[$companyName])) {
                $companiesData[$companyName] = array_fill_keys($chartData['labels'], 0);
            }

            $companiesData[$companyName][$dateKey]++;
        }

        $colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#6366F1'];
        $colorIndex = 0;

        foreach ($companiesData as $companyName => $dataByDay) {
            $chartData['datasets'][] = [
                'label' => $companyName,
                'data' => array_values($dataByDay),
                'backgroundColor' => $colors[$colorIndex % count($colors)],
            ];
            $colorIndex++;
        }

        return response()->json([
            'success' => true,
            'chartData' => $chartData,
        ]);
    }

    public function chartDataWhatsapp(Request $request)
    {
        $periodDays = (int) $request->input('period', 7);
        $allowedPeriods = [7, 15, 30];
        if (! in_array($periodDays, $allowedPeriods)) {
            $periodDays = 7;
        }
        $endDate = Carbon::today();
        $startDate = Carbon::today()->subDays($periodDays - 1);
        $logs = \App\Models\WhatsappMessageLog::with(['messageCron', 'messageCron.whatsappNumber'])
            ->whereBetween('sent_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->where('status', 'success')
            ->get();
        $labels = [];
        $period = \Carbon\CarbonPeriod::create($startDate, $endDate);
        foreach ($period as $date) {
            $labels[] = $date->format('d/m');
        }
        $palette = [
            'billing' => '#3B82F6',
            'due_date' => '#F59E0B',
            'boleto' => '#10B981',
            'birthday' => '#8B5CF6',
            'default' => '#6366F1',
        ];
        $datasets = [];
        foreach ($logs as $log) {
            $dateKey = $log->sent_at ? Carbon::parse($log->sent_at)->format('d/m') : null;
            if (! $dateKey) {
                continue;
            }
            $numberDesc = $log->messageCron && $log->messageCron->whatsappNumber ? ($log->messageCron->whatsappNumber->description ?? 'Número') : 'Número';
            $type = $log->message_type ?? ($log->messageCron ? $log->messageCron->type : 'default');
            $key = $numberDesc.' | '.$type;
            if (! isset($datasets[$key])) {
                $datasets[$key] = [
                    'label' => $key,
                    'data' => array_fill_keys($labels, 0),
                    'backgroundColor' => $palette[$type] ?? $palette['default'],
                ];
            }
            $datasets[$key]['data'][$dateKey] = ($datasets[$key]['data'][$dateKey] ?? 0) + 1;
        }
        $chartData = [
            'labels' => $labels,
            'datasets' => array_values(array_map(function ($ds) {
                $ds['data'] = array_values($ds['data']);

                return $ds;
            }, $datasets)),
        ];

        return response()->json([
            'success' => true,
            'chartData' => $chartData,
        ]);
    }

    public function index(Request $request)
    {
        $selectedConnectionId = $request->input('connection_id') ?? session('dashboard_connection_id');
        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();
        if (! $selectedConnectionId && $connections->isNotEmpty()) {
            $selectedConnectionId = $connections->first()->id;
        }
        session(['dashboard_connection_id' => $selectedConnectionId]);

        $contaAzulConnected = false;
        if ($selectedConnectionId) {
            $connection = $connections->firstWhere('id', $selectedConnectionId);
            if ($connection) {
                $contaAzulConnected = $this->contaAzulAuthService->getValidToken($connection) !== null;
            }
        } else {
            $contaAzulConnected = $this->contaAzulService->getValidToken() !== null;
        }

        $overdueCount = 0;
        $overdueValue = 0.0;

        if ($contaAzulConnected) {
            try {
                // Usar cache local para performance
                // O front-end dispara a sincronização (syncFinancials) logo após montar
                // A tabela invoices contém apenas faturas atrasadas (filtradas por data no sync)
                $overdueCount = Invoice::when($selectedConnectionId, fn ($q) => $q->where('connection_id', $selectedConnectionId))->count();
                $overdueValue = Invoice::when($selectedConnectionId, fn ($q) => $q->where('connection_id', $selectedConnectionId))->sum('saldo_devedor');
            } catch (\Exception $e) {
                Log::error('Erro ao buscar cobranças para dashboard: '.$e->getMessage());
            }
        }

        $stats = [
            'conta_azul_connected' => $contaAzulConnected,
            'whatsapp_numbers_active' => WhatsappNumber::where('status', 'active')->count(),
            'messages_sent' => MessageLog::where('status', 'success')->count(),
            'users_total' => User::count(),
            'cobrancas_overdue_count' => $overdueCount,
            'cobrancas_overdue_value' => $overdueValue,
        ];

        // Verifica se deve sincronizar (apenas na primeira visita da sessão)
        $shouldSync = ! session()->has('dashboard_synced');
        if ($shouldSync) {
            session(['dashboard_synced' => true]);
        }

        $chartRequest = new Request(['period' => 7]);
        $initialChartData = $this->chartData($chartRequest)->getData()->chartData;

        $evolutionNumbers = WhatsappNumber::where('provider', 'evolution')->orderBy('description')->get(['id', 'description', 'provider_instance', 'status']);

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'shouldSync' => $shouldSync,
            'connections' => $connections,
            'selectedConnectionId' => $selectedConnectionId,
            'chartData' => $initialChartData,
            'evolutionNumbers' => $evolutionNumbers,
        ]);
    }

    public function selectConnection(Request $request)
    {
        $connectionId = $request->input('connection_id');
        if (! $connectionId) {
            return response()->json(['success' => false, 'error' => 'connection_id obrigatório'], 422);
        }
        $connection = ContaAzulConnection::find($connectionId);
        if (! $connection) {
            return response()->json(['success' => false, 'error' => 'Conexão não encontrada'], 404);
        }
        session(['dashboard_connection_id' => $connectionId]);
        $connected = $this->contaAzulAuthService->getValidToken($connection) !== null;

        return response()->json(['success' => true, 'conta_azul_connected' => $connected]);
    }

    public function stats(Request $request)
    {
        $connectionId = $request->input('connection_id');
        $connected = false;
        if ($connectionId) {
            $connection = ContaAzulConnection::find($connectionId);
            if ($connection) {
                $connected = $this->contaAzulAuthService->getValidToken($connection) !== null;
            }
        } else {
            $connected = $this->contaAzulService->getValidToken() !== null;
        }
        $overdueCount = \App\Models\Invoice::when($connectionId, fn ($q) => $q->where('connection_id', $connectionId))->count();
        $overdueValue = \App\Models\Invoice::when($connectionId, fn ($q) => $q->where('connection_id', $connectionId))->sum('saldo_devedor');

        return response()->json([
            'success' => true,
            'stats' => [
                'conta_azul_connected' => $connected,
                'cobrancas_overdue_count' => $overdueCount,
                'cobrancas_overdue_value' => $overdueValue,
            ],
        ]);
    }
}
