# Design técnico — R06: correção de recurso nos demais métodos (autoaplicar, colunas, timeline, acesso)

> **Registro da rodada (record) — artefato da issue #130.** Épica #7, rodada R06, variante Condensed. Base: develop `2250574e0a` (branch `epic/7-r06-correcao-recurso-metodos`). Fontes: relatório de exploração pré-implementação (comentário na épica #7, 2026-11-18), PRD vivo `docs/reference/prd.md` (CA-18..CA-21 + bloco "Semântica da autoaplicação"), mini-briefing e scope desta rodada, e o código citado abaixo (âncoras `arquivo:linha` verificadas na base). Tarefas consumidoras: #131 (colunas/CA-18), #132 (timeline/CA-19), #133 (acesso/CA-20), #134 (autoaplicação/CA-21).

---

## 0. Decisões de contorno (valem para todas as seções)

| # | Decisão | Justificativa |
|---|---|---|
| D1 | Autoaplicação **reutiliza o caminho canônico do core** (`EvaluationMethod::applyConsolidatedResult`), não cria um segundo mecanismo de aplicação de status | Mesmo código, mesmos hooks, mesma semântica da aplicação manual do gestor — sem bifurcação de comportamento |
| D2 | Técnica fica **excluída por construção**: o checkbox não é oferecido no modal e, ainda que flagado, o core é no-op (`useAutoApplication() === false`) | CA-21: classificação (nota de corte/suplência/cotas) permanece aplicação manual; o consolidado técnico é nota, não status |
| D3 | `correction_type = record` **nunca autoaplica** (por definição não altera resultado oficial) | `AppealReview/Service.php:86-90` já bifurca official/record; autoaplicação pertence somente ao ramo official |
| D4 | BaseV2 somente; sem mudança de regra de cálculo/consolidação dos métodos; sem ampliar acesso (401/403 preservados) | Constraints da triagem (mini-briefing §Constraints) |

---

## 1. Autoaplicação — persistência (CA-21, #134)

### 1.1 Coluna booleana via db-update nomeado idempotente

Nova closure em **`src/db-updates.php`**, no final do array (após `'create registration_appeal_review table'`, `db-updates.php:3536-3583` — ordem no arquivo importa: a coluna depende da tabela):

```php
'adiciona auto_apply na registration_appeal_review' => function () {
    // PG 11+: ADD COLUMN com DEFAULT constante é metadado-only (não reescreve a tabela).
    if (!__column_exists('registration_appeal_review', 'auto_apply')) {
        __exec("ALTER TABLE registration_appeal_review ADD COLUMN auto_apply BOOLEAN NOT NULL DEFAULT FALSE");
    }
},
```

- **Nome do ledger é imutável** (`db_update(name)` é a chave; renomear reexecuta — ADR 0006). Nome proposto: `'adiciona auto_apply na registration_appeal_review'`.
- Padrão espelhado no update canônico `db-updates.php:3490-3497` (`__column_exists` + `__exec` — DDL essencialica relança e reexecuta no próximo deploy; **não** usar `__try`).
- `NOT NULL DEFAULT FALSE`: linhas existentes (designações legadas) ficam semanticamente "sem autoaplicação" — exatamente o comportamento atual, sem backfill. Alvo da coluna: designação (`registration_appeal_review`), **não** a inscrição.
- Teste de idempotência obrigatório antes do PR (`scripts/db-update.sh` duas vezes; CI não valida migrations).

### 1.2 Espelho na entidade (Doctrine)

`src/modules/OpportunityAppealPhase/Entities/RegistrationAppealReview.php` — seguir o estilo das propriedades vizinhas (`correctedScore`, :169-174):

- docblock `@property bool $autoApply` (lista de `@property`, :19-36);
- campo anotado logo após `correctionType`/`releasedScope` (~:125-132):

```php
/**
 * @var bool
 *
 * @ORM\Column(name="auto_apply", type="boolean", nullable=false)
 */
protected bool $autoApply = false;
```

- getter/setter explícitos (`getAutoApply()`/`setAutoApply()`) pela simetria com `setReleasedScope`/`getCorrectedValue` (:305-333). Nenhuma metadata EAV envolvida — é coluna própria, sem registro em `*-types.php`.

### 1.3 Exposição/serialização

