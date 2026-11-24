# Retrospectiva — R06 (Correção de recurso nos demais métodos)

> Registro da rodada — reconciliada em 2026-11-23 (#135). Épica #7 (sexta rodada). Variante Condensed. Entregas: PR único #137 (merge `02b232d51c`, 2026-11-23), a partir da branch `epic/7-r06-correcao-recurso-metodos`; tarefas #129–#134 fechadas com aceite por veredito de critério (comentários de 2026-11-23 em cada issue).

## Decisões da rodada

- **Modo de aceite: PR único + teste final do humano** — decisão de @israelmelo (2026-11-18): "coloque tudo em um PR só. no final testo tudo". As 6 tarefas entraram na mesma branch épica e o aceite foi dado por veredito de critério em cada issue após o teste manual do PR #137 — sem PRs intermediários por tarefa (contrastando com o modo R03, de PRs por tarefa fechados sem merge + squash final).
- **Ratificação da semântica da autoaplicação** — os valores PROPOSTOS do bloco "Semântica da autoaplicação" do PRD (momento = envio da correção; default desmarcado; lote só-marcação com desmarcar individual até o envio) foram **ratificados pelo autor da demanda no QA manual de 2026-11-23** (registrado no próprio bloco do PRD e na entrada 10 dos desvios).

## O que funcionou

- **Desvios declarados no ato**: as 10 entradas do `deviations.md` foram escritas durante a execução (commits `7d06ba456c`, `c8e26c7387` e as notas as-built dos reworks), cada uma com os 5 campos — a reconciliação não descobriu nenhum desvio novo (veredito + mapeamento commit→entrada na nota de reconciliação do próprio arquivo). Governança saudável: 10 durante / 0 na reconciliação.
- **Verify-first do CA-19**: o protocolo de evidência executada por método (`verification-timeline.md`) confirmou 3 métodos já corretos (sem alteração) e corrigiu exatamente a inconsistência candidata prevista no design (fallback do simples sem snapshot, `714fadfc21`) — nenhuma mudança além da evidenciada.
- **Coordenação de docs com a rodada anterior**: PRD e jornadas editados em incrementos cirúrgicos após o merge da reconciliação R03 (#79, PR #136), como previsto na constraint da triagem — sem conflito de conteúdo (sequência `d15bf11bb9` → `6c5fb02a45`).
- **Ciclo de QA manual com rework dentro do PR**: 4 rodadas de feedback do teste manual do humano (2026-11-18 a 2026-11-23) pegaram problemas reais que a suíte não pega — layout quebrado do `entity-table` (entrada 7), gestor bloqueado pelo guard corretor-only (entrada 8), regressão de UX do 403 de página (entrada 9), no-op enganoso da linha já-marcada (entrada 10), formulário da habilitação documental inexistente (`9525e360bb`). Pelas métricas de saúde invertidas, rework após teste manual é sinal **saudável** (feedback incorporado), não absorção: cada rework virou desvio declarado ou registro de escopo no mesmo dia.
- **Evidência de teste no estado final**: suíte completa dockerizada na branch — **999 testes / 5688 asserções / 0 falhas / 25 erros, todos pré-existentes** com uma única causa raiz (asset `@vuepic/vue-datepicker` com `BUILD_ASSETS=0`), **idênticos na base `2250574e0a`** verificados em worktree separada (corpo do PR #137; logs `/tmp/opencode/r06-full-suite-docker.log`, `/tmp/opencode/r06-base-targeted.log`). O CI não roda testes (regra 2 do AGENTS.md) — a evidência ficou registrada no PR.
- **Ambiente/infra da execução**: banco de dev compartilhado por override; watcher de assets para o frontend; ciclo de vida de worktrees limpo — `git worktree list` no fechamento da rodada:

  ```
  /mnt/ssd-externo/Projetos/Hacklab/mapasculturais                                               2250574e0a [develop]
  /mnt/ssd-externo/Projetos/Hacklab/mapasculturais-worktrees/collapsible-evaluation-sections-26  9dcf275f91 [fix/v7.8.X/collapsible-evaluation-sections-26]
  /mnt/ssd-externo/Projetos/Hacklab/mapasculturais/.worktrees/r06-reconciliacao                  02b232d51c [docs/135-r06-reconciliacao]
  ```

  (checkout principal + worktree da issue #26, ainda aberta, + esta worktree de reconciliação — criada para a #135 e removida após o merge.)

## Sinais de processo

- **Guard de página implementado e revertido dentro da rodada**: o reparo 2 do design §6.2 saiu corretor-only com `errorJson` em rota de página (1ª passada do #133), virou visão dual com 403 do tema (rework) e terminou revertido ao comportamento original (dumb page + alerta do componente) — três estados em 2 dias, todos registrados (entradas 8–9). O design já previa o dual ("nem gestor `@control`"); a 1ª implementação saiu mais restritiva que o próprio design e precisou do teste manual do humano para convergir. Custo pequeno (uma tarefa), mas é o segundo padrão de "implementar mais restrito que o especificado e corrigir no QA" da épica.
- **O guard revertido deixou documento vivo stale**: o CA-20 do PRD ainda citava "guard próprio na página do corretor" como reparo entregue, contradizendo o próprio corpo do CA (que já descrevia o comportamento final restaurado) — corrigido nesta reconciliação (#135). O scope.md rascunho tinha o mesmo desencontro ("Agora: guard explícito") e foi selado com o estado final.
- **Âncoras `arquivo:linha` dos registros driftam com os reworks**: as entradas 4–6 dos desvios citavam linhas da 1ª passada do #133 que os reworks seguintes deslocaram (ex.: `applyOpportunityScopeFromQuery` :569-572 → :747). Resolvido com tabela de drift na nota de reconciliação — os registros mantêm as âncoras do commit que citam (são records), com os valores atuais mapeados uma única vez no fechamento.
- **Sem CHANGELOG para as superfícies R06**: o Unreleased não registra colunas por método, acesso/visão readOnly nem autoaplicação (mesmo sinal da R03 — pendência de release, regra 3 do AGENTS.md).
- **Gap de cobertura frontend confirmado de novo**: os 4 reworks de QA foram todos de comportamento de UI (layout, visão, alerta, seleção) — a suíte PHPUnit de backend estava verde em todos eles. Coerente com o RNF-11, mas a UX das superfícies novas (modal de lote, readOnly) fica sem proteção de regressão automatizada.
- **25 erros pré-existentes da suíte** (asset `@vuepic/vue-datepicker` com `BUILD_ASSETS=0`) seguem exigindo verificação de base a cada evidência de suíte — ruído conhecido, não tratado nesta rodada.

## Pendências declaradas (follow-ups, fora da rodada)

- Mapeamento documental inválida → `STATUS_INVALID` (não "não selecionada") é semântica do core herdada como está (design.md §8) — mudança seria alteração de regra de consolidação, out of scope.
- Box final dos demais métodos segue com `consolidatedResult` — pendência técnica da #78 (herdada da R03; a R06 não a reabriu por ser verify-first de timeline, não de semântica dos boxes).
- CHANGELOG sem entrada para as superfícies R06 (#131–#134).
- 25 erros pré-existentes da suíte de testes (assets não buildados na imagem de testes) continuam gerando verificação de base manual a cada rodada.
