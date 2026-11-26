# Desenho técnico — R07: "Aplicar resultados das avaliações" no `EvaluationMethodQualification`

> **Classe:** RECORD — selado no fechamento da rodada; correções posteriores são adendo datado.
> Issue #144 · Épico #141 · Rodada R07 · Variante Condensada · Base de viabilidade: #143 · Aprovado pelo humano em 2026-11-26
> Molde: `src/modules/EvaluationMethodDocumentary/` · Alvo: `src/modules/EvaluationMethodQualification/`

## 1. Análise de encaixe com a arquitetura atual

O recurso se encaixa por **dois hooks e um componente Vue**, replicando a topologia do Documentary. Nenhum arquivo compartilhado é tocado — o port é 100% aditivo dentro do módulo Qualification.

### Arquivos que nascem

**`src/modules/EvaluationMethodQualification/components/evaluation-method-qualification--apply/init.php`** (novo)
Popula o jsObject, seguindo o padrão dos demais métodos (lido pelo AssetManager ao importar o componente; **sem build, sem SFC**):

```php
<?php
use MapasCulturais\i;

$opportunity = $this->controller->requestedEntity;
$em = $opportunity->getEvaluationMethod();

$status_list = [];
$statuses_names = $opportunity->statusLabels ?: $opportunity->evaluationMethodConfiguration->defaultStatuses;

foreach ($statuses_names as $status => $status_name) {
    if ((int) $status !== \MapasCulturais\Entities\Registration::STATUS_DRAFT) {
        $status_list[] = ["status" => $status, "label" => $status_name];
    }
}

$this->jsObject['config']['evaluation-method-qualification--apply'] = [
    'statusList' => $status_list,
    'consolidated_results' => $em->findConsolidatedResult($opportunity),
    'defaultStatusByResult' => ['valid' => 10, 'invalid' => 3],
];
```

Nota de divergência deliberada do molde: o Documentary usa `$opportunity->defaultStatuses` como fallback (rótulos genéricos com 2/8) e lista também 0 e 1 no frontend enquanto o backend aceita apenas `[0,2,3,8,10]` — inconsistência herdada que **não** será replicada. No Qualification o fallback é `$opportunity->evaluationMethodConfiguration->defaultStatuses` (`{1: Pendente, 3: Inabilitado, 10: Habilitado}`), o que garante coerência exata frontend ↔ backend (ver §3.1/§3.2).

**`src/modules/EvaluationMethodQualification/components/evaluation-method-qualification--apply/script.js`** (novo)
Cópia estrutural do Documentary com três adaptações: (a) endpoint `entity.POST('applyEvaluationsQualification', …)`; (b) `valueToString` do resultado consolidado mapeia `'valid' → this.text('Habilitado')`, `'invalid' → this.text('Inabilitado')`, `'0' → this.text('Não avaliada')`, default `value || ''`; (c) `watch`/`computed` sobre `applyData.from` que pré-seleciona o status de destino conforme o mapa `defaultStatusByResult` do jsObject (`valid → 10`, `invalid → 3`), editável pelo gestor. Demais métodos (`parseRegistrationList`, `prepareApplyData`, `resetApplyData`, `changed`, `modalClose`, `reloadPage`) são port verbatim.

**`src/modules/EvaluationMethodQualification/components/evaluation-method-qualification--apply/texts.php`** (novo)
```php
return [
    'Habilitado' => i::__('Habilitado'),
    'Inabilitado' => i::__('Inabilitado'),
    'Não avaliada' => i::__('Não avaliada'),
];
```

**`src/modules/EvaluationMethodQualification/components/evaluation-method-qualification--apply/template.php`** (novo)
Mesma estrutura de modal (`mc-modal` + `mc-tabs` + duas `mc-tab`): aba "Por resultado" (select de resultado consolidado com contagem — `{{valueToString(item.evaluation)}} ({{item.num}} Inscrições)` —, select de status de destino, checkbox "Aplicar para todas as inscrições enviadas" com o texto explicativo sobre o default "somente pendentes") e aba "Por inscrição" (textarea de números separados por vírgula + select de status). Todos os ids renomeados para `evaluation-qualification-*`; todos os textos via `i::__`/`i::_e`.

**`src/modules/EvaluationMethodQualification/layouts/parts/qualification--evaluation-result-apply.php`** (novo)
Part de injeção, análoga a `documentary--evaluation-result-apply.php`: importa `evaluation-method-qualification--apply` e renderiza `<evaluation-method-qualification--apply :entity="phase" :entities="entities">` dentro de `<div class="col-4 text-right">`.

### Arquivos alterados

