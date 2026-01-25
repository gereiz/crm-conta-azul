<?php

namespace App\Http\Controllers;

use App\Models\FutureMessageSchedule;
use App\Models\ContaAzulConnection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class FutureMessageController extends Controller
{
    public function index(Request $request)
    {
        $connectionId = $request->input('connection_id');
        $type = $request->input('type');
        $date = $request->input('date');

        $query = FutureMessageSchedule::with(['connection', 'cliente', 'invoice'])
            ->orderBy('scheduled_send_date', 'asc')
            ->orderBy('message_type', 'asc');

        if ($connectionId) {
            $query->where('connection_id', $connectionId);
        }

        if ($type) {
            $query->where('message_type', $type);
        }

        if ($date) {
            $query->whereDate('scheduled_send_date', $date);
        } else {
            // Default: Mostra de hoje em diante
            $query->whereDate('scheduled_send_date', '>=', Carbon::today());
        }

        $schedules = $query->paginate(20)->withQueryString();

        $connections = ContaAzulConnection::orderBy('empresa_nome')->get();

        return Inertia::render('WhatsApp/FutureMessages/Index', [
            'schedules' => $schedules,
            'connections' => $connections,
            'filters' => [
                'connection_id' => $connectionId,
                'type' => $type,
                'date' => $date,
            ]
        ]);
    }
}
