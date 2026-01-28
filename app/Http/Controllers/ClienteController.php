<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\WhatsappNumber;
use App\Models\WhatsappTemplate;
use App\Services\ContaAzulService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClienteController extends Controller
{
    protected $contaAzulService;

    public function __construct(ContaAzulService $contaAzulService)
    {
        $this->contaAzulService = $contaAzulService;
    }

    public function index(Request $request)
    {
        $search = $request->input('search');
        $size = $request->input('size', 20);

        $query = Cliente::query();

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('cpf_cnpj', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        // Ordenação
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction', 'asc');

        // Validar colunas permitidas para evitar SQL Injection indireta ou erros
        $allowedSorts = ['name', 'email', 'company_name', 'cpf_cnpj', 'mobile_phone'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'name';
        }
        if (!in_array(strtolower($direction), ['asc', 'desc'])) {
            $direction = 'asc';
        }

        $query->orderBy($sort, $direction);

        $clientes = $query->paginate($size)->withQueryString();

        return Inertia::render('Clientes/Index', [
            'clientes' => $clientes,
            'filters' => array_merge($request->only(['search', 'size']), [
                'sort' => $sort,
                'direction' => $direction,
            ]),
        ]);
    }

    public function show($id)
    {
        // $id pode ser o ID local ou o UUID da CA (se vindo de URL antiga)
        // Vamos tentar achar pelo ID local primeiro
        $clienteLocal = Cliente::find($id);
        
        if (!$clienteLocal) {
             // Tenta buscar por ca_id
             $clienteLocal = Cliente::where('ca_id', $id)->first();
        }

        if (!$clienteLocal) {
             return redirect()->route('clientes.index')->with('error', 'Cliente não encontrado na base local.');
        }

        // Buscar detalhes atualizados na API usando o ca_id
        $caId = $clienteLocal->ca_id;
        $clienteApi = $this->contaAzulService->request('get', "pessoas/{$caId}");

        // Se a API falhar, usamos os dados locais
        $cliente = $clienteApi ?? $clienteLocal->toArray();
        
        // Garante que o ID no objeto cliente seja o ID local para links internos funcionarem, se necessário
        // Mas a view Show provavelmente usa dados da API.
        // Vamos mesclar dados locais com dados da API para garantir
        if ($clienteApi) {
             $cliente['local_id'] = $clienteLocal->id;
             $cliente['company_name'] = $clienteLocal->company_name;
        }

        // Buscar faturas atrasadas do cliente (usando cache local)
        // $invoices = $this->contaAzulService->getCustomerInvoices($caId);
        $invoices = \App\Models\Invoice::where('cliente_ca_id', $caId)
                    ->orderBy('data_vencimento', 'desc')
                    ->get();

        $whatsappNumbers = WhatsappNumber::where('status', 'active')->get();
        $templates = WhatsappTemplate::orderBy('is_default', 'desc')->orderBy('name')->get();

        return Inertia::render('Clientes/Show', [
            'cliente' => $cliente,
            'invoices' => $invoices,
            'whatsappNumbers' => $whatsappNumbers,
            'templates' => $templates,
        ]);
    }

    public function overdueInvoices(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $search = $request->input('search');
        $paymentType = $request->input('payment_type');
        $connectionId = $request->input('connection_id');

        $query = \App\Models\Invoice::with('cliente');

        if ($startDate && $endDate) {
            $query->whereBetween('data_vencimento', [$startDate, $endDate]);
        }
        
        if ($search) {
             $query->where(function($q) use ($search) {
                 $q->whereHas('cliente', function($qc) use ($search) {
                     $qc->where('name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                 })
                 ->orWhere('cliente_nome', 'like', "%{$search}%");
             });
        }

        if ($paymentType) {
            $query->where('payment_type', $paymentType);
        }
        
        if ($connectionId) {
            $query->where('connection_id', $connectionId);
        }

        $invoices = $query->orderBy('data_vencimento', 'asc')->paginate(20)->withQueryString();
        
        $whatsappNumbers = WhatsappNumber::where('status', 'active')->get();
        $templates = WhatsappTemplate::orderBy('is_default', 'desc')->orderBy('name')->get();
        
        // Obter tipos de pagamento disponíveis para o filtro
        $paymentTypes = \App\Models\Invoice::select('payment_type')
            ->whereNotNull('payment_type')
            ->distinct()
            ->orderBy('payment_type')
            ->pluck('payment_type');
        
        $connections = \App\Models\ContaAzulConnection::orderBy('empresa_nome')->get();

        return Inertia::render('Clientes/InvoicesOverdue', [
            'invoices' => $invoices,
            'whatsappNumbers' => $whatsappNumbers,
            'templates' => $templates,
            'paymentTypes' => $paymentTypes,
            'connections' => $connections,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'search' => $search,
                'payment_type' => $paymentType,
                'connection_id' => $connectionId,
            ]
        ]);
    }

    public function getClientInvoices(Request $request, $clienteId)
    {
        // Try finding by local ID first, then CA ID
        $cliente = Cliente::find($clienteId);
        if (!$cliente) {
            $cliente = Cliente::where('ca_id', $clienteId)->first();
        }

        if (!$cliente) {
            return response()->json(['error' => 'Cliente não encontrado'], 404);
        }

        $query = \App\Models\Invoice::where('cliente_ca_id', $cliente->ca_id)
                    ->orderBy('data_vencimento', 'asc');

        if ($request->has('start_date') && $request->has('end_date') && $request->start_date && $request->end_date) {
            $query->whereBetween('data_vencimento', [$request->start_date, $request->end_date]);
        }

        $invoices = $query->get();

        return response()->json([
            'cliente' => $cliente,
            'invoices' => $invoices
        ]);
    }

    public function update(Request $request, Cliente $cliente)
    {
        $validated = $request->validate([
            'phone' => 'nullable|string|max:20',
            'mobile_phone' => 'nullable|string|max:20',
            'birthdate' => 'nullable|date',
        ]);
        $cliente->update($validated);
        return redirect()->back()->with('success', 'Dados de contato atualizados com sucesso.');
    }
}