**`src/modules/EvaluationMethodQualification/Module.php`** — dois hooks adicionados em `_init()`, mais métodos novos:

1. **Renderização do botão** (análogo a `EvaluationMethodDocumentary/Module.php:109-115`):
```php
$app->hook('template(opportunity.registrations.registration-list-actions-entity-table):begin', function($entity){
    if($em = $entity->evaluationMethodConfiguration){
        if($em->getEvaluationMethod()->slug == "qualification"){
            $this->part('qualification--evaluation-result-apply');
        }
    }
});
```
O hook nasce de `applyTemplateHook('registration-list-actions-entity-table', 'begin', …)` em `Opportunities/components/opportunity-registrations-table/template.php:54`, que passa a fase (`$entity`) — por isso o filtro de slug é sobre a EMC da fase e não sobre a oportunidade raiz.

2. **Rota de aplicação** (port de `EvaluationMethodDocumentary/Module.php:193-296`): `POST(opportunity.applyEvaluationsQualification)` — ver especificação completa em §3.4, com a validação de §3.1 no lugar do whitelist fixo `[0,2,3,8,10]` e sem o branch `continuous`.

3. **Métodos novos na classe** `EvaluationMethodQualification\Module`:
   - `getApplyResultStatuses(EvaluationMethodConfiguration $emc): array` — conjunto `{status => label}` de destinos válidos (§3.1);
   - `findConsolidatedResult($opportunity)` — port verbatim do SQL do Documentary (`GROUP BY consolidated_result`, `status > 0`); é genérico e funciona com os valores string `'valid'`/`'invalid'` sem alteração (§3.6).

O helper de aba "Por inscrição" (`findRegistrationIdsForResultApplication`) **não** é portado: já existe na classe-base `EvaluationMethod` (`src/core/EvaluationMethod.php:107-136`) e é herdado.

## 2. Análise de impacto/regressão

**Outros métodos (simple, documentary, continuous, technical):** nada muda. O port é aditivo: nenhum arquivo de `src/core/`, nenhum outro módulo, nenhum tema é alterado. `getEvaluatorStatuses` é lido como referência mas não é chamado nem modificado. Não há colisão de rotas (`applyEvaluationsQualification` é inédita — confirmado por busca) nem de nomes de componente/jsObject (namespacados pelo slug).

**Página de lista de inscrições:** o hook `registration-list-actions-entity-table` dispara em toda fase listada; o closure filtra `slug == 'qualification'` antes de chamar `$this->part(...)`, portanto fases de outro método não renderizam nada (mesmo mecanismo já usado por simple/documentary/continuous/technical sem interferência mútua — os cinco hooks coexistem no mesmo ponto de template). Em fase qualification, apenas o botão/modal novo aparece.

**Fases posteriores (cadeia de fases):** o comportamento desejado é o do Documentary: se `$opp->nextPhase` existir, uma única chamada `$next_phase->enqueueRegistrationSync()` ao final enfileira o job `SyncPhaseRegistrations` que propaga os novos status à próxima fase (RF-A25); **quando não há próxima fase** (caso típico da habilitação documental, que costuma ser a última fase), a guarda `if($next_phase = $opp->nextPhase)` torna o passo um no-op — os status ficam apenas na fase e nada mais acontece, sem erro e sem job. Nada de sync por inscrição (`skipSync = true` desliga o enfileiramento individual, `OpportunityPhases/Module.php`).

**Permissões (pcache):** cada inscrição alterada recebe `__skipQueuingPCacheRecreation = true` (sem enqueue individual); ao final, se `$total > 0`, `$opp->enqueueToPCacheRecreation()` enfileira a recriação agregada — processada de forma assíncrona pela fila existente. Nenhuma verificação ad-hoc: o acesso é `requireAuthentication()` (401) + `$opp->checkPermission('@control')` (403), padrão de duas camadas do repositório.

**Traduções:** as strings novas (template/texts do componente e mensagens da rota via `i::__`) entram no `.pot` na próxima extração do tooling de i18n; locales sem tradução caem no texto-fonte pt-BR — mesmo comportamento dos demais métodos.

**Riscos residuais aceitos:**
- **Request síncrono longo** em fases muito grandes (mitigado por `set_time_limit(0)`/memória ilimitada, mesmo teto do Documentary; sem regressão de padrão).
- **Resultado consolidado desatualizado:** se avaliações forem reabertas após a aplicação, o `consolidated_result` pode divergir do status aplicado — característica herdada do recurso em todos os métodos.
- **Status 2 (Inválida) inalcançável pela aba "Por inscrição":** característica herdada do helper (§3.6).
- **Grupo "Não avaliada" com `status > 0`:** a aba "Por resultado" com a caixa "todas as enviadas" pode atingir inscrições enviadas ainda não avaliadas — comportamento intencional (§3.5), sinalizado pelo rótulo e pela contagem exibida antes da aplicação.