- **API da entidade** (`/api/registrationappealreview` — POST/PATCH/API_find): a coluna passa a serializar como os demais campos primitivos (o controller usa `_finishRequest`/`PATCH_single` do core, `Controllers/RegistrationAppealReview.php:151,215-230`).
- **Ambiente do corretor**: `AppealReview/CorrectorEnvironment.php::build` (:31-96) inclui `autoApply` no payload — a UI do corretor exibe o aviso "o resultado será autoaplicado no envio" quando `true` (transparência do efeito).
- **Modal de designação**: o estado de gestão das designações existentes lê o campo da própria API (§3.2).

---

## 2. Autoaplicação — momento e efeito (CA-21, #134)

### 2.1 Ponto de inserção

`src/modules/OpportunityAppealPhase/AppealReview/Service.php::applyOfficialCorrection` (:154-172), **imediatamente após** `consolidateResult(true, $slot)` (:167-168) e **antes** do enqueue de pcache (:170-171):

```php
$registration = $slot->registration;
$registration->consolidateResult(true, $slot);

if ($review->autoApply) {                       // novo (R06/CA-21)
    $emc = $registration->opportunity->evaluationMethodConfiguration;
    $emc->evaluationMethod->applyConsolidatedResult($registration);
}

$app->enqueueEntityToPCacheRecreation($registration);
$app->persistPCachePendingQueue();
```

- O EMC principal já é exigido resolvível no fluxo (`assertEvaluationMethodResolvable`, :50 e :264-270) — resolução sem novo lookup.
- A chamada fica **dentro da transação** aberta em `applyCorrection` (:81-106) com access control desabilitado (:84): correção + eventual mudança de status são atômicas (falha em qualquer passo → rollback total). Notificações permanecem pós-commit (:108-109).
- `applyRecordCorrection` (:177-201) não recebe a chamada (D3).

### 2.2 Caminho canônico de aplicação (reuso, não reimplementação)

`core/EvaluationMethod.php::applyConsolidatedResult` (:488-544):

1. `useAutoApplication()` (:492) — **false em técnica** (`EvaluationMethodTechnical/Module.php:1114-1117`) → no-op: correção técnica segue apenas reconsolidando a nota (comportamento atual). Nos demais métodos a base retorna `true` (:476-479).
2. Guarda de desempate (:500-502) e guarda "todos os slots SENT" via view `evaluations` (:504-532) — com avaliação parcial pendente, não aplica (mesma semântica do fluxo comum de avaliação).
3. `getConsolidatedAutoApplicationResult($registration)` (:535; wrapper com hook `evaluationMethod({slug}).consolidatedAutoApplicationResult`, :553-559) — **mapeamento por método, definido pelo próprio método**:
   - **habilitação documental (qualification)**: `consolidatedResult == 'valid'` → `STATUS_APPROVED` (selecionada), senão `STATUS_NOTAPPROVED` (não selecionada) — `EvaluationMethodQualification/Module.php:93-96`;
   - **simplificada (simple)**: o consolidado **é** código de status (`_getConsolidatedAutoApplicationResult` retorna `consolidatedResult` puro — `EvaluationMethodSimple/Module.php:339-342`; consolidação = mínimo dos resultados dos avaliadores, :317-331);
   - **documental (documentary)**: `consolidatedResult == 1` → `STATUS_APPROVED`, senão `STATUS_INVALID` — `EvaluationMethodDocumentary/Module.php:363-366`. *(Nota de precisão: o core mapeia inválida → status 2 "Inválida", não 3 "Não selecionada"; a autoaplicação herda essa semântica — coerente com `applyEvaluationsDocumentary`.)*
4. Aplicação: `disableAccessControl()` → **`$registration->setStatus($value)`** → `save()` → `enableAccessControl()` (:537-540). `setStatus` é a API de status da entidade: resolve o destino como `setStatusTo*` e dispara os hooks canônicos `entity(Registration).status({nome})` (`Entities/Registration.php:1207-1268`), além de enfileirar pcache — ou seja, **o mesmo caminho que a aplicação manual do gestor percorre**.

### 2.3 Riscos conhecidos e tratamento

