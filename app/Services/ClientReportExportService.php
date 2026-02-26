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
        $start = $filters['start'] ?? null;
        $end = $filters['end'] ?? null;
        $type = $filters['type'] ?? null;
        $search = $filters['search'] ?? null;

        $invQ = Invoice::query()
            ->with('cliente')
            ->where(function ($q) {
                $q->whereNull('saldo_devedor')->orWhere('saldo_devedor', '>', 0);
            })
            ->where(function ($q) {
                $q->whereIn('status', ['OVERDUE', 'ATRASADO', 'PENDING', 'ABERTO'])->orWhereNull('status');
            });
        if ($connectionId) $invQ->where('connection_id', $connectionId);
        if ($start && $end) $invQ->whereBetween('data_vencimento', [$start, $end]);
        if ($search) {
            $invQ->where(function ($q) use ($search) {
                $q->where('cliente_nome', 'like', "%{$search}%")
                  ->orWhere('descricao', 'like', "%{$search}%");
            });
        }
        $invoices = $invQ->orderBy('cliente_nome')->orderBy('data_vencimento')->get();

        $countByClient = $invoices->groupBy('cliente_ca_id')->map->count();

        // Build sheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $this->renderHeader($sheet, $filters);

        $headers = ['Nome do cliente', 'Data', 'Descrição', 'Parecer', 'Valor total da parcela', 'Conta bancária'];
        $sheet->fromArray([$headers], null, 'A8');
        $sheet->getStyle('A8:F8')->getFont()->setBold(true);
        $sheet->freezePane('A9');

        $row = 9;
        foreach ($invoices as $inv) {
            $clientName = $inv->cliente_nome ?: ($inv->cliente->name ?? 'Cliente');
            $dateStr = $inv->data_vencimento ? $inv->data_vencimento->format('d/m/Y') : '';
            $descricao = $inv->descricao ?: '';
            $valor = (float) ($inv->saldo_devedor ?? $inv->valor_original ?? 0);
            $conta = 'Boleto bancário';

            $parecer = $this->resolveParecer($inv);

            $sheet->fromArray([[$clientName, $dateStr, $descricao, $parecer, number_format($valor, 2, ',', '.'), $conta]], null, 'A'.$row);

            // Color by qty
            $qty = (int) ($countByClient[$inv->cliente_ca_id] ?? 1);
            $color = $this->colorByQty($qty);
            if ($color) {
                $sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
            }
            $row++;
        }

        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
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
            ['text' => 'Negociado', 'color' => 'FFFCE4A1'],          // Amarelo claro
            ['text' => 'Pago', 'color' => 'FFD9EAD3'],               // Verde pálido
            ['text' => 'Não cobrado + justificativa', 'color' => 'FFEAD1DC'], // Rosa pálido
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
        // Em branco
        $sheet->setCellValue('B7', 'em branco');
        $sheet->getStyle('B7')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('B7:C7')->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFBBBBBB'));

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

    protected function resolveParecer(Invoice $inv): string
    {
        $phone = preg_replace('/\D+/', '', (string) ($inv->cliente->mobile_phone ?? $inv->cliente->phone ?? ''));
        if (! $phone) return 'N/A';

        $lastLog = WhatsappMessageLog::where('phone_sanitized', $phone)->orderByDesc('sent_at')->first();
        if (! $lastLog) return 'N/A';

        if ((bool) ($lastLog->responded ?? false)) {
            $incoming = \DB::table('incoming_messages')->where('numero_origem', $phone)->orderByDesc('created_at')->first();
            if ($incoming && $incoming->payload_json) {
                $payload = json_decode($incoming->payload_json, true);
                $text = $payload['text']['body'] ?? ($payload['message']['text']['body'] ?? null);
                if ($text) return (string) $text;
            }
        }

        if ($lastLog->delivery_status && strtoupper($lastLog->delivery_status) !== 'N/A') {
            return 'Cobrado';
        }

        return 'N/A';
    }
}
