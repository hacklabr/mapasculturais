# Scope — R06 (Correção de recurso nos demais métodos)

> Registro da rodada (record) — **rascunho no início da rodada; selado na reconciliação (#135)**. Épica: #7.

## Introduzidos
- CA-18 — colunas da lista/exportações por método de avaliação (#131).
- CA-19 — verificação preliminar(snapshot) / final(vigente) por método (#132, verify-first).
- CA-20 — reparos de acesso ao ambiente de correção (#133).
- CA-21 — autoaplicação na designação (#134): flag na designação, checkbox no modal (default desmarcado), seleção em lote + ação em massa na tabela, aplicação no envio da correção.
- Listagem de designações de correção **por oportunidade/fase** para o gestor (hoje só por inscrição, N chamadas).
- Rótulos de coluna por método centralizados em texts.php (hoje inline em 3 pontos).
- Coluna booleana de autoaplicação em `registration_appeal_review` (db-update nomeado idempotente — conforme design #130).

## Mudados (antes → agora)
- **Colunas da fase de recurso** — antes: "original / corrigida / Diferença" incondicionais a todos os métodos (no simples, média de códigos de status; na habilitação, colunas sempre vazias). Agora: técnico mantém o trio; simplificada, documental e habilitação documental exibem rótulos do método e ocultam "Diferença" — na lista e nas exportações.
- **Timeline do proponente** — antes: fallback do "Resultado preliminar" sem snapshot sem ramificação `simple`. Agora: ramificação por método nas duas caixas (verify-first — corrigir apenas o confirmado por evidência executada).
- **Página do corretor** — antes: `GET /appealCorrector/correction/{id}` sem guard próprio (proteção real apenas no fetch do environment). Agora: guard explícito.
- **`createAppealPhaseRegistration`** — antes: sem `requireAuthentication()` na 1ª linha. Agora: com (padrão do AGENTS.md para actions autenticadas).
- **Mensagem de elegibilidade** — antes: "exige fase principal com método técnico" (STALE). Agora: mensagem agnóstica de método (elegibilidade aberta a todos desde F7/#49).
- **Jornadas J3 (designação com autoaplicar/lote e colunas por método) e J4 (preliminar = snapshot / final = vigente)** ajustadas.

## Descontinuados
- Exibição da coluna "Diferença" nos métodos não-técnicos (lista de inscritos, lista de avaliações e exportações).

## Out of scope da rodada
Tema BaseV1; ampliação de elegibilidade de corretores; mudança de semântica das caixas preliminar/final; mudanças no fluxo de publicação; alteração da regra de cálculo/consolidação dos métodos; reabertura do escopo geral da épica #7.

## Onda de execução (planejada)
#129 (PRD — este documento e o mini-briefing) · #130 (consolidação técnica/design) · #131 (T3 — colunas) · #132 (T4 — timeline) · #133 (T5 — acesso) · #134 (T6 — autoaplicar) · #135 (revisão final/reconciliação — sela este escopo).
