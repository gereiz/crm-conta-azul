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

        // 3. Garante DDI 55
        // Se tem 10 ou 11 dígitos, assume que falta o 55
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            $digits = '55' . $digits;
        }
        // Se tem menos de 10, provavelmente é inválido para envio completo com DDD,
        // mas se o usuário digitar sem DDD (ex: 998887777), não temos como adivinhar o DDD.
        // Vamos assumir que se não começar com 55, adicionamos.
        if (!str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }

        // 4. Lógica do 9º dígito (Solicitação: remover)
        // Formato esperado BR com 9º dígito: 55 + DDD (2) + 9 + 8 dígitos = 13 dígitos
        // Formato esperado BR sem 9º dígito: 55 + DDD (2) + 8 dígitos = 12 dígitos
        
        // Se tiver 13 dígitos e o terceiro dígito do número local (após 55+DDD) for 9?
        // Estrutura: 55 (0,1) DD (2,3) N (4) XXXX (5-8) XXXX (9-12) -> total 13 chars (indices 0-12)
        // O 9º dígito é o índice 4 (5º caractere da string).
        
        if (strlen($digits) === 13) {
            // Verifica se é um celular (começa com 9 após o DDD)
            // Indices: 01 (55), 23 (DDD), 4 (9)
            if ($digits[4] === '9') {
                // Remove o caractere no índice 4
                $digits = substr($digits, 0, 4) . substr($digits, 5);
            }
        }

        // Validação final de comprimento (apenas para logging ou rejeição, mas aqui retornamos o melhor esforço)
        return $digits;
    }
}