## 3. Decisões de desenho

### 3.1. Validação do status de destino no backend — excluir apenas Rascunho (0)

**Decisão:** construir o conjunto válido a partir de `$opp->statusLabels ?: $emc->defaultStatuses` **excluindo somente `Registration::STATUS_DRAFT`**, num método novo do módulo — **não** chamar `getEvaluatorStatuses` nem alterá-lo.

```php
public function getApplyResultStatuses(EvaluationMethodConfiguration $emc): array
{
    $status_labels = $emc->opportunity->statusLabels ?: $emc->defaultStatuses;

    $statuses = [];
    foreach ($status_labels as $status => $label) {
        if ((int) $status !== Registration::STATUS_DRAFT) {
            $statuses[(int) $status] = $label;
        }
    }

    return $statuses;
}
```

**Justificativa:** (a) o helper do core exclui 0 **e 1** (`EvaluationMethod.php:207-218`), o que contradiz o adendo de 2026-11-26 (Pendente permanece selecionável) — usá-lo direto está descartado; (b) adicionar parâmetro ao helper do core tocaria arquivo compartilhado e acoplaria o core a uma regra de produto que hoje é **divergência deliberada exclusiva deste port** — o método local mantém a mudança dentro do módulo e preserva a regra "port aditivo"; (c) construir a partir de `statusLabels` respeita automaticamente a Configuração de status da fase (status desabilitado simplesmente não está em `statusLabels` → rejeitado no backend) e carrega os rótulos personalizados; (d) **comportamento com `statusLabels` vazio/ausente:** o fallback para `$emc->defaultStatuses` do Qualification (`{1: Pendente, 3: Inabilitado, 10: Habilitado}`) garante conjunto não-vazio, coerente com os rótulos do método — nunca cai no conjunto genérico da oportunidade (que incluiria "Não selecionada"/"Suplente" sem sentido na habilitação documental).

Na rota, a validação substitui o whitelist fixo do molde:
```php
$valid_statuses = array_keys($self->getApplyResultStatuses($opp->evaluationMethodConfiguration));
if (!is_numeric($this->data['to']) || !in_array((int) $this->data['to'], $valid_statuses, true)) {
    $this->errorJson(i::__('O status selecionado não está habilitado na configuração de status da fase'), 400);
    die;
}
$new_status = (int) $this->data['to'];
```
Rascunho (0) nunca é aceito — não faz sentido devolver a inscrição a rascunho por aplicação em massa.

### 3.2. Frontend coerente com o backend

**Decisão:** `statusList` no jsObject é construído pelo `init.php` (código em §1) com **exatamente a mesma expressão** da validação: `$opportunity->statusLabels ?: $opportunity->evaluationMethodConfiguration->defaultStatuses`, menos o status 0, com os rótulos personalizados. O template renderiza `v-for="item in statusList"` sem nenhuma lista hard-coded (diferente do molde Documentary, que mistura filtro `[0,1,2,3,8,10]` no init com whitelist `[0,2,3,8,10]` no backend — inconsistência não replicada). Coerência frontend ↔ backend garantida por construção: mesma fonte, mesmo filtro.

### 3.3. Defaults por resultado consolidado

**Decisão:** Habilitado (`valid`) → pré-seleciona status **10** (`STATUS_APPROVED`); Inabilitado (`invalid`) → pré-seleciona **3** (`STATUS_NOTAPPROVED`) — não 2. O mapa vem do jsObject (`defaultStatusByResult`), aplicado via `watch` em `applyData.from`; o gestor pode trocar o destino antes de aplicar. **Justificativa:** é o mesmo mapeamento que a auto-aplicação do qualification já usa (`EvaluationMethodQualification/Module.php:93-96` — `valid → APPROVED, demais → NOTAPPROVED`); o status 2 (Inválida) pertence à semântica de invalidade do edital, não à inabilitação documental.

### 3.4. Efeitos colaterais — padrão Documentary (não Continuous/Simple)

**Decisão:** port verbatim da sequência `EvaluationMethodDocumentary/Module.php:193-296`:

