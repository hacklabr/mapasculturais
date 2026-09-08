# Deviations — R01 (Correção de notas após deferimento de recurso)

### 2026-09-04 — F1 (#17): mecanismo de exposição da coluna na lista de inscritos
- **Planejado:** expor a coluna via hook `component(opportunity-results-table).visibleColumns` (item 3 da issue #17).
- **Implementado:** coluna no mecanismo nativo de colunas da `opportunity-registrations-table`; o hook citado alimenta a tabela de resultados (outra tabela) e segue disponível para o contexto de resultados.
- **Razão:** o hook indicado na issue serve à tabela de resultados, não à lista de inscritos; o mecanismo nativo da tabela de inscritos atende todos os critérios de aceitação sem endpoint novo.
- **Decisão registrada em:** relato do especialista ao facilitador na execução do F1 (2026-09-04).
- **Documento de referência atualizado:** n/a — sem mudança de comportamento do produto (mecanismo interno de UI); o PRD não especifica o mecanismo.

### 2026-09-08 — F1 (#17): lista onde a coluna de designação aparece
- **Planejado:** coluna na lista de inscritos da fase principal (leitura da issue #17; casamento por número com recursos deferidos).
- **Implementado:** coluna na lista de inscritos da fase de recurso, botão apenas nas linhas com status Deferido (10) — status nativo da lista.
- **Razão:** "A coluna deve estar na lista de inscrição da fase de recurso" — decisão do revisor; CA-1/issue #17 eram ambíguos sobre qual lista.
- **Decisão registrada em:** override na épica #7 (2026-09-08, por @israelmelo).
- **Documento de referência atualizado:** docs/reference/prd.md (CA-1 esclarecido).
