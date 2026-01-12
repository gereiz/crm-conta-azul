<?php

namespace App\Http\Controllers;

use App\Models\BillingRestriction;
use App\Models\ContaAzulConnection;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BillingRestrictionController extends Controller
{
    public function index()
    {
        $restrictions = BillingRestriction::with('connection')->orderByDesc('created_at')->paginate(20);
        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();
        return Inertia::render('Settings/Restrictions/Index', [
            'restrictions' => $restrictions,
            'connections' => $connections,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'connection_id' => 'required|exists:conta_azul_connections,id',
            'type' => 'required|in:client_equals,invoice_equals,description_contains',
            'value' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);
        BillingRestriction::create($validated);
        return redirect()->back()->with('success', 'Regra criada com sucesso.');
    }

    public function update(Request $request, BillingRestriction $restriction)
    {
        $validated = $request->validate([
            'connection_id' => 'required|exists:conta_azul_connections,id',
            'type' => 'required|in:client_equals,invoice_equals,description_contains',
            'value' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);
        $restriction->update($validated);
        return redirect()->back()->with('success', 'Regra atualizada com sucesso.');
    }

    public function destroy(BillingRestriction $restriction)
    {
        $restriction->delete();
        return redirect()->back()->with('success', 'Regra excluída com sucesso.');
    }

    public function toggle(BillingRestriction $restriction)
    {
        $restriction->is_active = ! $restriction->is_active;
        $restriction->save();
        return redirect()->back()->with('success', 'Status da regra atualizado.');
    }

    public function autocompleteClients(Request $request)
    {
        $request->validate([
            'connection_id' => 'required|exists:conta_azul_connections,id',
            'query' => 'nullable|string',
        ]);
        $q = $request->input('query', '');
        $items = \App\Models\Cliente::where('connection_id', $request->connection_id)
            ->when($q, function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('company_name', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'ca_id', 'name', 'company_name']);
        return response()->json($items);
    }

    public function autocompleteInvoices(Request $request)
    {
        $request->validate([
            'connection_id' => 'required|exists:conta_azul_connections,id',
            'query' => 'nullable|string',
        ]);
        $q = $request->input('query', '');
        $items = \App\Models\Invoice::where('connection_id', $request->connection_id)
            ->when($q, function ($query) use ($q) {
                $query->where('ca_id', 'like', "%{$q}%")
                      ->orWhere('descricao', 'like', "%{$q}%");
            })
            ->orderByDesc('data_vencimento')
            ->limit(10)
            ->get(['id', 'ca_id', 'descricao']);
        return response()->json($items);
    }
}