1. `requireAuthentication()` na primeira linha → `set_time_limit(0)`, `max_execution_time=0`, `memory_limit=-1`;
2. guarda de slug: EMC da oportunidade ≠ `qualification` → `errorJson(…, 400)`;
3. validação de `to` (§3.1) → `$opp->checkPermission('@control')` (ordem do molde: valida input antes de checar permissão — mantido para não divergir);
4. aba "Por inscrição" → helper herdado `findRegistrationIdsForResultApplication`; aba "Por resultado" → DQL `consolidatedResult = :from AND status <> :new_status AND (r.status = 1 | r.status > 0 conforme checkbox)`;
5. loop por inscrição: `skipSync = true` + `__skipQueuingPCacheRecreation = true`, log de progresso, `switch` sobre `setStatusToDraft/Invalid/NotApproved/Waitlist/Approved` com `default: _setStatusTo($new_status)` — necessário porque o conjunto agora é aberto (qualquer status configurado na fase, incl. 1 Pendente e 8 Suplente se habilitados) e `setStatusToSent()` não existe; o `default` com `_setStatusTo` é exatamente o caminho que o molde já usa para casos fora do switch;
6. `$app->em->clear()` por iteração (hidratação controlada em massa);
7. **após o loop, uma única vez:** `if($next_phase = $opp->nextPhase) { $next_phase->enqueueRegistrationSync(); }`;
8. `if ($total > 0) { $opp->enqueueToPCacheRecreation(); }`;
9. resposta `finish(sprintf(i::__('Avaliações aplicadas à %s inscrições'), $total), 200)`.

**Justificativa:** é o padrão vigente nos cinco métodos; qualquer desvio (sync por inscrição, pcache por inscrição, rota assíncrona) criaria um segundo comportamento a manter — explicitamente fora de escopo.

### 3.5. Grupo "Não avaliada" (`consolidated_result = '0'`)

**Decisão:** o grupo aparece no select da aba "Por resultado" com rótulo **"Não avaliada"** (o SQL do molde já o traz, pois agrega todo `consolidated_result` com `status > 0`; `valueToString('0')` no componente devolve o rótulo) e a aplicação sobre ele é **permitida**. **Justificativa:** na habilitação documental as pendentes nunca avaliadas são um caso de uso real de gestão em massa (ex.: inabilitar por decurso de prazo de retificação, ou habilitar em regularização administrativa); o gestor vê a contagem no próprio select antes de decidir, e o default "somente pendentes" limita o estrago. O rótulo "Não avaliada" (já existe como string localizada no módulo, `enqueueScriptsAndStyles`) deixa explícito o que "0" significa — diferente do Documentary, que rotula '0' como "Rascunho em análise".

### 3.6. Abas "Por resultado" e "Por inscrição"

**Por resultado:** port verbatim do `findConsolidatedResult` do Documentary (`Module.php:311-328`): SQL direto `SELECT consolidated_result evaluation, COUNT(*) num FROM registration WHERE opportunity_id = :opportunity AND status > 0 GROUP BY consolidated_result ORDER BY num DESC`. Funciona sem alteração porque a coluna é string e os valores do Qualification são `'valid'`/`'invalid'`/`'0'`. **`not-applicable` e valores legados:** `'not-applicable'` existe apenas por critério (checkbox de item), nunca como resultado consolidado — `_getConsolidatedResult` só produz `'valid'`, `'invalid'` ou `0` (`Module.php:55-85`), portanto **nunca aparece como grupo**; avaliações legadas pré-7.6 já passam por `parseLegacyResult` na consolidação, então o valor gravado em `consolidated_result` é sempre canônico. O `default: value || ''` do `valueToString` do componente cobre qualquer valor inesperado sem quebrar a tela.

**Por inscrição:** helper herdado `findRegistrationIdsForResultApplication` (`src/core/EvaluationMethod.php:107-136`), sem cópia. **Característica herdada registrada:** o conjunto elegível é `{1, 3, 8, 10}` — inscrições em status **2 (Inválida)** não são alcançadas por esta aba, mesmo que 2 esteja habilitado na configuração da fase e selecionado como destino em outra inscrição. Aceito: é o comportamento idêntico dos quatro métodos (mudá-lo seria alterar helper compartilhado — fora de escopo) e, nos defaults do Qualification, 2 nem sequer é um status da fase.

### 3.7. Testes

**Decisão:** estender `tests/src/EvaluationMethodApplyResultsByRegistrationTest.php` (transação + rollback, Builders/Directors existentes):

1. **Caso no provider existente:** adicionar `'habilitação documental' => [EvaluationMethods::qualification, 'qualification', 'applyEvaluationsQualification']` ao `evaluationMethods()`. O teste herdado (`testApplyResultsByRegistration`) já cobre: aba "Por inscrição", número inexistente ignorado sem quebrar, status aplicado só à inscrição listada, resposta 200. Nenhum ajuste no builder é necessário — `EvaluationMethods::qualification` já existe no enum e o `OpportunityBuilder->addEvaluationPhase()` o aceita.

