<?php

namespace App\Http\Controllers;

use App\Models\WhatsappNumber;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WhatsAppController extends Controller
{
    public function index()
    {
        $numbers = WhatsappNumber::orderBy('created_at', 'desc')->get();

        return Inertia::render('WhatsApp/Index', [
            'numbers' => $numbers,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'description' => 'nullable|string|max:255',
            'ddi' => 'required|string|max:5',
            'ddd' => 'required|string|max:5',
            'phone' => 'required|string|max:20',
            'whapi_key' => 'required|string',
            'is_default' => 'boolean',
            'status' => 'required|in:active,inactive',
        ]);

        if ($validated['is_default'] ?? false) {
            // Remove default from other numbers
            WhatsappNumber::where('is_default', true)->update(['is_default' => false]);
        }

        WhatsappNumber::create($validated);

        return redirect()->route('whatsapp.index')->with('success', 'Número de WhatsApp adicionado com sucesso!');
    }

    public function update(Request $request, WhatsappNumber $whatsapp)
    {
        $validated = $request->validate([
            'description' => 'nullable|string|max:255',
            'ddi' => 'required|string|max:5',
            'ddd' => 'required|string|max:5',
            'phone' => 'required|string|max:20',
            'whapi_key' => 'nullable|string',
            'is_default' => 'boolean',
            'status' => 'required|in:active,inactive',
        ]);

        if ($validated['is_default'] ?? false) {
            // Remove default from other numbers excluding current
            WhatsappNumber::where('id', '!=', $whatsapp->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $whatsapp->update($validated);

        return redirect()->route('whatsapp.index')->with('success', 'Número de WhatsApp atualizado com sucesso!');
    }

    public function destroy(WhatsappNumber $whatsapp)
    {
        $whatsapp->delete();

        return redirect()->route('whatsapp.index')->with('success', 'Número de WhatsApp removido com sucesso!');
    }
}
