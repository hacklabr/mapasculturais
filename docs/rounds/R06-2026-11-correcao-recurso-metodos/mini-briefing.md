# Mini-briefing — R06 (Correção de recurso nos demais métodos)

> Registro da rodada (record) — selado no fechamento. Épica: #7, rodada R06. Variante: Condensed. Demanda do time (@israelmelo, 2026-11-18); triagem confirmada. A semântica do autoaplicar consta como proposta pendente de ratificação (ver Constraints).

## Problema
A correção de notas após deferimento de recurso (RF-A30) foi construída **técnica-first** e quatro pontos de contato ainda tratam a avaliação técnica como se fosse a única:

1. **Colunas incondicionais** — "Média original / Média corrigida / Diferença" aparecem para todo método: no simples a "média" é média aritmética de códigos de status (ex.: 6,5 entre Suplente e Selecionada); na habilitação documental as colunas ficam permanentemente "-"; na documental as médias não têm semântica.
2. **Timeline do proponente** — as caixas "Resultado preliminar" (snapshot) e "Resultado final" (vigente) precisam de evidência executada de coerência por método; o fallback do preliminar sem snapshot não cobre o método simples.
3. **Acesso ao ambiente de correção** — auditoria confirmou 4 gaps (listagem de designações só por inscrição; página do corretor sem guard próprio; `createAppealPhaseRegistration` sem `requireAuthentication()`; mensagem STALE de "método técnico").
4. **Sem autoaplicação** — o envio da correção corrige a nota e reconsolida, mas o status da inscrição só muda por aplicação manual do gestor; não existe opção de autoaplicar na designação.

## Métrica de sucesso
Um gestor opera a correção numa oportunidade de método **simplificada, documental ou habilitação documental** sem encontrar nenhum artefato sem sentido para o método: colunas com rótulos e valores condizentes (sem "Diferença"), timeline coerente (preliminar = snapshot; final = vigente/aplicado), acesso íntegro (gestor vê as designações, inclusive por fase; corretor só as próprias; sem permissão bloqueado) e, ao marcar autoaplicar, o resultado é aplicado no envio da correção sem passo manual extra.

## Constraints (triagem, 2026-11-18)
- Técnica **mantém o comportamento atual**: trio de colunas original/corrigida/diferença e aplicação manual de classificação.
- Timeline é tarefa **verify-first**: corrigir apenas o que a evidência executada mostrar inconsistente (fallback do simples sem snapshot é o candidato conhecido); método já correto → documentar evidência e não alterar.
- Acesso: reparar os 4 gaps **sem ampliar acesso** (401/403 preservados); elegibilidade de corretores intocada — observação registrada: sem Comissão de Recursos cadastrada, só o dono do slot é elegível.
- Autoaplicar (PROPOSTA pendente de ratificação do autor da demanda na revisão do PR): momento = no envio da correção; efeito por método = mapeamento consolidado→status reutilizando a autoaplicação do core (técnica mantém aplicação manual); default do checkbox no modal = desmarcado; lote = marcar autoaplicar em massa nas designações selecionadas.
- Edições de `docs/reference/prd.md` coordenadas com #79 (revisão final R03): #79 mergeia primeiro, R06 rebaseia depois — incrementos cirúrgicos.
- Alterações apenas no tema BaseV2 (BaseV1 intocado).

## Out of scope
Tema BaseV1; ampliação de elegibilidade de corretores; mudança de semântica das caixas preliminar/final; mudanças no fluxo de publicação; alteração da regra de cálculo/consolidação dos métodos; reabertura do escopo geral da épica #7.

## Critérios (rascunho desta tarefa, aplicados ao PRD vivo)
CA-18 (colunas por método, #131) · CA-19 (timeline por método, #132) · CA-20 (acesso ao ambiente de correção, #133) · CA-21 (autoaplicar na designação, #134) — ver RF-A30 em [prd.md](../../reference/prd.md).
