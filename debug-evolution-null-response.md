# Debug Session: evolution-null-response
- **Status**: [OPEN]
- **Issue**: Alguns envios pela Evolution API falham aleatoriamente com `Call to a member function successful() on null`.
- **Debug Server**: http://127.0.0.1:7777/event
- **Log File**: .dbg/trae-debug-log-evolution-null-response.ndjson

## Reproduction Steps
1. Enviar mensagens pela Evolution API em cenários reais de cron/lote.
2. Observar quando o relatório registra o erro `successful() on null`.
3. Comparar o caminho do envio que falhou com o caminho de envio bem-sucedido.

## Hypotheses & Verification
| ID | Hypothesis | Likelihood | Effort | Evidence |
|----|------------|------------|--------|----------|
| A | `tryRequest()` retorna `null` em falhas transitórias e um caminho de envio ainda chama `successful()` sem guarda. | High | Low | Pending |
| B | Existe mais de um método/caminho de envio na `EvolutionWhatsAppService` e apenas parte deles foi protegida antes. | High | Medium | Pending |
| C | O retorno da Evolution vem vazio/em formato inesperado, é capturado por `catch` e propagado como `null` até o chamador. | Medium | Medium | Pending |
| D | O erro acontece em envios específicos de cron/lote ou mídia/template, não no envio simples. | Medium | Medium | Pending |
| E | O relatório está refletindo a exceção genérica, mas a origem real é um método de checagem/conexão reutilizado no fluxo de envio. | Medium | Low | Pending |

## Log Evidence
- Instrumentação adicionada em `app/Services/EvolutionWhatsAppService.php`:
  - A: entrada do `tryRequest()`
  - B: resposta e esgotamento das tentativas HTTP
  - C: exceção capturada durante request
  - D: entrada/saída do `sendMessage()` com pilha resumida do chamador
  - E: entrada/saída do `checkConnection()`
- Log da sessão limpo e pronto para reprodução em `.dbg/trae-debug-log-evolution-null-response.ndjson`

## Verification Conclusion
- Pending
