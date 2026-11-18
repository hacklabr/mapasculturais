# Scope — R03 (Publicação do resultado do recurso)

> Registro da rodada (record) — selado no fechamento. Épica #7 (terceira rodada). Variante Condensed (comportamento em uso + 3 superfícies). Abertura por decisão de @israelmelo (2026-09-22), registrada na épica em 2026-09-23; o briefing da rodada é o próprio registro de triagem na épica (follow-ups pós-R02 validados no edital 65 / fase de recurso 67) — não há artefato mini-briefing separado.

## Introduzidos
- CA-16 — botão único **"Publicar resultado do recurso"** na fase de recurso (estágio único): mesma ação do publicar final (`POST publishRegistrations`, **com selos**), com confirmação e mensagens de sucesso próprias; gate de término da fase (`phaseEnded` do #72) no publicar; **"Despublicar resultado do recurso"** com rótulo e confirmação explícitos.
- CA-17 — gate canônico de exibição no método contínuo (EMC da fase de recurso): `shouldDisplayEvaluationResults` passa a consultar `areRegistrationResultsPublished()` (final OU preliminar com two-stage), mantendo `allow_proponent_response` como disjunção (backward compat).
- Box "Resultado final" do método **simples** lê `registration.status` (status aplicado) — CA-12 ajustado; o box preliminar permanece fiel ao snapshot (#64).

## Mudados (antes → agora)
- Publicação da fase de recurso — antes (R02): o bloco de dois estágios vazava para a instância da fase de recurso (botão "Publicar resultado preliminar", sem sentido ali — problema original da #76); antes (2026-10-15, `2d2494c562`): estágio único com rótulo genérico "Publicar Resultados"/"Despublicar", sem gate de término. Agora: rótulos explícitos do recurso, gate `phaseEnded` e copy própria (`opportunity-phase-publish-config-registration/template.php:171-206`, `script.js:154-181`, `texts.php:17-20`).
- Exibição do resultado do recurso (contínuo) — antes: `publishedRegistrations || allow_proponent_response` (só a publicação FINAL abria a exibição; com recurso deferido e apenas o preliminar publicado, o acompanhamento mascarava o status real com "Aguardando resposta"). Agora: `areRegistrationResultsPublished() || allow_proponent_response` (`EvaluationMethodContinuous/Module.php:587-591`).
- Box "Resultado final" (simples) — antes: `registration.consolidatedResult` (consolidado da avaliação; evidência 65/67: consolidated=3, "não selecionada"). Agora: `registration.status` (resultado aplicado; evidência 65/67: status=8, Suplente) (`registration-status/template.php:137-145`).
- Mensagens da fase de recurso — antes: textos genéricos do estágio único (`sucesso_publicar`/`sucesso_despublicar`). Agora: textos próprios do contexto de recurso (`texts.php:17-20`).
- Métodos `publishRegistration`/`unpublishRegistration` do componente — antes: escolhiam mensagem por ternário em `isAppealInstance`. Agora: exclusivos da fase principal (mensagens de final fixas; a instância do recurso usa `publishAppealRegistration`/`unpublishAppealRegistration`).

## Descontinuados
- Textos genéricos `'sucesso_publicar'`/`'sucesso_despublicar'` do estágio único (removidos de `texts.php`).
- Rótulos genéricos "Publicar Resultados"/"Despublicar" na fase de recurso (estado de 2026-10-15, `2d2494c562`).

## Onda intermediária (2026-10-15 — mesma janela da rodada, registro "R03 — Ajustes da correção de notas e da publicação" na épica)
- `2d2494c562` — fase de recurso volta ao estágio único (rótulo genérico, sem gate) — **superado pelo squash final** `6a26bc0020`.
- `671e86fd75` — botão duplicado de publicação na seção de coleta do recurso removido (publicação só em "Resposta do recurso") — **persiste**.
- `241e5db28b` — aviso na confirmação do "Publicar resultado final" quando a fase de recurso existe e não está publicada (não bloqueia) — **persiste** (`template.php:151-153`).
- `df6767c6a2` (+ teste `a22926912b`) — "Exibir detalhamento" liberado já com o resultado preliminar publicado (o proponente precisa do detalhamento para decidir se recorre) — **persiste** (CA-12).
- Os commits de correção de notas do mesmo registro pertencem à linhagem R01/RF-A30 e estão registrados em `docs/rounds/R01-2026-08-correcao-notas-recurso/`.

## Out of scope da rodada
Tema BaseV1; backend das rotas `publishRegistrations`/`unpublishRegistrations` (inalteradas — mesmos endpoints e selos em 3 camadas); box final dos demais métodos (técnico/qualificação/documental seguem com `consolidatedResult` — pendência técnica declarada na #78); gate de exibição dos demais métodos (a classe base já usa o canônico — verificado na #77, nada a alinhar: `core/EvaluationMethod.php:582-585`); fallback do box preliminar sem snapshot (pré-existente).

## Onda de execução (todas entregues com veredito)
#76 (botão único) · #77 (gate canônico) · #78 (box final com status aplicado) — entrega em squash `6a26bc0020` (2026-10-28), a partir das branches `feature/76-r03-appeal-publish-button`/`feature/77-r03-gate-canonic`/`feature/78-r03-final-box-status` (PRs #80/#81/#82 fechados sem merge; equivalência de conteúdo verificada na reconciliação) — revisão final: #79.
