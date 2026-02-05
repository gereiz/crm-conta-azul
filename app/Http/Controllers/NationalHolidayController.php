<?php

namespace App\Http\Controllers;

use App\Models\NationalHoliday;
use Illuminate\Http\Request;

class NationalHolidayController extends Controller
{
    public function index(Request $request)
    {
        $year = (int) ($request->input('year') ?: now()->year);
        $items = NationalHoliday::whereYear('date', $year)
            ->orderBy('date')
            ->get(['id', 'date', 'name']);

        return response()->json([
            'year' => $year,
            'items' => $items,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'name' => 'required|string|max:255',
        ]);

        $holiday = NationalHoliday::create($validated);

        return response()->json($holiday, 201);
    }

    public function destroy(NationalHoliday $holiday)
    {
        $holiday->delete();
        return response()->noContent();
    }
}

