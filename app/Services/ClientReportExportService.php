<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\WhatsappMessageLog;
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

        $logQ = WhatsappMessageLog::with(['connection'])
            ->where('status', 'success');
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
            $sheet->getRowDimension(8)->setRowHeight(22);
            $sheet->getStyle('A8:F8')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFEFEF');
            $sheet->getStyle('A8:F8')->getBorders()->getOutline()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $sheet->freezePane('A9');
            // Larguras para impressão previsível
            $sheet->getColumnDimension('A')->setWidth(40);
            $sheet->getColumnDimension('B')->setWidth(12);
            $sheet->getColumnDimension('C')->setWidth(35);
            $sheet->getColumnDimension('D')->setWidth(25);
            $sheet->getColumnDimension('E')->setWidth(14);
            $sheet->getColumnDimension('F')->setWidth(20);
            $sheet->getDefaultRowDimension()->setRowHeight(18);

            // Primeiro: computa contagem total de boletos por cliente para colorização uniforme
            $counts = [];
            foreach ($groupLogs as $log) {
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
            foreach ($groupLogs as $log) {
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

            // Seção: Não cobrados por motivo (logs skipped) — duas linhas abaixo do total
            $sectionStart = $totalRow + 2;
            $skipped = $groupLogs->where('status', 'skipped')->filter(function ($l) {
                return (string) ($l->error_message ?? '') !== '';
            })->groupBy(function ($l) {
                return (string) $l->error_message;
            });
            foreach ($skipped as $reason => $logsByReason) {
                // Cabeçalho da seção
                $sheet->mergeCells("A{$sectionStart}:F{$sectionStart}");
                $sheet->setCellValue("A{$sectionStart}", "Não cobradas, {$reason}:");
                $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getFont()->setBold(true);
                $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
                $sectionStart++;
                $sectionTotal = 0.0;

                // Lista de linhas por boleto
                foreach ($logsByReason as $log) {
                    $boletoIds = is_array($log->boleto_ids) ? $log->boleto_ids : [];
                    if (empty($boletoIds)) {
                        $sheet->fromArray([[
                            $log->client_name,
                            $log->sent_at ? $log->sent_at->format('d/m/Y') : '',
                            '',
                            $reason,
                            null,
                            $log->payment_type ?? 'Outro',
                        ]], null, 'A'.$sectionStart);
                        $sectionStart++;
                        continue;
                    }
                    $invList = Invoice::whereIn('id', $boletoIds)->with('cliente')->get();
                    foreach ($invList as $inv) {
                        $clientName = $inv->cliente_nome ?: ($inv->cliente->name ?? $log->client_name ?? 'Cliente');
                        $dateStr = $inv->data_vencimento ? $inv->data_vencimento->format('d/m/Y') : ($log->sent_at ? $log->sent_at->format('d/m/Y') : '');
                        $descricao = $inv->descricao ?: '';
                        $valor = (float) ($inv->saldo_devedor ?? $inv->valor_original ?? 0);
                        $sectionTotal += $valor;
                        $sheet->fromArray([[
                            $clientName,
                            $dateStr,
                            $descricao,
                            $reason,
                            $valor,
                            $inv->payment_type ?: 'Outro',
                        ]], null, 'A'.$sectionStart);
                        // estilo de número
                        $sheet->getStyle("E{$sectionStart}")->getNumberFormat()->setFormatCode('#,##0.00');
                        // borda da linha
                        $sheet->getStyle("A{$sectionStart}:F{$sectionStart}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFDDDDDD'));
                        $sectionStart++;
                    }
                }
                // Total da seção
                $sheet->setCellValue("D{$sectionStart}", "Total {$reason}");
                $sheet->setCellValue("E{$sectionStart}", "=SUM(E".($totalRow+3).":E".($sectionStart-1).")");
                $sheet->getStyle("D{$sectionStart}:E{$sectionStart}")->getFont()->setBold(true);
                $sheet->getStyle("D{$sectionStart}:E{$sectionStart}")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
                $sectionStart += 2; // separação entre motivos
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
        $sheet->getStyle('A1:A6')->getFont()->setBold(true)->setSize(12);
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
}