| Risco | Evidência | Tratamento |
|---|---|---|
| Hooks de status — selos | `entity(Registration).status(approved)` aplica selos certificadores em 3 camadas — `Opportunities/Module.php:1072-1138` | Disparam **igual à aplicação manual** (`applyEvaluations*` → `setStatusTo*`): autoaplicar é equivalente a o gestor aplicar, sem efeitos novos. `shouldApplyCertifierSealsOnApproval` continua decidindo por oportunidade |
| Hooks de status — notificações de recurso | `OpportunityAppealPhase/Module.php:385-424` (approved/notapproved/invalid → e-mail/notificação/sync) | Esses hooks têm guarda `$opportunity->status == STATUS_APPEAL_PHASE` (:389, :403, :417) e o alvo da autoaplicação é a inscrição da **fase principal** — ficam naturalmente silenciosos. As notificações da decisão do recurso continuam penduradas no status da inscrição da fase de recurso (decisão do gestor, intocada) |
| Hooks de status — requalificação entre fases | `OpportunityPhases/Module.php:376-408` (`getPreviousPhaseQualificationDql`) | A DQL já aceita `status = 10` **ou** recurso deferido (:398-405); inscrição autoaplicada para selecionada qualifica pela própria condição de status — mesmo resultado da aplicação manual |
| pcache | enqueue+persist at `Service.php:170-171` | Mantido como está: a ordem de inserção (§2.1) garante que o enqueue cubra o estado pós-mudança de status; `setStatus/save` dentro da transação enfileira a própria recriação (fila assíncrona preservada — sem verificação ad-hoc) |
| Notificações pós-commit | `Notifier::notifyCorrectionSent` só após commit — `Service.php:108-109` | Inalterado; autoaplicação roda dentro da transação, notificação continua pós-commit |
| Visibilidade prematura | status alimenta `publishRegistrations` — `Entities/Opportunity.php:1142-1148` (ApiQuery `status EQ(10)`) | A mudança de status **não publica nada**: a query só roda quando o gestor publica (flag `publishedRegistrations`, :1142-1143); a exibição ao proponente continua gateada por `areRegistrationResultsPublished` (CA-17, `Opportunity.php:1290-1301`) e o snapshot do preliminar já gravado não muda |
| Método técnico flagado por engano | — | Dupla proteção (D2): UI não oferece o checkbox; core é no-op (:492 + :1114-1117) |

---

## 3. Checkbox no modal + seleção em lote (CA-21, #134)

### 3.1 Criação — modal `opportunity-appeal-correction-assignment`

Componente em `src/modules/OpportunityAppealPhase/components/opportunity-appeal-correction-assignment/` (`init.php` 327 linhas, `template.php`, `texts.php` — componentes de módulo sem SFC, servidos pelo AssetManager):

- `init.php` (config :70-111): novo `'autoApplyDefault' => false` e exposição do **slug do método da fase principal** por contexto (o init já ramifica por método para `criteriaCatalogs`, :101-105 — technical/documentary têm catálogo, demais `null`).
- `template.php`: checkbox **"Autoaplicar o resultado ao enviar a correção"**, `v-model` em `autoApply`, **default desmarcado**, renderizado somente quando método ∈ {simple, documentary, qualification} (D2). Desabilitado/oculto para técnico com hint em `texts.php`.
- `texts.php`: rótulo, hint de efeito ("o resultado consolidado será aplicado ao status da inscrição quando o corretor enviar a correção") e hint técnico ("a classificação permanece aplicação manual do gestor").
- Payload do POST ganha `autoApply`; `Controllers/RegistrationAppealReview.php::POST_index` (:54-152) faz `$review->autoApply = filter_var($data['autoApply'] ?? false, FILTER_VALIDATE_BOOL);` junto às atribuições :120-149.

### 3.2 Gestão de designações existentes

- `PATCH_single` (:215-230) delega a `guardMutableFields` (:407-491): adicionar `autoApply` ao conjunto de campos mutáveis com cast bool. **Restrição de domínio**: rejeitar (400) a alteração quando `status == STATUS_SENT` — designação enviada já produziu (ou não) o efeito; mudar o flag depois do envio é enganoso. `STATUS_REOPENED` (via `POST_reopen`, :264-311) reabilita a gestão — coerente com o ciclo designado/rascunho/reaberto (`Entities/RegistrationAppealReview.php:45-48`).
- O painel de acompanhamento por slot (CA-13) exibe o estado do flag (coluna/badge), lido da API (§1.3).

### 3.3 Ação em lote na tabela da fase de recurso

