# Retrospectiva — R03 (Publicação do resultado do recurso)

> Registro da rodada — reconciliada em 2026-11-18 (#79). Épica #7 (terceira rodada). Variante Condensed. Entregas: onda intermediária 2026-10-15 e squash final `6a26bc0020` em 2026-10-28 (develop local; push posterior).

## O que funcionou
- A rodada absorveu uma substituição de aceite no meio da execução (2026-10-15: rótulo genérico, sem gate) e a sua reversão (2026-10-28) com ambos os momentos registrados na épica — o histórico é recuperável por issue, commit e registro, e o estado final coincide com o aceite original (ver deviations.md).
- O squash final manteve o develop limpo e a equivalência com as branches originais pôde ser verificada na reconciliação (#79): #78 byte-idêntica ao develop; #77 idêntica no trecho R03 (o diff residual é a correção upstream não relacionada de validação de status do avaliador, `EvaluationMethodContinuous/Module.php:143-144`); #76 adaptada estruturalmente, com todos os elementos funcionais da branch presentes no develop (o develop ainda ganha o aviso de recurso não publicado na confirmação do final).
- Escolha acertada de não mexer sem evidência: o box final dos outros 3 métodos segue no consolidado como pendência técnica declarada na própria issue #78 e no código (`registration-status/template.php:142-144`) — o gate do #77 também confirmou que nenhum outro método sobrescreve `shouldDisplayEvaluationResults` (base já canônica, `core/EvaluationMethod.php:582-585`), dispensando mudança.
- Validação independente posterior: o relatório de exploração da R06 (épica, 2026-11-18) confirma os comportamentos as-built — gate canônico (`Opportunity.php:1290-1301`), box final do simples com `registration.status` — citando `arquivo:linha` sobre o develop `2250574e0a` que já contém o R03.

## Sinais de processo
- **Reversão sem override formal:** a reativação do aceite original da #76 (2026-10-28) está no registro de fechamento, mas não gerou um "Override register" no padrão do R02 (2026-09-22) — a substituição de 2026-10-15 foi revertida sem trilha formal de decisão.
- **PRs fechados sem merge + squash adaptado:** as #76/#77/#78 entraram pelo squash `6a26bc0020` "adaptado" sobre o bloco `isAppealInstance` vigente (pós-`2d2494c562`) e os PRs #80/#81/#82 foram fechados sem merge — o conteúdo revisado no PR não é o conteúdo exato do develop; a equivalência precisou ser verificada por diff de conteúdo nesta reconciliação (comparação por hash seria inútil).
- **Push tardio:** os registros de 2026-10-15 e 2026-10-28 declaram "commits locais na develop, ainda sem push" — as issues foram fechadas antes do push; a rastreabilidade dependeu dos registros na épica.
- **Sem CHANGELOG para as superfícies R03:** o Unreleased cobre o seguimento da correção (campos liberados/parecer, 2026-10-28) mas não registra botão do recurso, gate canônico e box final com status aplicado (develop `2250574e0a`) — pendência de release (regra 3 do AGENTS.md).
- **Contradição doc×código pré-existente encontrada (reportada, não forçada):** `jornadas.md` J3.4 ainda diz "Publicar resultado final, **sempre disponível**" — o código exige final não publicado + término da fase (`template.php:135`) e o CA-15 registra o override de 2026-09-22; J3.4/J4.5 também não descrevem o estágio único do recurso (CA-16) nem o gate canônico (CA-17). Correção fora dos deliverables da reconciliação R03 (prd.md apenas) — reportada na #79.
- **Evidência de teste da entrega final:** a onda intermediária (2026-10-15) lista suítes verdes no estado commitado; o registro do squash final (2026-10-28) cita apenas validação em ambiente de desenvolvimento, sem lista de suítes — desconhecido se a suíte completa rodou no estado final (os PRs #80/#81/#82 foram fechados sem merge; a regra do repo pede `bash tests/run.sh` antes de PR).
- O botão do recurso não tem teste automatizado dedicado (conteúdo de template Vue; a suíte é PHPUnit de backend) — coerente com o RNF-11 do PRD, mas o gate `phaseEnded` da UI fica sem proteção de regressão.

## Pendências declaradas (follow-ups, fora da rodada)
- Box final dos demais métodos (técnico/qualificação/documental) segue com `consolidatedResult` — pendência técnica da #78 (já mapeada pela exploração R06/AJUSTE 2, incluindo o fallback `simple` do box preliminar sem snapshot, `registration-status/template.php:49-62`).
- Ambientes que publicaram "preliminar" na fase de recurso antes do R03 seguem com o resultado visível sem botão para despublicá-lo (registro de 2026-10-15; o bloco do recurso lê apenas `publishedRegistrations`, `template.php:177-205`).
- CHANGELOG sem entrada para as superfícies R03 (#76/#77/#78).
- `jornadas.md` J3.4/J4.5 desatualizadas quanto à publicação do recurso (contradição "sempre disponível" + ausência do estágio único e do gate canônico).
