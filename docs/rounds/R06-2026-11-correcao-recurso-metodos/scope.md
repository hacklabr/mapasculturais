# Scope — R06 (Correção de recurso nos demais métodos)

> Registro da rodada (record) — **selado na reconciliação (#135, 2026-11-23)**. Épica: #7. Entrega em PR único #137 (merge `02b232d51c`, 2026-11-23), com aceite por veredito de critério em #129–#134.

## Introduzidos
- CA-18 — colunas da lista/exportações por método de avaliação (#131).
- CA-19 — verificação preliminar(snapshot) / final(vigente) por método (#132, verify-first; evidência em `verification-timeline.md`).
- CA-20 — reparos de acesso ao ambiente de correção (#133), incluindo a **visão somente-leitura do gestor** (payload `readOnly`) adicionada no QA.
- CA-21 — autoaplicação na designação (#134): flag na designação (coluna `auto_apply`), checkbox no modal de designação (default desmarcado), botão **"Autoaplicar nas designações de correção"** na área de ações da lista + **modal de seleção em lote** (lote só-marcação: já marcadas informativas e não selecionáveis; desmarcar é individual, no modal de designação, até o envio da correção), aplicação do resultado no envio da correção pelo caminho canônico do core.
- Formulário de correção da **habilitação documental** no ambiente do corretor (#134, QA 2026-11-23 — ramo `isQualification` no `opportunity-appeal-correction-evaluation`: rádio Atende/Não atende/Não se aplica por critério, motivos e obs por seção, espelhando o `qualification-evaluation-form`) — gap descoberto no teste manual: o ambiente não tinha forma de dados para o método.
- Listagem de designações de correção **por oportunidade/fase** para o gestor (antes só por inscrição, N chamadas).
- Rótulos de coluna por método centralizados em **fonte única** `AppealReview/ResultFormatter::headerLabels`, consumida pelos init.php dos componentes via config e diretamente pelo JobType de planilha (desvio 1 — não em texts.php, como rascunhado).
- Coluna booleana de autoaplicação em `registration_appeal_review` (db-update nomeado idempotente `'adiciona auto_apply na registration_appeal_review'`, `db-updates.php:3585` — conforme design #130).

## Mudados (antes → agora)
- **Colunas da fase de recurso** — antes: "original / corrigida / Diferença" incondicionais a todos os métodos (no simples, média de códigos de status; na habilitação, colunas sempre vazias). Agora: técnico mantém o trio; simplificada, documental e habilitação documental exibem rótulos do método e ocultam "Diferença" — na lista e nas exportações.
- **Timeline do proponente** — antes: fallback do "Resultado preliminar" sem snapshot sem ramificação `simple`. Agora: ramificação por método nas duas caixas (verify-first — corrigida apenas a inconsistência confirmada por evidência executada; demais métodos registrados como já corretos, sem alteração).
- **Ambiente do corretor (visão)** — antes: `GET /appealCorrector/correction/{id}` dumb page e ambiente restrito ao corretor designado (a 1ª passada do #133 chegou a bloquear o gestor com guard corretor-only). Agora: visão dual — gestor com `@control` na fase principal abre o ambiente em **somente leitura** (payload `readOnly`, formulário travado, ações de envio ocultas); a escrita permanece exclusiva do corretor designado; a página segue dumb page para usuário logado (guard de página revertido no QA 2026-11-19 — comportamento original), com o bloqueio real no endpoint de dados (`GET /appealCorrector/environment/{id}` → 403 JSON) e o alerta de acesso negado no componente.
- **`createAppealPhaseRegistration`** — antes: sem `requireAuthentication()` na 1ª linha. Agora: com (padrão do AGENTS.md para actions autenticadas).
- **Mensagem de elegibilidade** — antes: "exige fase principal com método técnico" (STALE). Agora: mensagem agnóstica de método (elegibilidade aberta a todos desde F7/#49).
- **Jornadas J3 (designação com autoaplicar/lote e colunas por método) e J4 (preliminar = snapshot / final = vigente)** ajustadas.

## Descontinuados
- Exibição da coluna "Diferença" nos métodos não-técnicos (lista de inscritos, lista de avaliações e exportações).
- Mensagem de elegibilidade com exigência de "método técnico" (STALE desde F7/#49).

## Out of scope da rodada
Tema BaseV1; ampliação de elegibilidade de corretores; mudança de semântica das caixas preliminar/final; mudanças no fluxo de publicação; alteração da regra de cálculo/consolidação dos métodos; reabertura do escopo geral da épica #7.

## Onda de execução (todas entregues com veredito)
#129 (PRD — CA-18..CA-21 no PRD vivo) · #130 (consolidação técnica/design) · #131 (T3 — colunas) · #132 (T4 — timeline) · #133 (T5 — acesso) · #134 (T6 — autoaplicar) — entrega em PR único #137 (merge `02b232d51c`, 2026-11-23), a partir da branch `epic/7-r06-correcao-recurso-metodos`, com 4 rodadas de rework de QA manual dentro do PR — revisão final: #135 (esta reconciliação).
