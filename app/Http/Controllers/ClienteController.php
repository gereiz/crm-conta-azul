<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ContaAzulConnection;
use App\Models\WhatsappNumber;
use App\Models\WhatsappTemplate;
use App\Services\ContaAzulService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
        $connectionId = $request->input('connection_id');
        $noPhone = filter_var($request->input('no_phone'), FILTER_VALIDATE_BOOLEAN);
        $noEmail = filter_var($request->input('no_email'), FILTER_VALIDATE_BOOLEAN);
        $noDocument = filter_var($request->input('no_document'), FILTER_VALIDATE_BOOLEAN);
        $onlyInternational = $request->has('international') ? filter_var($request->input('international'), FILTER_VALIDATE_BOOLEAN) : null;

        $query = Cliente::query();

        if ($search) {
            $digits = preg_replace('/\D+/', '', (string) $search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('cpf_cnpj', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('mobile_phone', 'like', "%{$search}%");
            });
            if ($digits && strlen($digits) >= 6) {
                $query->orWhere(function ($q) use ($digits) {
                    $q->where('phone', 'like', "%{$digits}%")
                        ->orWhere('mobile_phone', 'like', "%{$digits}%");
                });
            }
        }

        if ($connectionId) {
            $query->where('connection_id', $connectionId);
        }

        if ($noPhone) {
            $query->whereNull('phone')->whereNull('mobile_phone');
        }
        if ($noEmail) {
            $query->whereNull('email');
        }
        if ($noDocument) {
            $query->whereNull('cpf_cnpj');
        }
        if ($onlyInternational !== null) {
            $query->where('is_international', $onlyInternational);
        }

        // Ordenação
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction', 'asc');

        // Validar colunas permitidas para evitar SQL Injection indireta ou erros
        $allowedSorts = ['name', 'email', 'company_name', 'cpf_cnpj', 'mobile_phone', 'is_international'];
        if (! in_array($sort, $allowedSorts)) {
            $sort = 'name';
        }
        if (! in_array(strtolower($direction), ['asc', 'desc'])) {
            $direction = 'asc';
        }

        $query->orderBy($sort, $direction);

        $clientes = $query->paginate($size)->withQueryString();
        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();

        return Inertia::render('Clientes/Index', [
            'clientes' => $clientes,
            'connections' => $connections,
            'filters' => array_merge($request->only(['search', 'size']), [
                'sort' => $sort,
                'direction' => $direction,
                'connection_id' => $connectionId,
                'no_phone' => $noPhone,
                'no_email' => $noEmail,
                'no_document' => $noDocument,
                'international' => $onlyInternational,
            ]),
        ]);
    }

    public function show($id)
    {
        // $id pode ser o ID local ou o UUID da CA (se vindo de URL antiga)
        // Vamos tentar achar pelo ID local primeiro
        $clienteLocal = Cliente::find($id);

        if (! $clienteLocal) {
            // Tenta buscar por ca_id
            $clienteLocal = Cliente::where('ca_id', $id)->first();
        }

        if (! $clienteLocal) {
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
            // Merge telefones locais quando os campos da API estiverem vazios
            if (empty($cliente['telefone_comercial']) && !empty($clienteLocal->phone)) {
                $cliente['telefone_comercial'] = $clienteLocal->phone;
            }
            if (empty($cliente['telefone_celular']) && !empty($clienteLocal->mobile_phone)) {
                $cliente['telefone_celular'] = $clienteLocal->mobile_phone;
            }
            // Também expõe os campos locais explicitamente para a view
            $cliente['phone'] = $clienteLocal->phone;
            $cliente['mobile_phone'] = $clienteLocal->mobile_phone;
            $cliente['is_international'] = (bool) ($clienteLocal->is_international ?? false);
        }

        // Buscar apenas faturas em atraso (saldo > 0, vencidas e não pagas/baixadas)
        $today = \Carbon\Carbon::today()->format('Y-m-d');
        $excludeStatuses = [
            'PAID', 'PAGO', 'RECEIVED', 'RECEBIDO', 'CONCILIADO',
            'LIQUIDADO', 'CANCELLED', 'CANCELADO', 'BAIXADO',
            'PENDING', 'ABERTO',
        ];
        $invoices = \App\Models\Invoice::where('cliente_ca_id', $caId)
            ->where('saldo_devedor', '>', 0)
            ->where('data_vencimento', '<', $today)
            ->whereNotIn('status', $excludeStatuses)
            ->orderBy('data_vencimento', 'asc')
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

    public function export(Request $request)
    {
        $search = $request->input('search');
        $connectionId = $request->input('connection_id');
        $noPhone = filter_var($request->input('no_phone'), FILTER_VALIDATE_BOOLEAN);
        $noEmail = filter_var($request->input('no_email'), FILTER_VALIDATE_BOOLEAN);
        $noDocument = filter_var($request->input('no_document'), FILTER_VALIDATE_BOOLEAN);
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction', 'asc');

        $query = Cliente::query();
        if ($search) {
            $digits = preg_replace('/\D+/', '', (string) $search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('cpf_cnpj', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('mobile_phone', 'like', "%{$search}%");
            });
            if ($digits && strlen($digits) >= 6) {
                $query->orWhere(function ($q) use ($digits) {
                    $q->where('phone', 'like', "%{$digits}%")
                        ->orWhere('mobile_phone', 'like', "%{$digits}%");
                });
            }
        }
        if ($connectionId) {
            $query->where('connection_id', $connectionId);
        }
        if ($noPhone) {
            $query->whereNull('phone')->whereNull('mobile_phone');
        }
        if ($noEmail) {
            $query->whereNull('email');
        }
        if ($noDocument) {
            $query->whereNull('cpf_cnpj');
        }
        $allowedSorts = ['name', 'email', 'company_name', 'cpf_cnpj', 'mobile_phone'];
        if (! in_array($sort, $allowedSorts)) {
            $sort = 'name';
        }
        if (! in_array(strtolower($direction), ['asc', 'desc'])) {
            $direction = 'asc';
        }
        $query->orderBy($sort, $direction);
        $items = $query->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Clientes');
        $headers = [
            'ID',
            'CA ID',
            'Empresa',
            'Nome',
            'Email',
            'Telefone',
            'Celular',
            'Internacional',
            'CPF/CNPJ',
            'Tipo Pessoa',
            'Cidade',
            'Estado',
            'Data Nascimento',
            'Criado em',
            'Atualizado em',
        ];
        $sheet->fromArray([$headers], null, 'A1');
        $row = 2;
        foreach ($items as $c) {
            $sheet->fromArray([[
                $c->id,
                $c->ca_id,
                $c->company_name,
                $c->name,
                $c->email,
                $c->phone,
                $c->mobile_phone,
                $c->is_international ? 'Sim' : 'Não',
                $c->cpf_cnpj,
                $c->person_type,
                $c->city,
                $c->state,
                $c->birthdate ? \Carbon\Carbon::parse($c->birthdate)->format('d/m/Y') : '',
                $c->created_at ? \Carbon\Carbon::parse($c->created_at)->format('d/m/Y H:i:s') : '',
                $c->updated_at ? \Carbon\Carbon::parse($c->updated_at)->format('d/m/Y H:i:s') : '',
            ]], null, 'A'.$row);
            $row++;
        }
        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $filename = 'clientes_filtrados_'.now()->format('Y-m-d_H-i').'.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
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
        $today = \Carbon\Carbon::today()->format('Y-m-d');

        if ($startDate && $endDate) {
            $query->whereBetween('data_vencimento', [$startDate, $endDate]);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('cliente', function ($qc) use ($search) {
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

        // Somente faturas vencidas (data < hoje) e/ou status de atraso
        $query->where('saldo_devedor', '>', 0)
            ->where(function ($q) use ($today) {
                $q->where('data_vencimento', '<', $today)
                    ->orWhereIn('status', ['OVERDUE', 'ATRASADO']);
            })
            ->whereNotIn('status', ['PENDING', 'ABERTO']);

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
            ],
        ]);
    }

    public function toggleInternational(Request $request, Cliente $cliente)
    {
        $value = $request->has('value') ? filter_var($request->input('value'), FILTER_VALIDATE_BOOLEAN) : null;
        if ($value === null) {
            $cliente->is_international = ! (bool) ($cliente->is_international ?? false);
        } else {
            $cliente->is_international = $value;
        }
        $cliente->save();

        return response()->json([
            'success' => true,
            'id' => $cliente->id,
            'is_international' => (bool) $cliente->is_international,
        ]);
    }

    public function getClientInvoices(Request $request, $clienteId)
    {
        // Try finding by local ID first, then CA ID
        $cliente = Cliente::find($clienteId);
        if (! $cliente) {
            $cliente = Cliente::where('ca_id', $clienteId)->first();
        }

        if (! $cliente) {
            return response()->json(['error' => 'Cliente não encontrado'], 404);
        }

        $today = \Carbon\Carbon::today()->format('Y-m-d');
        $excludeStatuses = [
            'PAID', 'PAGO', 'RECEIVED', 'RECEBIDO', 'CONCILIADO',
            'LIQUIDADO', 'CANCELLED', 'CANCELADO', 'BAIXADO',
            'PENDING', 'ABERTO',
        ];
        $query = \App\Models\Invoice::where('cliente_ca_id', $cliente->ca_id)
            ->where('saldo_devedor', '>', 0)
            ->where('data_vencimento', '<', $today)
            ->whereNotIn('status', $excludeStatuses)
            ->orderBy('data_vencimento', 'asc');

        if ($request->has('start_date') && $request->has('end_date') && $request->start_date && $request->end_date) {
            $query->whereBetween('data_vencimento', [$request->start_date, $request->end_date]);
        }

        $invoices = $query->get();

        return response()->json([
            'cliente' => $cliente,
            'invoices' => $invoices,
        ]);
    }

    public function update(Request $request, Cliente $cliente)
    {
        $validated = $request->validate([
            'phone' => 'nullable|string|max:20',
            'mobile_phone' => 'nullable|string|max:20',
            'birthdate' => 'nullable|date',
            'is_international' => 'sometimes|boolean',
        ]);
        $cliente->update($validated);

        return redirect()->back()->with('success', 'Dados de contato atualizados com sucesso.');
    }
}