2. **Novo caso `testApplyResultsValidatesDestinationStatusAgainstPhaseConfig`:** mesma montagem (admin logado, oportunidade com fase de avaliação qualification aberta, uma inscrição enviada). Dois sub-cenários:
   - **Destino desabilitado rejeitado:** customizar `statusLabels` da fase **removendo** um status (ex.: 8) e postar `to = 8` → `assertStatus400`; inscrição permanece em 1.
   - **Pendente aceito:** postar `to = Registration::STATUS_SENT` (1) → `assertStatus200` e a inscrição (previamente movida para 3) retorna a Pendente.

3. **Novo caso `testApplyResultsByResultQualification`** (aba "Por resultado"): criar duas inscrições enviadas e gravar `consolidatedResult` `'valid'` em uma e `'invalid'` na outra (atribuição direta + `save()` pelo ciclo da entidade); postar `tabSelected=result`, `from='valid'`, `to=10`, `status='pending'` → só a `'valid'` muda; a `'invalid'` permanece. Cobre o filtro por resultado consolidado e o default "somente pendentes".

Todos com `RequestFactory->POST(controller_id: 'opportunity', …)` + `login($admin)`, seguindo o padrão do arquivo.

### 3.8. i18n

**Decisão:** todas as strings novas nascem marcadas para extração: textos do modal no `template.php` via `i::_e`/`i::esc_attr__` (título "Aplicar resultados das avaliações", rótulos das abas, "Selecione as avaliações", "Selecione o status que deseja aplicar", "Lista de inscrições", textos do checkbox/explicação, "Cancelar", "Aplicar" — a maioria já existe no catálogo por reuso idêntico aos outros métodos); rótulos dinâmicos no `texts.php` via `i::__` ("Habilitado", "Inabilitado", "Não avaliada"); mensagens da rota no `Module.php` via `i::__` (erro de slug, erro de status inválido, mensagem de contagem `sprintf`). Entram no `.pot` na próxima rodada de extração do tooling de i18n do repositório; locales sem tradução caem em pt-BR, como nos demais métodos.

## 4. Decomposição do estágio 3 (teste de acoplamento)

**Resultado: UMA tarefa** — "Implementar aplicação de resultados no Qualification (código + testes)".

**Teste de acoplamento aplicado aos pares candidatos:**
- *Backend (hooks/rota) × Frontend (componente):* separar deixaria a rota sem consumidor real e o componente apontando para endpoint inexistente — nenhum dos dois é revisável/isolável com sentido completo sem o outro (o critério de aceite "botão → modal → aplicação → contagem" atravessa a fronteira). **Acopladas.**
- *Implementação × Testes:* os testes exercem exatamente a rota criada; entregar código sem teste viola a regra do repositório (CI não roda testes — o autor precisa rodar `bash tests/run.sh` antes do PR), e teste sem código não compila o provider novo. **Acopladas.**
- *Frontend × Testes:* já cobertas transitivamente pelos pares acima.

**Fronteira de arquivos da tarefa única:** `src/modules/EvaluationMethodQualification/Module.php` (alterado) · `src/modules/EvaluationMethodQualification/components/evaluation-method-qualification--apply/{init.php, script.js, texts.php, template.php}` (novos) · `src/modules/EvaluationMethodQualification/layouts/parts/qualification--evaluation-result-apply.php` (novo) · `tests/src/EvaluationMethodApplyResultsByRegistrationTest.php` (alterado). Nada fora dessa fronteira é tocado — o que confirma a aditividade e simplifica o diff para contribuição ao upstream.

A vinculação de evidências no RF-A13 e o fechamento do `scope.md` são reconciliação da rodada, não tarefa de implementação.

## 5. Declaração sobre ADR

**Não é necessário.** O critério é decisão nova com consequência duradoura; aqui não há decisão arquitetural nova: o desenho é **reúso direto de um padrão existente** (bulk apply por método de avaliação, já instanciado cinco vezes no repositório), e a única divergência deliberada (status de destino lidos da Configuração de status da fase, excluindo apenas Rascunho) é uma decisão de **produto**, já registrada com data e ratificação humana nos documentos RECORD da rodada (mini-briefing + adendo 2026-11-26), no `scope.md` e no RF-A13 do `prd.md` — camada correta para esse tipo de registro. O helper `getEvaluatorStatuses` permanece intocado e a divergência fica encapsulada no módulo, sem efeito estrutural duradouro sobre o core ou sobre os demais métodos.
