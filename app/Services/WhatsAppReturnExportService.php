<?php

namespace App\Services;

use App\Models\WebhookEventLog;
use App\Models\WhatsappMessageLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class WhatsAppReturnExportService
{
    public function build(Collection $logs): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Retorno Mensagens');

        $headers = [
            'Data Envio',
            'Empresa',
            'Numero Remetente',
            'Cliente',
            'Telefone Cliente',
            'Respondida?',
            'Ultima resposta',
            'Texto',
        ];

        $sheet->fromArray([$headers], null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFEFEF');
        $sheet->getStyle('A1:H1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->freezePane('A2');

        $latestByPhone = $this->getLatestMessagesByPhone($logs);

        $row = 2;
        foreach ($logs as $log) {
            $phone = $this->normalizePhone($log->phone_sanitized ?: $log->phone_original);
            $lastMessage = $latestByPhone[$phone] ?? null;
            $sheet->fromArray([[
                $log->sent_at ? $log->sent_at->format('d/m/Y H:i:s') : '',
                $log->connection->empresa_nome ?? '',
                $this->formatSenderNumber($log),
                $log->client_name ?? '',
                $log->phone_sanitized ?: $log->phone_original ?: '',
                $log->responded ? 'Sim' : 'Nao',
                $lastMessage['origin'] ?? '',
                $lastMessage['text'] ?? '',
            ]], null, 'A'.$row);

            $sheet->getStyle("A{$row}:H{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("H{$row}")->getAlignment()->setWrapText(true);
            $row++;
        }

        if ($row > 2) {
            $sheet->getStyle('A1:H'.($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->getDefaultRowDimension()->setRowHeight(20);

        return $spreadsheet;
    }

    protected function getLatestMessagesByPhone(Collection $logs): array
    {
        $phones = $logs->map(function (WhatsappMessageLog $log) {
            return $this->normalizePhone($log->phone_sanitized ?: $log->phone_original);
        })->filter()->unique()->values();

        if ($phones->isEmpty()) {
            return [];
        }

        $latest = [];

        $events = WebhookEventLog::query()
            ->whereIn('phone', $phones->all())
            ->orderByDesc('created_at')
            ->get(['phone', 'from_me', 'payload_json', 'created_at']);

        foreach ($events as $event) {
            $phone = $this->normalizePhone($event->phone);
            if (! $phone || isset($latest[$phone])) {
                continue;
            }

            $text = $this->extractMessageText(is_array($event->payload_json) ? $event->payload_json : []);
            if ($text === '') {
                continue;
            }

            $latest[$phone] = [
                'origin' => $event->from_me ? 'Sistema' : 'Cliente',
                'text' => $text,
            ];
        }

        $missingPhones = array_values(array_diff($phones->all(), array_keys($latest)));
        if (! empty($missingPhones)) {
            $incomingRows = DB::table('incoming_messages')
                ->whereIn('numero_origem', $missingPhones)
                ->orderByDesc('created_at')
                ->get(['numero_origem', 'payload_json']);

            foreach ($incomingRows as $incoming) {
                $phone = $this->normalizePhone($incoming->numero_origem ?? '');
                if (! $phone || isset($latest[$phone])) {
                    continue;
                }

                $payload = json_decode((string) ($incoming->payload_json ?? ''), true);
                $text = $this->extractMessageText(is_array($payload) ? $payload : []);
                if ($text === '') {
                    continue;
                }

                $latest[$phone] = [
                    'origin' => 'Cliente',
                    'text' => $text,
                ];
            }
        }

        return $latest;
    }

    protected function extractMessageText(array $payload): string
    {
        $text = $payload['text']['body']
            ?? $payload['message']['text']['body']
            ?? $payload['body']
            ?? $payload['text']
            ?? null;

        return is_string($text) ? trim($text) : '';
    }

    protected function normalizePhone(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    protected function formatSenderNumber(WhatsappMessageLog $log): string
    {
        $number = $log->whatsappNumber;
        if (! $number) {
            return '';
        }

        $description = trim((string) ($number->description ?? ''));
        if ($description !== '') {
            return $description;
        }

        return trim(implode(' ', array_filter([
            $number->ddi ?? null,
            $number->ddd ?? null,
            $number->phone ?? null,
        ])));
    }
}
