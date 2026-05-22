<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Invoice;
use App\Models\MessageCron;
use App\Models\WhatsappMessageLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

class ClientReportExportService
{
    public function build(array $filters): Spreadsheet
    {
        $connectionId = $filters['connection_id'] ?? null;
        $clienteId = $filters['cliente_id'] ?? null;
        $start = $filters['start'] ?? null;
        $end = $filters['end'] ?? null;
        $type = $filters['type'] ?? null;
        $search = $filters['search'] ?? null;

        $logQ = WhatsappMessageLog::with(['connection']);
        if ($connectionId) $logQ->where('connection_id', $connectionId);
        if ($clienteId) $logQ->where('cliente_id', $clienteId);
        if ($start && $end) $logQ->whereBetween('sent_at', [$start, $end]);
        if ($search) {
            $logQ->where(function ($q) use ($search) {
                $q->where('client_name', 'like', "%{$search}%")
                  ->orWhere('phone_sanitized', 'like', "%{$search}%")
                  ->orWhere('phone_original', 'like', "%{$search}%");
            });
        }
        if ($type) $logQ->where('message_type', $type);
        $logs = $logQ->orderBy('sent_at', 'asc')->get();

        $groups = $logs->groupBy('connection_id');
        $spreadsheet = new Spreadsheet();
        $first = true;

        foreach ($groups as $connId => $groupLogs) {
            $sentGroup = $groupLogs->where('status', 'success');
            $companyName = $groupLogs->first()->connection->empresa_nome ?? 'Empresa';
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;
            $sheet->setTitle(Str::substr(Str::slug($companyName), 0, 31));
            $sheet->getProtection()->setSheet(false);

            $this->renderHeader($sheet, ['company_name' => $companyName]);

            $headers = ['Nome do cliente', 'Data', 'Descrição', 'Parecer', 'Valor total da parcela', 'Conta bancária'];
            $sheet->fromArray([$headers], null, 'A8');
            $sheet->getStyle('A8:F8')->getFont()->setBold(true);
            $sheet->getStyle('A8:F8')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            // Centraliza títulos das colunas B..E (Data, Descrição, Parecer, Valor)
            $sheet->getStyle('B8:E8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getRowDimension(8)->setRowHeight(22);
            $sheet->getStyle('A8:F8')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFEFEF');
            $sheet->getStyle('A8:F8')->getBorders()->getOutline()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $sheet->freezePane('A9');
            // Larguras para impressão previsível
            $sheet->getColumnDimension('A')->setWidth(40);
            $sheet->getColumnDimension('B')->setWidth(12); // Data
            $sheet->getColumnDimension('C')->setWidth(12); // Descrição (compacto)
            $sheet->getColumnDimension('D')->setWidth(12); // Parecer
            $sheet->getColumnDimension('E')->setWidth(18); // Valor total
            $sheet->getColumnDimension('F')->setWidth(14); // Conta bancária
            $sheet->getDefaultRowDimension()->setRowHeight(18);
            // Centralização será aplicada ao final, no range efetivamente utilizado

            // Primeiro: computa contagem total de boletos por cliente para colorização uniforme
            $counts = [];
            foreach ($sentGroup as $log) {
                $boletoIds = is_array($log->boleto_ids) ? $log->boleto_ids : [];
                if (empty($boletoIds)) {
                    $cid = $log->cliente_id;
                    if ($cid) $counts[$cid] = ($counts[$cid] ?? 0) + 1;
                    continue;
                }
                $invList = Invoice::whereIn('id', $boletoIds)->get(['id','cliente_id']);
                foreach ($invList as $inv) {
                    $cid = $inv->cliente_id ?: $log->cliente_id;
                    if ($cid) $counts[$cid] = ($counts[$cid] ?? 0) + 1;
                }
            }

            // Monta linhas por boleto (sem agrupamento)
            $row = 9;
            foreach ($sentGroup as $log) {
                $boletoIds = is_array($log->boleto_ids) ? $log->boleto_ids : [];
                if (empty($boletoIds)) {
                    // fallback: uma linha sem boleto
                    $parecer = $this->resolveParecerFromLog($log);
                    $sheet->fromArray([[
                        $log->client_name,
                        $log->sent_at ? $log->sent_at->format('d/m/Y') : '',
                        '',
                        $parecer,
                        null,
                        'Boleto bancário',
                    ]], null, 'A'.$row);
                    $color = $this->colorByQty($counts[$log->cliente_id] ?? 1);
                    if ($color && $parecer !== '') {
                        $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
                    }
                    $row++;
                    continue;
                }
                $invList = Invoice::whereIn('id', $boletoIds)->with('cliente')->get();
                foreach ($invList as $inv) {
                    $clientName = $inv->cliente_nome ?: ($inv->cliente->name ?? $log->client_name ?? 'Cliente');
                    $dateStr = $inv->data_vencimento ? $inv->data_vencimento->format('d/m/Y') : ($log->sent_at ? $log->sent_at->format('d/m/Y') : '');
                    $descricao = $inv->descricao ?: '';
                    $valor = (float) ($inv->saldo_devedor ?? $inv->valor_original ?? 0);
                    $parecer = $this->resolveParecerFromLog($log);
                    $sheet->fromArray([[
                        $clientName,
                        $dateStr,
                        $descricao,
                        $parecer,
                        $valor,
                        'Boleto bancário',
                    ]], null, 'A'.$row);
                    // Wrap para impressão legível
                    $sheet->getStyle("C{$row}:D{$row}")->getAlignment()->setWrapText(true);
                    $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $cid = $inv->cliente_id ?: $log->cliente_id;
                    $color = $this->colorByQty($counts[$cid] ?? 1);
                    if ($color && $parecer !== '') {
                        $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
                    }
                    $row++;
                }
            }

            // Reativa auto size das colunas
            foreach (range('A', 'F') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Linhas de total: uma linha em branco e, na seguinte, "Total" em D e soma em E
            $lastDataRow = $row - 1;
            $blankRow = $lastDataRow + 1;
            $totalRow = $lastDataRow + 2;
            // Deixa linha em branco (nada a fazer)
            $sheet->setCellValue("D{$totalRow}", 'Total');
            $sheet->setCellValue("E{$totalRow}", "=SUM(E9:E{$lastDataRow})");
            $sheet->getStyle("D{$totalRow}:E{$totalRow}")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("D{$totalRow}:E{$totalRow}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
            // Bordas em toda a tabela
            $dataRange = "A9:F{$lastDataRow}";
            $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFBBBBBB'));

            // Seção: Não cobrados por motivo (logs skipped/error) — duas linhas abaixo do total
            $sectionStart = $totalRow + 2;
            $nonSentGroups = $groupLogs->filter(function ($l) {
                $st = strtolower((string) ($l->status ?? ''));
                return in_array($st, ['skipped', 'error', 'failed'], true);
            })->groupBy(function ($l) {
                $msg = trim((string) ($l->error_message ?? ''));
                return $msg !== '' ? $msg : 'Motivo não informado';
            });
            foreach ($nonSentGroups as $reason => $logsByReason) {
                // Cabeçalho da seção (título)
                $sheet->mergeCells("A{$sectionStart}:F{$sectionStart}");
                $sheet->setCellValue("A{$sectionStart}", "Não cobradas, {$reason}:");
                $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getFont()->setBold(true);
                $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
                $sectionStart++;
                // Header de colunas (não mesclado), igual ao de cobradas
                $sectionHeaders = ['Nome do cliente', 'Data', 'Descrição', 'Parecer', 'Valor total da parcela', 'Conta bancária'];
                $sheet->fromArray([$sectionHeaders], null, 'A'.$sectionStart);
                $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getFont()->setBold(true);
                $sheet->getStyle("B{$sectionStart}:E{$sectionStart}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getBorders()->getOutline()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                $sectionStart++;
                $sectionTotal = 0.0;
                $rows = $this->buildNonSentRowsForReason($logsByReason, (string) $reason, $groupLogs);
                foreach ($rows as $reportRow) {
                    $sectionTotal += (float) ($reportRow['value'] ?? 0);
                    $sheet->fromArray([[
                        $reportRow['client_name'] ?? 'Cliente',
                        $reportRow['date'] ?? '',
                        $reportRow['description'] ?? '',
                        $reportRow['parecer'] ?? $reason,
                        $reportRow['value'] ?? null,
                        $reportRow['payment_type'] ?? 'Outro',
                    ]], null, 'A'.$sectionStart);
                    if (($reportRow['value'] ?? null) !== null) {
                        $sheet->getStyle("E{$sectionStart}")->getNumberFormat()->setFormatCode('#,##0.00');
                    }
                    $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFDDDDDD'));
                    $sectionStart++;
                }
                // Total da seção
                $sheet->setCellValue("D{$sectionStart}", "Total {$reason}");
                $sheet->setCellValue("E{$sectionStart}", $sectionTotal);
                $sheet->getStyle("E{$sectionStart}")->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle("D{$sectionStart}:E{$sectionStart}")->getFont()->setBold(true);
                $sheet->getStyle("D{$sectionStart}:E{$sectionStart}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
                $sectionStart += 2; // separação entre motivos
            }
            // Seção adicional: Forma de Pagamento diferente (não BOLETO)
            try {
                $notBoleto = Invoice::where('connection_id', (int) $connId)
                    ->where('saldo_devedor', '>', 0)
                    ->whereNotIn('status', ['PAID','PAGO','BAIXADO','LIQUIDADO','CANCELLED','CANCELADO','PENDING','ABERTO'])
                    ->where('data_vencimento', '<', \Carbon\Carbon::today()->format('Y-m-d'))
                    ->where(function ($q) {
                        $q->whereNull('payment_type')
                          ->orWhere('payment_type', 'NOT LIKE', '%BOLETO%');
                    })
                    ->with('cliente')
                    ->get();
                if ($notBoleto->isNotEmpty()) {
                    // Título da subseção
                    $sheet->mergeCells("A{$sectionStart}:F{$sectionStart}");
                    $sheet->setCellValue("A{$sectionStart}", "Não cobradas, Forma de Pagamento diferente:");
                    $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getFont()->setBold(true);
                    $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
                    $sectionStart++;
                    // Header das colunas (igual ao de cobradas)
                    $sectionHeaders = ['Nome do cliente', 'Data', 'Descrição', 'Parecer', 'Valor total da parcela', 'Conta bancária'];
                    $sheet->fromArray([$sectionHeaders], null, 'A'.$sectionStart);
                    $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getFont()->setBold(true);
                    $sheet->getStyle("B{$sectionStart}:E{$sectionStart}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getBorders()->getOutline()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                    $sectionStart++;
                    $reasonTotal = 0.0;
                    foreach ($notBoleto as $inv) {
                        $clientName = $inv->cliente_nome ?: ($inv->cliente->name ?? 'Cliente');
                        $dateStr = $inv->data_vencimento ? $inv->data_vencimento->format('d/m/Y') : '';
                        $descricao = $inv->descricao ?: '';
                        $valor = (float) ($inv->saldo_devedor ?? $inv->valor_original ?? 0);
                        $reasonTotal += $valor;
                        $sheet->fromArray([[
                            $clientName,
                            $dateStr,
                            $descricao,
                            'Forma de Pagamento diferente',
                            $valor,
                            $inv->payment_type ?: 'Outro',
                        ]], null, 'A'.$sectionStart);
                        $sheet->getStyle("E{$sectionStart}")->getNumberFormat()->setFormatCode('#,##0.00');
                        $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFDDDDDD'));
                        $sectionStart++;
                    }
                    $sheet->setCellValue("D{$sectionStart}", "Total Forma de Pagamento diferente");
                    $sheet->setCellValue("E{$sectionStart}", $reasonTotal);
                    $sheet->getStyle("E{$sectionStart}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $sheet->getStyle("D{$sectionStart}:E{$sectionStart}")->getFont()->setBold(true);
                    $sheet->getStyle("D{$sectionStart}:E{$sectionStart}")->getBorders()->getOutline()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                    $sectionStart += 2;
                }
            } catch (\Throwable $e) {
                // Ignora falhas silenciosamente para não quebrar a geração
            }
            // Atualiza última linha útil para configuração de impressão
            $endRowForPrint = max($totalRow, $sectionStart - 1);

            // Configuração de impressão semelhante ao Google Planilhas
            $printArea = "A1:F{$endRowForPrint}";
            $pageSetup = $sheet->getPageSetup();
            $pageSetup->setPrintArea($printArea);
            $pageSetup->setFitToWidth(1);
            $pageSetup->setFitToHeight(0);
            $pageSetup->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
            $pageSetup->setHorizontalCentered(true);
            $pageSetup->setRowsToRepeatAtTopByStartAndEnd(8, 8);
            $sheet->setShowGridlines(true);
            $sheet->setPrintGridlines(true);
            $margins = $sheet->getPageMargins();
            $margins->setTop(0.5);
            $margins->setBottom(0.5);
            $margins->setLeft(0.25);
            $margins->setRight(0.25);

            // Centraliza colunas B..F somente no range utilizado
            $sheet->getStyle("B9:F{$endRowForPrint}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
        }

        return $spreadsheet;
    }

    protected function renderHeader(Worksheet $sheet, array $filters): void
    {
        // Cabeçalho replicado com merges, alturas e blocos de cor (pixel a pixel aproximado)
        // Área "Legenda"
        $sheet->setCellValue('A1', 'Legenda');
        $sheet->mergeCells('A1:A6');
        $sheet->getStyle('A1:A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A1:A6')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1:A6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0'); // cinza claro
        foreach ([1,2,3,4,5,6] as $r) {
            $sheet->getRowDimension($r)->setRowHeight(22);
        }
        $sheet->getStyle('A1:A6')->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF999999'));

        // Itens de legenda com patches ao lado (coluna C)
        $legend = [
            ['text' => 'Deve apenas 1 mês', 'color' => 'FFCCE5FF'], // Azul claro
            ['text' => 'Deve 2 meses', 'color' => 'FFC6EFCE'],       // Verde claro
            ['text' => 'Caso crítico 3 ou + meses', 'color' => 'FFFFCDD2'], // Vermelho claro
        ];
        $baseRow = 1;
        for ($i = 0; $i < count($legend); $i++) {
            $r = $baseRow + $i + 1; // B2..B7 (mas usamos B2..B6 conforme merges)
            $sheet->setCellValue("B{$r}", $legend[$i]['text']);
            $sheet->getStyle("B{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("C{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($legend[$i]['color']);
            $sheet->mergeCells("C{$r}:C{$r}");
            $sheet->getColumnDimension('B')->setWidth(35);
            $sheet->getColumnDimension('C')->setWidth(4);
            $sheet->getStyle("B{$r}:C{$r}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFBBBBBB'));
        }
        // Não cobrado
        $sheet->setCellValue('B5', 'Não cobrado');
        $sheet->getStyle('B5')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('B5:C5')->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFBBBBBB'));

        // Título da empresa (bloco à direita)
        $companyTitle = $filters['company_name'] ?? 'Empresa';
        $sheet->setCellValue('F1', $companyTitle);
        $sheet->mergeCells('F1:F6');
        $sheet->getStyle('F1:F6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle('F1:F6')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('F1:F6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0'); // cinza claro
        $sheet->getColumnDimension('F')->setWidth(24);
        $sheet->getStyle('F1:F6')->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF999999'));
    }

    protected function colorByQty(int $qty): ?string
    {
        if ($qty >= 3) return 'FFFFCDD2'; // Vermelho claro
        if ($qty === 2) return 'FFC6EFCE'; // Verde claro
        return 'FFCCE5FF'; // Azul claro
    }

    protected function resolveParecerFromLog(WhatsappMessageLog $log): string
    {
        $status = strtoupper((string) ($log->delivery_status ?? ''));
        if ($status === '' || $status === 'N/A') {
            return '';
        }
        if ($status === 'PENDING') {
            return '-Cobrado';
        }
        if (in_array($status, ['SENT','READ','DELIVERED'], true)) {
            if (! (bool) ($log->responded ?? false)) {
                return '-Cobrado';
            }
            $phone = preg_replace('/\D+/', '', (string) ($log->phone_sanitized ?? $log->phone_original ?? ''));
            if ($phone) {
                $incoming = \DB::table('incoming_messages')->where('numero_origem', $phone)->orderByDesc('created_at')->first();
                if ($incoming && $incoming->payload_json) {
                    $payload = json_decode($incoming->payload_json, true);
                    $text = $payload['text']['body'] ?? ($payload['message']['text']['body'] ?? null);
                    if ($text) return (string) $text;
                }
            }
        }
        return '';
    }

    protected function buildNonSentRowsForReason(Collection $logsByReason, string $reason, Collection $groupLogs): array
    {
        $rows = [];
        $seen = [];

        foreach ($logsByReason as $log) {
            foreach ($this->buildNonSentRowsFromLog($log, $reason, $groupLogs) as $row) {
                $key = $row['key'] ?? md5(json_encode($row));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                unset($row['key']);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    protected function buildNonSentRowsFromLog(WhatsappMessageLog $log, string $reason, Collection $groupLogs): array
    {
        if ($this->isDisconnectedAbortSummary($log, $reason)) {
            $expanded = $this->buildDisconnectedAbortRows($log, $reason, $groupLogs);
            if (! empty($expanded)) {
                return $expanded;
            }
        }

        $boletoIds = is_array($log->boleto_ids) ? $log->boleto_ids : [];
        if (empty($boletoIds)) {
            return [[
                'key' => 'log-'.$log->id,
                'client_name' => $log->client_name ?: 'Cliente',
                'date' => $log->sent_at ? $log->sent_at->format('d/m/Y') : '',
                'description' => '',
                'parecer' => $reason,
                'value' => null,
                'payment_type' => 'Outro',
            ]];
        }

        $rows = [];
        $invList = Invoice::whereIn('id', $boletoIds)->with('cliente')->get();
        foreach ($invList as $inv) {
            $rows[] = [
                'key' => 'invoice-'.$log->id.'-'.$inv->id,
                'client_name' => $inv->cliente_nome ?: ($inv->cliente->name ?? $log->client_name ?? 'Cliente'),
                'date' => $inv->data_vencimento ? $inv->data_vencimento->format('d/m/Y') : ($log->sent_at ? $log->sent_at->format('d/m/Y') : ''),
                'description' => $inv->descricao ?: '',
                'parecer' => $reason,
                'value' => (float) ($inv->saldo_devedor ?? $inv->valor_original ?? 0),
                'payment_type' => $inv->payment_type ?: 'Outro',
            ];
        }

        return $rows;
    }

    protected function isDisconnectedAbortSummary(WhatsappMessageLog $log, string $reason): bool
    {
        if ((string) $log->client_name !== 'Resumo do cron') {
            return false;
        }

        $normalized = Str::lower($reason);

        return str_contains($normalized, 'desconectado durante o processamento')
            || str_contains($normalized, 'cron abortado antes de consultar');
    }

    protected function buildDisconnectedAbortRows(WhatsappMessageLog $summaryLog, string $reason, Collection $groupLogs): array
    {
        if (! $summaryLog->message_cron_id || ! $summaryLog->batch_id) {
            return [];
        }

        $cron = MessageCron::find($summaryLog->message_cron_id);
        if (! $cron) {
            return [];
        }

        $batchLogs = $groupLogs->filter(function ($item) use ($summaryLog) {
            return (string) $item->batch_id === (string) $summaryLog->batch_id
                && (int) $item->message_cron_id === (int) $summaryLog->message_cron_id;
        });

        $individualLogs = $batchLogs->filter(function ($item) {
            return (string) $item->client_name !== 'Resumo do cron';
        });

        return match ((string) $cron->type) {
            'billing' => $this->buildDisconnectedBillingRows($cron, $summaryLog, $reason, $individualLogs),
            'due_date', 'boleto' => $this->buildDisconnectedInvoiceRows($cron, $summaryLog, $reason, $individualLogs),
            'birthday' => $this->buildDisconnectedBirthdayRows($cron, $summaryLog, $reason, $individualLogs),
            default => [],
        };
    }

    protected function buildDisconnectedBillingRows(MessageCron $cron, WhatsappMessageLog $summaryLog, string $reason, Collection $individualLogs): array
    {
        $invoices = $this->getBillingInvoicesForReport($cron, $summaryLog);
        if ($invoices->isEmpty()) {
            return [];
        }

        $processedClientIds = $individualLogs->pluck('cliente_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $processedLookup = array_fill_keys($processedClientIds, true);
        $rows = [];

        foreach ($invoices->groupBy('cliente_id') as $clienteId => $clientInvoices) {
            if (! $clienteId || isset($processedLookup[(int) $clienteId])) {
                continue;
            }
            foreach ($clientInvoices as $inv) {
                $rows[] = [
                    'key' => 'abort-billing-'.$summaryLog->id.'-'.$inv->id,
                    'client_name' => $inv->cliente_nome ?: ($inv->cliente->name ?? 'Cliente'),
                    'date' => $inv->data_vencimento ? $inv->data_vencimento->format('d/m/Y') : ($summaryLog->sent_at ? $summaryLog->sent_at->format('d/m/Y') : ''),
                    'description' => $inv->descricao ?: '',
                    'parecer' => $reason,
                    'value' => (float) ($inv->saldo_devedor ?? $inv->valor_original ?? 0),
                    'payment_type' => $inv->payment_type ?: 'Outro',
                ];
            }
        }

        return $rows;
    }

    protected function buildDisconnectedInvoiceRows(MessageCron $cron, WhatsappMessageLog $summaryLog, string $reason, Collection $individualLogs): array
    {
        $invoices = $this->getInvoiceCandidatesForReport($cron, $summaryLog);
        if ($invoices->isEmpty()) {
            return [];
        }

        $processedInvoiceIds = $individualLogs
            ->flatMap(function ($log) {
                $ids = is_array($log->boleto_ids) ? $log->boleto_ids : [];
                return collect($ids)->map(fn ($id) => (int) $id);
            })
            ->filter()
            ->unique()
            ->all();
        $processedLookup = array_fill_keys($processedInvoiceIds, true);
        $rows = [];

        foreach ($invoices as $inv) {
            if (isset($processedLookup[(int) $inv->id])) {
                continue;
            }
            $rows[] = [
                'key' => 'abort-invoice-'.$summaryLog->id.'-'.$inv->id,
                'client_name' => $inv->cliente_nome ?: ($inv->cliente->name ?? 'Cliente'),
                'date' => $inv->data_vencimento ? $inv->data_vencimento->format('d/m/Y') : ($summaryLog->sent_at ? $summaryLog->sent_at->format('d/m/Y') : ''),
                'description' => $inv->descricao ?: '',
                'parecer' => $reason,
                'value' => (float) ($inv->saldo_devedor ?? $inv->valor_original ?? 0),
                'payment_type' => $inv->payment_type ?: 'Outro',
            ];
        }

        return $rows;
    }

    protected function buildDisconnectedBirthdayRows(MessageCron $cron, WhatsappMessageLog $summaryLog, string $reason, Collection $individualLogs): array
    {
        $clients = $this->getBirthdayCandidatesForReport($cron, $summaryLog);
        if ($clients->isEmpty()) {
            return [];
        }

        $processedClientIds = $individualLogs->pluck('cliente_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $processedLookup = array_fill_keys($processedClientIds, true);
        $rows = [];

        foreach ($clients as $client) {
            if (isset($processedLookup[(int) $client->id])) {
                continue;
            }
            $rows[] = [
                'key' => 'abort-birthday-'.$summaryLog->id.'-'.$client->id,
                'client_name' => $client->name ?: 'Cliente',
                'date' => $summaryLog->sent_at ? $summaryLog->sent_at->format('d/m/Y') : '',
                'description' => 'Aniversário',
                'parecer' => $reason,
                'value' => null,
                'payment_type' => 'Outro',
            ];
        }

        return $rows;
    }

    protected function getBillingInvoicesForReport(MessageCron $cron, WhatsappMessageLog $summaryLog): Collection
    {
        $tz = config('app.timezone') ?: 'America/Sao_Paulo';
        $reference = $summaryLog->sent_at ? $summaryLog->sent_at->copy()->timezone($tz) : Carbon::now($tz);
        $daysLate = max(0, (int) ($cron->days_after_due ?? 0));
        $strictThresholdDays = $daysLate + 1;
        $dueDateLimit = $reference->copy()->subDays($strictThresholdDays)->format('Y-m-d');
        $todayDate = $reference->copy()->startOfDay()->format('Y-m-d');
        $periodStart = $this->getPeriodStartDateForReference($cron, $reference);

        $query = Invoice::where('data_vencimento', '<=', $dueDateLimit)
            ->where('data_vencimento', '<', $todayDate)
            ->whereNotIn('status', ['PENDING', 'ABERTO']);

        if (! empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }

        if ($periodStart) {
            $query->where('data_vencimento', '>=', $periodStart);
        }

        return $query->where('saldo_devedor', '>', 0)
            ->whereNotIn('status', ['PAID', 'PAGO', 'BAIXADO', 'LIQUIDADO', 'CANCELLED', 'CANCELADO'])
            ->where(function ($q) {
                $q->whereNotNull('payment_type')
                    ->where('payment_type', 'LIKE', '%BOLETO%');
            })
            ->with('cliente')
            ->get()
            ->filter(fn ($inv) => $inv->cliente_id && $inv->cliente);
    }

    protected function getInvoiceCandidatesForReport(MessageCron $cron, WhatsappMessageLog $summaryLog): Collection
    {
        $reference = $summaryLog->sent_at ? $summaryLog->sent_at->copy() : Carbon::now();

        if ($cron->type === 'due_date') {
            $targetDate = $reference->copy()->addDays((int) ($cron->days_before_due ?? 0))->format('Y-m-d');
            $query = Invoice::whereDate('data_vencimento', $targetDate)
                ->where(function ($q) {
                    $q->whereIn('status', ['PENDING', 'ABERTO'])
                        ->orWhereNull('status');
                })
                ->where(function ($q) {
                    $q->whereNull('saldo_devedor')->orWhere('saldo_devedor', '>', 0);
                });
        } else {
            $days = (int) ($cron->days_before_due ?? $cron->period_value ?? 0);
            $startDate = $reference->copy()->startOfDay()->format('Y-m-d');
            $endDate = $reference->copy()->addDays($days)->format('Y-m-d');
            $query = Invoice::where('status', 'PENDING')
                ->whereDate('data_vencimento', '>=', $startDate)
                ->whereDate('data_vencimento', '<=', $endDate)
                ->whereNotNull('link_boleto')
                ->where('link_boleto', '!=', '');
        }

        if (! empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }

        return $query->with('cliente')->get();
    }

    protected function getBirthdayCandidatesForReport(MessageCron $cron, WhatsappMessageLog $summaryLog): Collection
    {
        $reference = $summaryLog->sent_at ? $summaryLog->sent_at->copy() : Carbon::now();
        $today = $reference->format('m-d');

        $query = Cliente::whereRaw("DATE_FORMAT(birthdate, '%m-%d') = ?", [$today]);
        if (! empty($cron->connection_id)) {
            $query->where('connection_id', $cron->connection_id);
        }

        return $query->get();
    }

    protected function getPeriodStartDateForReference(MessageCron $cron, Carbon $reference): ?string
    {
        if (! $cron->period_value || ! $cron->period_unit) {
            return null;
        }

        $date = $reference->copy();
        switch ($cron->period_unit) {
            case 'days':
                $date->subDays($cron->period_value);
                break;
            case 'months':
                $date->subMonths($cron->period_value);
                break;
            case 'years':
                $date->subYears($cron->period_value);
                break;
        }

        return $date->format('Y-m-d');
    }
}
