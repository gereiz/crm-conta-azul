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
     * @param string|null $phone
     * @return string|null Retorna null se o número for inválido/vazio
     */
    public static function sanitize(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // 1. Remove tudo que não é dígito
        $digits = preg_replace('/\D/', '', $phone);

        // 2. Remove zeros à esquerda
        $digits = ltrim($digits, '0');

        if (empty($digits)) {
            return null;
        }

        // 3. DDI: se já possuir DDI (>=12 dígitos), mantém; caso contrário, aplica 55 como padrão
        if (strlen($digits) < 12 && !str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }

        // 4. Lógica do 9º dígito
        // Regra Geral: MANTER o 9º dígito (padrão nacional/internacional atual).
        // Exceção (Solicitação Usuário): Remover 9º dígito APENAS para DDDs de MG (30-39).
        // Motivo: Relato de falha de envio para MG com 9 dígitos via Whapi.
        
        if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
            // Indices: 01 (55), 23 (DDD), 4 (9)
            $ddd = substr($digits, 2, 2);
            $firstDigit = $digits[4];

            // Verifica se é um celular (começa com 9 após o DDD)
            if ($firstDigit === '9') {
                // Verifica se o DDD começa com '3' (Região MG: 31, 32, 33, 34, 35, 37, 38)
                // Usando str_starts_with ou range
                if (str_starts_with($ddd, '3')) {
                    // Remove o 9º dígito (índice 4)
                    $digits = substr($digits, 0, 4) . substr($digits, 5);
                }
            }
        }

        // Validação final de comprimento (apenas para logging ou rejeição, mas aqui retornamos o melhor esforço)
        return $digits;
    }

    /**
     * Verifica se o número sanitizado é um telefone fixo.
     * Considera apenas números do Brasil (DDI 55).
     * 
     * @param string|null $sanitizedPhone
     * @return bool
     */
    public static function isLandline(?string $sanitizedPhone): bool
    {
        if (empty($sanitizedPhone)) return false;

        // Apenas para Brasil
        if (!str_starts_with($sanitizedPhone, '55')) return false;

        // Móvel com 9º dígito tem 13 dígitos (55 + DDD + 9 + XXXX-XXXX)
        if (strlen($sanitizedPhone) === 13) return false;

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
