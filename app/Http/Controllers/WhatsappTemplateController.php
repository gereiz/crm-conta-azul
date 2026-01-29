<?php

namespace App\Http\Controllers;

use App\Models\WhatsappTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WhatsappTemplateController extends Controller
{
    public function index()
    {
        $templates = WhatsappTemplate::latest()->get();

        return Inertia::render('Settings/Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'is_default' => 'boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            WhatsappTemplate::where('is_default', true)->update(['is_default' => false]);
        }

        WhatsappTemplate::create($validated);

        return redirect()->back()->with('success', 'Modelo criado com sucesso!');
    }

    public function update(Request $request, WhatsappTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'is_default' => 'boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            WhatsappTemplate::where('id', '!=', $template->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $template->update($validated);

        return redirect()->back()->with('success', 'Modelo atualizado com sucesso!');
    }

    public function destroy(WhatsappTemplate $template)
    {
        $template->delete();

        return redirect()->back()->with('success', 'Modelo excluído com sucesso!');
    }
}
