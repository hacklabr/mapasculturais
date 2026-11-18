# R06 — Registro de desvios (declarado vs. implementado)

> **Registro da rodada (record).** Desvios da implementação em relação ao planejado no `design.md`, na ordem em que ocorreram. Cada entrada: planejado → implementado → razão → decisão → documento de referência atualizado. Fonte das entradas 1–3: tarefa #131, commit `d3bfc6bef9` (2026-11-18). Fonte das entradas 4–6: tarefa #133, commit `19285ee424` (2026-11-18).

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

## #133 — Acesso ao ambiente de correção (CA-20)

### 4. Âncora de permissão da listagem por oportunidade

- **Planejado (design.md §6.1):** `@control` na oportunidade consultada (o ID recebido no parâmetro `opportunity`).
- **Implementado:** âncora de permissão no `parent` (fase principal) quando o ID passado é da fase de recurso (`Controllers/RegistrationAppealReview.php::applyOpportunityScopeFromQuery`, `:569-572`, com `checkPermission('@control')` em `:589`); quando o ID é da fase principal (ou raiz), a âncora é a própria oportunidade consultada.
- **Razão:** estritamente mais restritivo — controle só da fase de recurso não concede listagem; `canUser_control` propaga apenas pai→filho (quem controla a fase principal sempre controla as filhas, nunca o inverso). Sem ampliação de acesso (D4).
- **Decisão:** pendente de verificação pelo facilitador na revisão do commit `19285ee424` (2026-11-18).
- **Documento de referência atualizado:** design.md §6 (nota as-built).

### 5. Parâmetros combinados na listagem (`registration` + `opportunity`)

- **Planejado:** design silencioso sobre parâmetros combinados (§6.1 descreve os caminhos por `registration` e por `opportunity` sem definir o comportamento quando ambos são enviados).
- **Implementado:** `registration` + `opportunity` juntos → 400 com mensagem orientando escolher um dos parâmetros (`Controllers/RegistrationAppealReview.php:550-552`).
- **Razão:** determinístico e restritivo — a interseção dos dois escopos seria ambígua (o caminho por inscrição força `registration EQ(n)`, o por oportunidade força `appealPhase IN(...)`); rejeitar elimina a ambiguidade sem reduzir funcionalidade (cada caminho segue disponível isolado).
- **Decisão:** pendente de verificação pelo facilitador na revisão do commit `19285ee424` (2026-11-18).
- **Documento de referência atualizado:** design.md §6 (nota as-built).

### 6. Resolução do escopo de fases de recurso da listagem

- **Planejado:** escopo via SQL cru com o padrão do mapeamento recurso → fase principal usado nas colunas (`mapAppealToMainRegistrations`, `OpportunityAppealPhase/Module.php:963-976`).
- **Implementado:** resolução via metadata `appealPhase` em PHP — para cada fase em escopo (`phasesInOpportunityScope`, `Controllers/RegistrationAppealReview.php:614-629`), lê `$phase->appealPhase` (`:577-582`) e coleta as fases de recurso em `IN(...)`.
- **Razão:** mesmo mapeamento (a metadata é a fonte do join SQL), um caminho de query a menos — entidades já hidratadas pelo repositório, sem duplicar a query cru nem tratar SQL/parâmetros à mão; o volume é o número de fases da oportunidade (pequeno por construção).
- **Decisão:** pendente de verificação pelo facilitador na revisão do commit `19285ee424` (2026-11-18).
- **Documento de referência atualizado:** design.md §6 (nota as-built).