> **As-built (override 2026-11-18, teste manual do PR #137):** a seleção de linhas **não** vive mais na tabela. A coluna de seleção adicionada ao `entity-table` (prop `selectable` + `update:selectedIds`) quebrava o layout da tabela — o cabeçalho era renderizado duas vezes (linha do cabeçalho fake + `<thead>`, ambas ganhando a célula de seleção) com uma célula solta ao final. A seleção em lote virou **botão "Autoaplicar nas designações de correção"** na área de ações da lista (ao lado de "Exportar planilha"; rótulo renomeado no QA 2026-11-23 — o lote marca a autoaplicação, aplicada no envio da correção — e o botão passou a usar as mesmas classes dos vizinhos, `button button--primary`, corrigindo a altura), que abre o **modal `opportunity-appeal-bulk-auto-apply`** (`OpportunityAppealPhase/components/`) com a lista das designações elegíveis (ativas, oficiais, método não-técnico) — inscrição + agente + resultado atual + corretor + prazo + status por linha, checkbox por linha (default desmarcado) e "Selecionar todos" — e confirmação `POST /registrationappealreview/bulkAutoApply` com relatório (marcadas/ignoradas+motivos) dentro do modal. O `entity-table` foi revertido ao formato anterior (sem plumbing de seleção). Dados: listagem por oportunidade (§6/CA-20) + `registration/find?id=IN(...)` para os nomes dos agentes (a expansão aninhada `registration.{owner.name}` descarta `consolidatedResult` na ApiQuery). Endpoint de lote inalterado.

**Plumbing novo de seleção de linhas no `entity-table`** (`src/modules/Entities/components/entity-table/` — hoje os checkboxes existem só para filtros avançados e visibilidade de colunas, `template.php:77-78,146-147,171-172`): *(revertido — ver nota as-built acima)*

- Prop opcional (`selectable`) + emissão de seleção (`v-model:selected-ids` ou eventos `update:selectedIds`), com checkbox no cabeçalho ("selecionar todos" da página) e por linha.
- Espelhar os padrões de "selecionar todos" já consolidados na casa: `opportunity-support-config` (`template.php:100-106`, `script.js:23,320`), `fields-visible-evaluators` (`template.php:35-41`, `script.js:25,144`), `registration-editable-fields` (`template.php:42-48`, `script.js:24,74`) — estado `selectAll` + método `toggle*` + `v-model` por item.
- `opportunity-registrations-table` (`src/modules/Opportunities/components/opportunity-registrations-table/`) ativa a seleção somente quando a fase em contexto é a fase de recurso e há designações (o contexto `appealContexts` já é injetado pelo init do modal de designação, `opportunity-appeal-correction-assignment/init.php:89-93`) e uma ação de barra "Marcar autoaplicação" sobre as linhas selecionadas.

**Estratégia de endpoint — decisão: endpoint dedicado** (`POST_bulkAutoApply` em `Controllers/RegistrationAppealReview.php`, convenção `{VERBO}_{acao}`; precedentes de lote no core: `setMultipleStatus`, `Controllers/Registration.php:526-609`). Justificativa contra loop de PATCHes:

1. **Semântica de falha**: uma transação/uma resposta com relatório por item (`{updated: [...], skipped: [{id, motivo}]}`) vs. N chamadas independentes com estado parcial orquestrado no client.
2. **Custo/auditabilidade**: 1 round trip e 1 ponto de `requireAuthentication` + `assertFeatureEnabled` + resolução `@control` por fase principal (agrupando ids por oportunidade), em vez de N requisições re-autenticando e re-validando `guardMutableFields` (:407-491) que retorna a entidade inteira a cada chamada.
3. **Contrato enxuto**: o lote só **marca** (PRD/CA-21 e bloco "Semântica": "ação em lote = marcar autoaplicação em massa... (não desmarcar)") — o endpoint fixa `autoApply = true` e valida: ids existentes, designação **ativa** (não SENT), método da fase principal não-técnico (400 listando rejeitados), tudo com `@control`.

---

## 4. Colunas por método (#131, CA-18)

### 4.1 Formatador compartilhado (backend)

Hoje as colunas são numéricas e incondicionais: `enrichEvaluationScoreColumns` (`OpportunityAppealPhase/Module.php:625-668`) e `enrichRegistrationScoreColumns` (:727-799) computam original/corrigida/diferença; `toNumericScore` (:918-925) transforma não-numérico em `null` (qualification morta; simple = média de códigos de status semântica vazia; documentary 1/−1 produz "média" sem sentido). O método de avaliação **já está disponível** no contexto da tabela (init.php:293, script.js:254-257 — relatório de exploração) mas não é usado nas colunas.

Nova camada formatadora de resultado por método (ex.: `AppealReview/ResultFormatter`, injetada nos dois enrichments e reutilizada pelos jobs de planilha):

- **Entrada**: valor original/vigente do slot ou consolidação da inscrição + slug do método da fase principal.
- **Saída**: (a) **string de exibição** por método — técnica: número (como hoje); simples: rótulo do status; documental: Válida/Inválida; habilitação: Habilitada/Inabilitada; (b) sinal **`showDifference`** — `true` somente para técnica (fora do técnico a coluna "Diferença" some da lista, das avaliações e das exportações).
- Nas médias por inscrição (:727-799, base `fetchScoresByRegistration` :888-912 — só slots SENT), métodos não-técnicos exibem o **consolidado** no formato do método em vez de média aritmética.
- `ApiQuery(...).findResult` hooks permanecem o veículo (:136-143) — colunas virtuais, sem persistência e sem schema.

### 4.2 Rótulos de cabeçalho por método em texts.php (3 pontos)

Hoje inline com `i::__()` em:

1. `opportunity-registrations-table/init.php:101-115` (Média original/corrigida, Diferença);
2. `opportunity-evaluations-table/init.php:183-187` (Nota original/corrigida, Diferença por slot);
3. `Spreadsheets/JobTypes/Registrations.php:249-264` (export nível inscrição).

Mudança: mapa único de rótulos por método (chaves `original`/`corrected`/`difference`) mantido junto ao formatador (§4.1) e **referenciado pelos texts.php dos dois componentes** (os componentes consomem via config/props); o JobType importa o mesmo mapa (backend não tem texts.php — o ponto deixa de definir rótulo inline e passa a consumir a fonte única). Headers por método: técnica mantém o trio; demais métodos recebem rótulos condizentes ("Resultado original"/"Resultado corrigido" ou específicos por método) e **sem** coluna de diferença (`showDifference` controla a presença, inclusive no cabeçalho).

### 4.3 Exportações por slot

Jobs de planilha por slot dos métodos — `EvaluationMethodSimple/JobTypes/Spreadsheet.php`, `EvaluationMethodDocumentary/JobTypes/Spreadsheet.php`, `EvaluationMethodQualification/JobTypes/Spreadsheet.php` — ganham as colunas original/corrigida (formatadas pelo §4.1) quando o método possui exportação por slot (CA-18: "inclusive nas exportações por slot quando o método as possuir").

> **As-built (pós-#131):** rótulos centralizados no `AppealReview/ResultFormatter::headerLabels` (fonte única consumida via config/jsObject pelos init.php dos componentes e diretamente pelo JobType — sem duplicação em texts.php); vocabulário da habilitação é o do próprio método (**Habilitado/Inabilitado**, `getEvaluationStatues`); método resolvido da **fase principal** por `ResultFormatter::methodForOpportunity` (não via `isTechnicalEvaluationPhase` do EMC local). Detalhes em `deviations.md` (#131).

---

## 5. Timeline por método (#132, CA-19) — verify-first

**Protocolo**: para cada um dos 4 métodos, evidência executada (teste automatizado + evidência registrada na rodada) de que:

- "Resultado preliminar" exibe o **snapshot** (`preliminaryResultSnapshot`; metadata `Module.php:527-531`, escrita/limpeza `Entities/Opportunity.php:1229-1285`) — template já ramifica os 4 métodos, incluindo `simple` (`registration-status/template.php:28-42`, ramo simple :41);
- "Resultado final" exibe o **vigente/aplicado** (:124-145; `simple` usa `registration.status`, :145, com a doutrina R03/#78 documentada :137-144).

Comportamento já correto **não se altera** — registra-se a evidência.

**Correção candidata conhecida** — fallback do preliminar **sem snapshot** (`template.php:49-62`) não tem ramificação `simple` (só qualification/technical/documentary, :50-61): adicionar o ramo espelhando :41/:145 — no simples, exibir o **status aplicado** (`registration.status` via `simpleStatusLabel`/`simpleStatusColor`), mantendo a doutrina "o aplicado é o status" (consolidatedResult pode divergir do aplicado — evidência 65/67 citada em :137-141). Só aplicar se a evidência executada confirmar a inconsistência.

A divergência **vigente × aplicado** no simples **não** é corrigida na timeline — resolve-se pelo AJUSTE 4 (autoaplicação, §2: com autoaplicar, consolidado e status convergem no envio da correção).

---

## 6. Acesso ao ambiente de correção (#133, CA-20) — 4 reparos, sem ampliar acesso

1. **Listagem de designações por oportunidade/fase para o gestor** — hoje `API_find` exige `registration` no query string e escopa o resultado à inscrição autorizada (`Controllers/RegistrationAppealReview.php:161-178` + `getAuthorizedRegistrationFromQuery` :497-521), forçando N chamadas (uma por inscrição). Reparo: aceitar também `opportunity` (fase de recurso ou principal) com `@control` na fase principal, escopando por `appeal_phase_id`/`registration_id` (colunas e índices já existem — DDL `db-updates.php:3550-3551,3570-3571`); caminho por `registration` permanece para compat.
2. **Guard próprio em `GET /appealCorrector/correction/{id}`** — hoje a página é "dumb page": `requireAuthentication()` e render, sem verificação por designação (`Controllers/AppealCorrector.php:65-86`); a proteção real vive no fetch do environment (:35-63 → `CorrectorEnvironment::assertCanAccess` :162-183). Reparo: validar o acesso antes do render (reuso de `assertCanAccess`/`build`, expondo-o ou via `CorrectorEnvironment`), respondendo 403/redirect para quem não é o corretor designado nem gestor `@control`. Corretor continua vendo **apenas as próprias** designações (`GET_assignments` :22-33; `listForCorrector` :98-113).
3. **`requireAuthentication()` na 1ª linha de `createAppealPhaseRegistration`** — `OpportunityAppealPhase/Module.php:263-353` entra direto no `try` (:266); guest hoje dependeria do `PermissionDenied` do `save()` (catch :350-352, 403). Padrão da casa: toda action autenticada chama `requireAuthentication()` na primeira linha (AGENTS.md regra 5; `Controller.php:455-463`) → 401 explícito.
4. **Mensagem de elegibilidade agnóstica de método** — `Controllers/RegistrationAppealReview.php:390` diz "exige fase principal com método técnico" (STALE: elegibilidade é agnóstica desde F7/#49; a condição real é `eligibleCorrectors` vazio — sem EMC/fase de recurso ativa/Comissão de Recursos, sobra só o dono do slot, `Entities/RegistrationAppealReview.php:240-278`). Reparo: mensagem sem citar método, mencionando Comissão de Recursos (observação registrada na triagem: sem comitê cadastrada, só o dono do slot é elegível — não é bug).

> **As-built (pós-#133):** três desvios declarados — (1) a âncora de permissão da listagem por oportunidade é sempre a fase principal (`parent`) quando o ID informado é da fase de recurso, não a oportunidade consultada (estritamente mais restritivo); (2) `registration` + `opportunity` combinados na mesma chamada → 400 (determinístico e restritivo); (3) o escopo das fases de recurso é resolvido em PHP pela metadata `appealPhase` (mesmo mapeamento do SQL cru, um caminho de query a menos). Detalhes em `deviations.md` (#133).

> **As-built (rework #133, 2026-11-19 — feedback do teste manual do PR #137):** o reparo 2 acima saiu na 1ª passada como guard **corretor-only** (bloqueava o gestor com `@control` que o CA-20 manda deixar entrar) e respondia **errorJson cru em rota de página**. Rework: acesso de **visualização** dual em `CorrectorEnvironment` — `canView()` = corretor designado (qualquer status, histórico incluso) OU `@control` na fase principal; `canWrite()` = regra de escrita anterior intacta (ativa + titularidade + janela; espelha `Service::applyCorrection`, que revalida no envio) — e o payload do ambiente carrega **`readOnly`** (true para gestor sem designação, corretor fora da janela e designação enviada/encerrada), que o frontend (`opportunity-appeal-correction-evaluation`) consome travando os inputs e ocultando salvar/enviar com aviso "Visualização somente leitura". Denial na página agora **propaga** `PermissionDenied` para o RoutesManager (erro 403 do tema, HTML); `errorJson` permanece só no endpoint AJAX do environment. Guest segue no 401/redirect do `requireAuthentication`. Detalhes em `deviations.md` (entrada 8).

> **As-built (rework #133, 2ª passada — 2026-11-19, feedback do teste manual do PR #137):** o guard de **página** do reparo 2 foi **revertido** — `GET /appealCorrector/correction/{id}` volta a ser **dumb page** para usuário logado (mantém `requireAuthentication` + feature-flag 404), como era antes do #133. Motivo: o comportamento original do produto para usuário logado sem permissão é a página carregar (200) com o **alerta vermelho de acesso negado** do componente, sem renderizar a correção — o 403 de página (mesmo renderizado pelo tema) era regressão de UX. O bloqueio real dos dados **permanece** no endpoint `GET /appealCorrector/environment/{id}` (`assertViewable` → `errorJson` 403, AJAX), inalterado; a view server-side passa **apenas o id** da designação (nenhum dado da correção no HTML). A visão dual `canView`/`canWrite` e o modo `readOnly` do gestor ficam intactos — mudou só o perfil sem-permissão. Detalhes em `deviations.md` (entrada 9).

---

## 7. Testes

Suíte dockerizada (`bash tests/run.sh`; Builders/Directors + transação/rollback; `processJobs`/`processPCache` onde aplicável). Suites existentes a **atualizar** (fixam o comportamento atual que muda) + casos novos:

| Suite | Atualização | Casos novos |
|---|---|---|
| `tests/src/AppealApplyCorrectionTest.php` | correção sem `autoApply` continua não mudando status (regressão da semântica default) | com `autoApply` + método simple/documentary/qualification → status aplicado conforme mapeamento por método (§2.2); técnico com flag → status intacto (no-op); `correction_type=record` com flag → sem aplicação; mudança de status e correção na mesma transação |
| `tests/src/AppealAssignmentApiTest.php` | contrato POST/PATCH | `autoApply` no POST (default false); PATCH do flag em designação ativa; PATCH após SENT → 400; `POST_bulkAutoApply` (marcação em massa, `@control`, rejeição de técnico/SENT/inexistente, relatório updated/skipped) |
| `tests/src/AppealCorrectorCycleTest.php` | ciclo designação→rascunho→envio | flag persistido ao longo do ciclo; reabertura reabilita gestão do flag |
| `tests/src/AppealScoreColumnsTest.php` | colunas numéricas atuais (técnica) | colunas por método (display string + `showDifference` só em técnica) nos 3 pontos (lista, avaliações, exportação nível inscrição) e nos jobs por slot (§4.3) |
| `tests/src/AppealCorrectorEnvironmentTest.php` | payload do ambiente | payload expõe `autoApply`; guard da página `GET /appealCorrector/correction/{id}` (403 para terceiro, 200 para corretor/gestor) |
| `tests/src/RegistrationAppealReviewTest.php` | entidade | coluna/annotation `autoApply` (default false), serialização API |
| `tests/src/AppealCorrectionNotificationsTest.php` | notificações pós-commit | com autoaplicação: notificação continua pós-commit; hooks de recurso (:385-424) silenciosos para a inscrição da fase principal |
| `tests/src/AppealPreliminarySnapshotTest.php` | snapshot do preliminar | evidência verify-first por método (§5); fallback sem snapshot no simples (ramo novo :49-62); final vigente/aplicado nos 4 métodos |
| `tests/src/AppealTwoStagePublishTest.php` | gates de publicação | status autoaplicado **sem** publicação não vaza ao proponente (gate `areRegistrationResultsPublished`) |

Execução na ordem: #131 (colunas) e #133 (acesso) são independentes; #132 (timeline) depende do formatador/labels de #131 apenas para a evidência; #134 (autoaplicação) depende de §1 (schema) e conversa com #132 (convergência vigente×aplicado no simples).

---

## 8. Pendências declaradas

- A semântica da autoaplicação (momento/default/lote) consta no PRD como **PROPOSTA pendente de ratificação** do autor da demanda na revisão do PR (bloco "Semântica da autoaplicação", `docs/reference/prd.md`).
- Mapeamento documental inválida → `STATUS_INVALID` (não "não selecionada") é semântica **do core** (`EvaluationMethodDocumentary/Module.php:363-366`) e é herdada como está; se o dono quiser 3, é mudança de core (out of scope — "alteração da regra de cálculo/consolidação dos métodos").
