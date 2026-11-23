# R06 — Verificação CA-19 (#132): timeline do proponente por método

> **Registro da rodada (record) — evidência verify-first do design.md §5.** Componente `src/modules/Opportunities/components/registration-status/` (consumido pelo timeline em `opportunity-phases-timeline/template.php:45`; a inscrição de cada fase vem de `getRegistration` — `opportunity-phases-timeline/script.js:186-189`, `$MAPAS.registrationPhases[phaseOpportunity.id]`, entidade viva serializada no render da página). Âncoras `arquivo:linha` referem-se ao estado **neste commit** (com a correção do fallback aplicada); onde a linha mudou em relação à base `d3bfc6bef9`, indico `(base :N)` — o box final deslocou +6 linhas com a inserção do ramo simple.

## Protocolo

Para cada um dos 4 métodos (technical, simple, documentary, qualification):

- **(a)** "Resultado preliminar" exibe o **snapshot** (`preliminaryResultSnapshot`), não o valor vivo pós-correção;
- **(b)** "Resultado final" exibe o **vigente/aplicado** (technical/documentary/qualification: `consolidatedResult` vivo; simple: `registration.status` — doutrina R03/#78).

## Mecanismo comum (vale para os 4 métodos)

**(a) Snapshot — frontend:**

- `registration-status/script.js:195-236` (`loadPreliminaryData`, no `mounted` :34-36): busca **raw** via `/api/registration/find` com `@select=id,preliminaryResultSnapshot` (:225-229), gated por `resultsPublished` (:224 — final OU preliminar publicado). Leitura raw porque o populate do SDK descarta a chave virtual (:221-223).
- `registration-status/script.js:104-117` (`preliminarySnapshotValue`): derivado **somente** de `this.preliminarySnapshot` (:114-116); sem publicação → `null` (:110-112). Nenhuma leitura de `consolidatedResult`/`status` entra nesse computed.
- `registration-status/template.php:28-42`: ramo do snapshot (`v-if="preliminarySnapshotValue !== null"`), um sub-ramo por método — todos formatam `preliminarySnapshotValue`, nunca a entidade viva. Com snapshot presente, o `mc-status` genérico é substituído (:19 via `hasMethodResult`, `script.js:299-314`).
- O ramo está **fora** do gate `showResults` (comentário :21-27) — necessário para o simples sem `publishEvaluationDetails`.

**(a) Snapshot — backend (por que é imune à correção):**

- Metadata registrada não-privada em `OpportunityAppealPhase/Module.php:528-532`; exposição no caminho API coberta por `enrichPreliminaryResultSnapshot` (`Module.php:702-735`).
- Escrita **somente** no ato da publicação preliminar: `Opportunity::publishPreliminaryRegistrations` (`core/Entities/Opportunity.php:1177-1194`) → `writePreliminaryResultSnapshots` (:1229-1256), gravando `getEvaluationResultValue()` por inscrição (formato por método: técnica = número; documental = 1/-1; simples = código do status MIN; qualificação = status do mapa).
- Limpeza somente na despublicação preliminar: `unPublishPreliminaryRegistrations` (:1201-1219) → `clearPreliminaryResultSnapshots` (:1263-1285). Publicação final **não** passa por aqui (comentário :1258-1261).
- Correção de recurso não toca o snapshot: `AppealReview/Service.php::applyOfficialCorrection` (:154-172) apenas grava o slot (:158-165), chama `consolidateResult(true, $slot)` (:167-168) e enfileira pcache (:170-171); `Registration::consolidateResult` (`core/Entities/Registration.php:359-380`) atualiza só a coluna `consolidated_result` (:367, :374-378).

**(b) Final vigente/aplicado:**

- `registration-status/template.php:120` (base :113): box "Resultado final" gated por `publishState.final` = `publishedRegistrations` multi-fonte (`script.js:69-84`).
- `registration-status/template.php:131-153` (base :124-146): um sub-ramo por método, todos lendo a **entidade viva** (`registration.consolidatedResult` ou `registration.status`) — atualizados no render da página e, no fluxo de correção, reescritos por `consolidateResult` (e, no simples, pelo `setStatus` da aplicação).

## Veredito por método

| Método | (a) Preliminar = snapshot | (b) Final = vigente/aplicado | Notas |
|---|---|---|---|
| technical | ✅ `template.php:30` — `formatNote(preliminarySnapshotValue)` | ✅ `template.php:133` (base :126) — `formatNote(registration.consolidatedResult)` | Snapshot numérico gravado no publish (formato por método); final reflete reconsolidação pós-correção |
| simple | ✅ `template.php:41` — `simpleStatusLabel(preliminarySnapshotValue)` + `simpleStatusColor` (código do status MIN congelado) | ✅ `template.php:152` (base :145) — `simpleStatusColor/simpleStatusLabel(registration.status)` | Doutrina R03/#78 documentada em `template.php:144-151` (base :137-144): o FINAL aplicado é o status; divergência vigente×aplicado resolvida pela autoaplicação (#134/§2), fora do escopo |
| documentary | ✅ `template.php:31-40` — `== '1'` (Válido) / `== '-1'` (Inválido) sobre o snapshot | ✅ `template.php:134-143` (base :127-136) — `registration.consolidatedResult == '1'/'-1'` | — |
| qualification | ✅ `template.php:29` — `qualificationLabel(preliminarySnapshotValue)` + `qualificationColor` | ✅ `template.php:132` (base :125) — `qualificationLabel(registration.consolidatedResult)` | — |

**Evidência executada (backend, suites existentes — sem alteração neste commit):** `tests/src/AppealPreliminarySnapshotTest.php` cobre a captura por método (`testTechnicalSnapshotCapturesConsolidated` :159, `testDocumentarySnapshotCapturesConsolidated` :174, `testSimpleSnapshotCapturesConsolidated` :190, `testQualificationSnapshotCapturesConsolidated` :206), a limpeza no unpublish (:221), a preservação na publicação final (:240) e a **imutabilidade sob correção aplicada** (`testSnapshotSurvivesAppliedCorrection` :263); `tests/src/AppealTwoStagePublishTest.php` cobre os gates (`testPreliminaryThenFinalPublish` :86, `testUnpublishPreliminaryDoesNotTouchFinal` :146).

## Correção aplicada (a única esperada)

**Fallback do preliminar sem snapshot** — `registration-status/template.php:49-69` (base :49-62): não tinha ramificação `simple` (apenas qualification :50, technical :51, documentary :52-61). Adicionado o ramo `simple` (:68) espelhando o caso do snapshot (:41) e o do box final (:152): exibe o rótulo do status da inscrição com sua cor (`simpleStatusLabel`/`simpleStatusColor` sobre `registration.status`, helpers já usados pelo componente — `script.js:258-275`; `statuses` = `statusLabels` da oportunidade, `script.js:160-162`), com a doutrina "o aplicado é o status" (R03/#78).

Observações de contorno (comportamento preservado, sem mudança):

- O fallback como um todo permanece dentro do gate `showResults(phase)` (`template.php:44`; `script.js:426-430`) — em fases simples sem `publishEvaluationDetails` o bloco não renderiza, como já ocorria para os demais métodos com o gate; mexer nesse gate seria redesenhar os boxes (fora do escopo CA-19).
- Sem snapshot, o `mc-status` genérico (:19) continua renderizando junto do valor do fallback — comportamento pré-existente comum aos 4 métodos, mantido.

## Outras inconsistências encontradas

**Nenhuma.** Além do fallback do simples (corrigido acima), a verificação por método não encontrou valor vivo onde se esperava snapshot, nem vice-versa:

- O ramo do snapshot (:28-42) não lê `registration.consolidatedResult`/`registration.status` em nenhum método;
- O fallback exibir o consolidado **vivo** sob o rótulo "preliminar" é o comportamento por design comentado em :45-48 (sem publicação em dois estágios / final direto sem preliminar);
- Falha no fetch degrada para o estado vigente sem bloquear a página (docblock `script.js:188-194`), também por design.

## Suítes executadas

Correção **somente frontend** (template do componente) — sem mudança de backend, sem teste novo exigido. Vizinhança da suíte de recurso executada no ambiente dockerizado (PHPUnit 10.5.58, PHP 8.3.36, imagem `tests-mapas`, 2026-11-18):

| Suíte | Resultado |
|---|---|
| `AppealPreliminarySnapshotTest` | OK (7 tests, 21 assertions) |
| `AppealTwoStagePublishTest` | OK (5 tests, 20 assertions) |
| `AppealApplyCorrectionTest` | OK (10 tests, 35 assertions) |
| `AppealAssignmentApiTest` | OK (26 tests, 98 assertions) |
| `AppealCorrectorCycleTest` | OK (3 tests, 37 assertions) |
| `AppealCorrectionNotificationsTest` | OK (7 tests, 24 assertions) |
| `AppealReleasedScopeTest` | OK (3 tests, 16 assertions) |
| `AppealScoreColumnsTest` | OK (13 tests, 117 assertions) |
| `AppealCorrectorEnvironmentTest` | 17/18 OK; 1 erro **pré-existente** |

O erro do `AppealCorrectorEnvironmentTest` (`testCorrectionPageRendersForCorrector`) é `Asset not found: ../node_modules/@vuepic/vue-datepicker/dist/main.css` — o teste renderiza a página completa e a imagem de testes não tem os assets de frontend buildados (`BUILD_ASSETS=0`). Verificado **pré-existente**: o mesmo erro ocorre com a árvore limpa (sem as mudanças deste commit, stash/pop), sem relação com o template alterado.
