# Retro — Rodada R07: Aplicar resultados das avaliações na habilitação documental

> Classe: RECORD — selada no fechamento da rodada.

## O que a rodada entregou
Recurso "Aplicar resultados das avaliações" no método habilitação documental (épico #141, implementação #145, PRs #147/#148), com a divergência deliberada de ler os status de destino da Configuração de status da fase (menos Rascunho). 2 desvios, ambos declarados no ato (cast int no statusList; CHANGELOG incluído por decisão do humano no aceite).

## Sinais de processo (9.4)

- **Desvios tardios:** 0 — ambos declarados durante a execução (evento F: durante=2, na revisão=0).
- **Gaps recorrentes:** nenhum.
- **Requisitos novos tardios:** 1 ajuste no aceite (CHANGELOG) — pequeno e absorvido como desvio declarado, sem cerimônia extra.
- **doc-bug:** 0 nesta rodada. **Override de variante/gate:** 0 (a contestação "Minimal × Condensada" na triagem foi resolvida com evidência, sem override).
- **Feedback de produto:** nenhum bug reportado no teste manual; aprovação direta com 1 adição (CHANGELOG).

## Falhas de ferramenta/processos a registrar

1. **Worktree fora do padrão:** a worktree foi criada pela ferramenta do host em `~/.local/share/opencode/worktree/...` (fora de `.worktrees/` dentro do repositório — violação da convenção F006) e, pior, ancorada em OUTRO clone local do repositório, o que quebrou a remoção padrão (restou `.pnpm-store` de root, exigindo remoção via container). Toda a entrega foi commitada/empurrada, nada se perdeu. **Ação:** nas próximas rodadas, criar a worktree manualmente em `.worktrees/<slug>/` via o especialista de operações, não pela ferramenta do host.
2. **Fila de operações:** em duas ocasiões a sessão do especialista de operações reportou apenas o último lote recebido (e uma vez não executou o commit do lote anterior). Detectado por verificação com evidência (regra do fluxo) e corrigido na hora. **Ação:** verificar o estado real (plataforma + git) após cada lote delegado — nunca confiar só no relatório.
3. **Worktrees órfãs de rodadas anteriores (#91, #95)** encontradas nesta reconciliação — tratadas no ato (removidas; trabalho já mergeado em `v7.8.X`). Higiene de worktree era o item novo do checklist justamente por isso.

## Métricas invertidas (anti-bypass #11)
Zero feedback / zero override / zero doc-bug **nesta rodada isolada** — esperado para uma rodada pequena e bem especificada (port de padrão existente). Não é sinal de absorção: houve contestação de variante na triagem (resolvida com evidência) e 1 adição de escopo no aceite (declarada). Acompanhar na consolidação de 3 rodadas.
