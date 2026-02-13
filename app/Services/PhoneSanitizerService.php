<?php

namespace App\Services;

class PhoneSanitizerService
{
    /**
     * Sanitiza o número de telefone para envio via WhatsApp (Whapi).
     * Regras:
     * 1. Remove caracteres não numéricos.
     * 2. Remove zeros à esquerda.
     * 3. Garante o DDI 55.
     * 4. Remove o 9º dígito (primeiro 9 do número móvel) se presente,
     *    para garantir o formato antigo de 8 dígitos se assim solicitado,
     *    ou corrigir duplicação.
     *    Nota: O usuário solicitou explicitamente remover o 9º dígito.
     *    Exemplo: 5533998013895 -> 553398013895
     *
     * @return string|null Retorna null se o número for inválido/vazio
     */
    public static function sanitize(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $hasPlus = preg_match('/^\s*\+/', (string) $phone) === 1;

        // 1. Remove tudo que não é dígito
        $digits = preg_replace('/\D/', '', $phone);

        // 2. Remove zeros à esquerda
        $digits = ltrim($digits, '0');

        if (empty($digits)) {
            return null;
        }

        // 3. DDI:
        // - Se usuário informou '+' originalmente, respeitar país informado (não prefixar 55).
        // - Se comprimento >= 12, considerar que já possui DDI.
        // - Se comprimento == 11: manter se for NANP (começa com '1'); caso contrário, prefixar 55 (BR típico sem DDI).
        // - Se comprimento <= 10: prefixar 55.
        if (! str_starts_with($digits, '55')) {
            if ($hasPlus) {
                // Mantém DDI informado
            } elseif (strlen($digits) >= 12) {
                // Já possui DDI (ex.: 351..., 44..., etc.)
            } elseif (strlen($digits) === 11) {
                // Expandido: tratar como internacional se iniciar com códigos válidos de 1–2 dígitos
                $oneDigit = ['1','7']; // NANP, Rússia/Kazakhstan
                $twoDigit = [
                    '20','27', // África (Egito, África do Sul)
                    // Europa
                    '30','31','32','33','34','36','39',
                    '40','41','43','44','45','46','47','48','49',
                    // Américas
                    '51','52','53','54','56','57','58',
                    // Ásia/Oceania
                    '60','61','62','63','64','65','66',
                    // Extremos
                    '81','82','84','86',
                    // Oriente Médio
                    '90','91','92','93','94','95','98','99',
                ];
                // Suporte explícito a DDI de 3 dígitos (ex.: Angola 244)
                $threeDigit = [
                    '212','213','216','218','220','221','222','223','224','225','226','227','228','229','230','231','232','233','234','235','236','237','238','239','240','241','242','243','244','245','248','249','250','251','252','253','254','255','256','257','258','260','261','262','263','264','265','266','267','268','269',
                    '350','351','352','353','354','355','356','357','358','359',
                    '380','381','382','385','386','387',
                    '420','421','423',
                    '965','966','967','968','970','971','972','973','974','975','976','977',
                    '880','852','853','886'
                ];
                $startsInternational =
                    in_array($digits[0], $oneDigit, true) ||
                    in_array(substr($digits, 0, 2), $twoDigit, true) ||
                    in_array(substr($digits, 0, 3), $threeDigit, true);
                if (! $startsInternational) {
                    $digits = '55'.$digits;
                }
            } else {
                $digits = '55'.$digits;
            }
        }

        // 4. Lógica do 9º dígito
        // Regra Geral: MANTER o 9º dígito (padrão nacional/internacional atual).
        // Exceção (Solicitação Usuário): Remover 9º dígito para DDDs 3X, 7X e 8X.
        // Motivo: Relato de falha de envio para regiões específicas via Whapi.

        if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
            // Indices: 01 (55), 23 (DDD), 4 (9)
            $ddd = substr($digits, 2, 2);
            $firstDigit = $digits[4];

            // Verifica se é um celular (começa com 9 após o DDD)
            if ($firstDigit === '9') {
                // Remover para DDDs iniciados em 3, 7 ou 8
                if (in_array($ddd[0], ['3', '7', '8'])) {
                    // Remove o 9º dígito (índice 4)
                    $digits = substr($digits, 0, 4).substr($digits, 5);
                }
            }
        }

        // Validação final de comprimento (apenas para logging ou rejeição, mas aqui retornamos o melhor esforço)
        return $digits;
    }

    /**
     * Verifica se o número sanitizado é um telefone fixo.
     * Considera apenas números do Brasil (DDI 55).
     */
    public static function isLandline(?string $sanitizedPhone): bool
    {
        if (empty($sanitizedPhone)) {
            return false;
        }

        // Apenas para Brasil
        if (! str_starts_with($sanitizedPhone, '55')) {
            return false;
        }

        // Móvel com 9º dígito tem 13 dígitos (55 + DDD + 9 + XXXX-XXXX)
        if (strlen($sanitizedPhone) === 13) {
            return false;
        }

        // Se tem 12 dígitos (55 + DDD + XXXX-XXXX)
        if (strlen($sanitizedPhone) === 12) {
            // O primeiro dígito do número está no índice 4
            // Fixos começam com 2, 3, 4 ou 5
            $firstDigit = $sanitizedPhone[4];

            return in_array($firstDigit, ['2', '3', '4', '5']);
        }

        return false;
    }
}
