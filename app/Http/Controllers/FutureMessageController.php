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
            if ($type === 'boleto') {
                // Emissão (com link)
                // Pode ser message_type 'boleto' OU ('billing'/'due_date' com invoice.link_boleto != null)
                $query->where(function($q) {
                    $q->where('message_type', 'boleto')
                      ->orWhere(function($sub) {
                          $sub->whereIn('message_type', ['billing', 'due_date'])
                              ->whereHas('invoice', function($inv) {
                                  $inv->whereNotNull('link_boleto')->where('link_boleto', '!=', '');
                              });
                      });
                });
            } elseif ($type === 'due_date') {
                // Vencimento (sem link)
                // Pode ser message_type 'due_date' OU ('billing'/'boleto' com invoice.link_boleto null)
                $query->where(function($q) {
                    $q->where('message_type', 'due_date')
                      ->orWhere(function($sub) {
                          $sub->whereIn('message_type', ['billing', 'boleto'])
                              ->whereHas('invoice', function($inv) {
                                  $inv->where(function($link) {
                                      $link->whereNull('link_boleto')->orWhere('link_boleto', '');
                                  });
                              });
                      });
                });
            } else {
                $query->where('message_type', $type);
            }
        }

        if ($date) {
            try {
                $start = \Carbon\Carbon::parse($date)->startOfDay();
                $end = \Carbon\Carbon::parse($date)->endOfDay();
                $query->whereBetween('scheduled_send_date', [$start, $end]);
            } catch (\Exception $e) {
                // Fallback seguro caso o parse falhe
                $query->whereDate('scheduled_send_date', $date);
            }
        } else {
            // Default: Mostra apenas futuros (> hoje), pois envios de hoje são processados pela cron
            // Ajustado para > hoje (amanhã em diante)
            $query->whereDate('scheduled_send_date', '>', Carbon::today());
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
