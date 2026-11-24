# Deviations — R03 (Publicação do resultado do recurso)

### 2026-10-15 → 2026-10-28 — #76: aceite substituído e reativado (desvio revertido dentro da rodada)
- **Planejado:** botão único "Publicar resultado do recurso", com rótulo explícito, gate de término da fase (#72/`phaseEnded`) e copy própria (issue #76, 2026-09-23).
- **Implementado:** em duas etapas — (1) 2026-10-15 (`2d2494c562`): publicação em estágio único com rótulo genérico "Publicar Resultados"/"Despublicar" e **sem** o gate de término, substituição do #76/PR #80 declarada como decisão do dono no registro de 2026-10-15 na épica ("o recurso volte com botão de antes e siga como era antes"); (2) 2026-10-28 (`6a26bc0020`): aceite original reativado — rótulos explícitos, gate `phaseEnded`, confirmação e mensagens próprias (`opportunity-phase-publish-config-registration/template.php:171-206`).
- **Razão:** a reativação consta do registro de fechamento da rodada na épica (2026-10-28) — a entrega "reativa o aceite original da #76" sobre o bloco `isAppealInstance` vigente; não detalha motivação além do aceite original.
- **Decisão registrada em:** épica #7 — registro de 2026-10-15 (substituição, citando o dono) e registro de fechamento de 2026-10-28 (reativação). Sem "Override register" formal para a reversão (diferentemente da reversão do R02, 2026-09-22).
- **Documento de referência atualizado:** docs/reference/prd.md (CA-16, com a nota histórica).
- **Veredito na reconciliação:** plano == implementado (o desvio existiu durante a execução e foi revertido antes do fechamento).

### 2026-10-28 — #76: guarda de fluxo contínuo removida do despublicar do recurso
- **Planejado:** rótulo explícito no despublicar do recurso (issue #76) — sem menção à guarda de fluxo contínuo (`!firstPhase?.isContinuousFlow || (firstPhase?.isContinuousFlow && firstPhase?.hasEndDate)`) que o despublicar da fase principal mantém e que o bloco do recurso ainda tinha no estado de 2026-10-15 (`2d2494c562`).
- **Implementado:** despublicar do recurso visível sempre que `phase.publishedRegistrations` (`template.php:197-205`), sem a guarda de fluxo contínuo.
- **Razão:** fase de recurso não existe em oportunidades de fluxo contínuo — a criação é bloqueada no backend (`OpportunityAppealPhase/Module.php:167-169`, erro 404 "Não é possível criar recurso em oportunidades com fluxo contínuo"); justificativa registrada em comentário de código da branch original (`feature/76-r03-appeal-publish-button`).
- **Decisão registrada em:** sem registro formal — decorre da entrega (comentário na branch + bloqueio de backend).
- **Documento de referência atualizado:** docs/reference/prd.md (CA-16 — despublicar sempre que publicado).

### 2026-10-28 — #77: evidência E2E prevista não nomeada no registro de entrega
- **Planejado:** E2E no edital 65/fase 67 — recurso deferido + só preliminar publicado na fase de recurso → acompanhamento mostra "Deferido" (issue #77).
- **Implementado:** gate canônico conforme a issue; o registro de entrega diz "Implementado e validado em ambiente de desenvolvimento" sem nomear o edital 65/67 (a #76 cita "edital local opp 1").
- **Razão:** desconhecida — o registro omitiu o dado.
- **Decisão registrada em:** n/a — lacuna de evidência, não de comportamento.
- **Documento de referência atualizado:** n/a — sem contradição doc×código (o gate implementado atende ao comportamento especificado).
