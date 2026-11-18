# R06 — Registro de desvios (declarado vs. implementado)

> **Registro da rodada (record).** Desvios da implementação em relação ao planejado no `design.md`, na ordem em que ocorreram. Cada entrada: planejado → implementado → razão → decisão → documento de referência atualizado. Fonte das entradas 1–3: tarefa #131, commit `d3bfc6bef9` (2026-11-18).

---

## #131 — Colunas por método (CA-18)

### 1. Rótulos de cabeçalho por método

- **Planejado (design.md §4.2):** mapa único de rótulos mantido junto ao formatador e **referenciado pelos texts.php dos componentes** (consumo via config/props); o JobType de planilha importa o mesmo mapa.
- **Implementado:** rótulos centralizados no `AppealReview/ResultFormatter::headerLabels` (fonte única — `ResultFormatter.php:114-148`), consumidos via config/jsObject pelos `init.php` dos dois componentes (`opportunity-registrations-table/init.php:106-107`, `opportunity-evaluations-table/init.php`) e diretamente pelo JobType de planilha de inscrições (`Spreadsheets/JobTypes/Registrations.php`). Nenhum rótulo duplicado em texts.php.
- **Razão:** texts.php é um array estático consumido pelo JS — referenciá-lo como fonte exigiria duplicar os rótulos em 3 lugares (2 texts.php + JobType); além disso o JobType é backend puro e **não possui texts.php**. A centralização no formatador mantém a fonte única exigida pelo design com um único ponto de manutenção.
- **Decisão:** verificado pelo facilitador na revisão do commit `d3bfc6bef9` (2026-11-18).
- **Documento de referência atualizado:** design.md §4.2 (nota as-built).

### 2. Vocabulário do rótulo na habilitação documental

- **Planejado:** rótulo "Habilitada/Inabilitada" (feminino).
- **Implementado:** "Habilitado/Inabilitado".
- **Razão:** é o mapa do próprio método — `EvaluationMethodQualification::getEvaluationStatues` (`EvaluationMethodQualification/Module.php:98-106`) define `valid => Habilitado` / `invalid => Inabilitado`, e `ResultFormatter::formatValue` delega ao `valueToString` do método (`ResultFormatter.php:71-78`). É o mesmo vocabulário já usado na coluna "Resultado do avaliador"; CA-18 pede rótulos condizentes com o método, e divergir do mapa criaria dois vocabulários para o mesmo valor.
- **Decisão:** verificado pelo facilitador na revisão do commit `d3bfc6bef9` (2026-11-18).
- **Documento de referência atualizado:** design.md §4 (nota as-built).

### 3. Resolução do método de avaliação nas colunas

- **Planejado:** método detectado via `isTechnicalEvaluationPhase` (init.php:293 na base da exploração; hoje `opportunity-registrations-table/init.php:304`).
- **Implementado:** `ResultFormatter::methodForOpportunity` resolve o método da fase **principal** (`ResultFormatter.php:155-166`): se a oportunidade é fase de recurso (`STATUS_APPEAL_PHASE` com `parent`), sobe para a fase pai antes de ler o EMC.
- **Razão:** na lista de inscrições da fase de recurso, o EMC próprio da fase é `continuous` — usar o EMC local (ou um detector binário "é técnico?") esconderia a coluna "Diferença" incorretamente quando a fase principal é técnica e vice-versa. As colunas refletem a fase onde vivem os slots corrigidos (fase principal).
- **Decisão:** verificado pelo facilitador na revisão do commit `d3bfc6bef9` (2026-11-18).
- **Documento de referência atualizado:** design.md §4 (nota as-built).
