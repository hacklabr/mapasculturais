# Scope of round R07 — Aplicar resultados das avaliações na habilitação documental

## Variant
condensed

## Requirements introduced
<!-- New RFs/RNFs created in this round -->
_(nenhum — a rodada estende um requisito existente)_

## Requirements changed
<!-- Existing RFs/RNFs modified in this round, with a summary of the change -->
- RF-A13 — before: aplicação de resultados em massa nos métodos simple, documentary, continuous e technical, com lista fixa de status de destino (0, 2, 3, 8, 10) | now: inclui o método qualification (habilitação documental) com as abas "Por resultado" (Habilitado/Inabilitado) e "Por inscrição"; os status de destino passam a ser lidos da Configuração de status da fase (`statusLabels`), excluindo apenas Rascunho do seletor — divergência deliberada do padrão dos demais métodos (lista fixa), decidida na triagem do briefing (2026-11-26) e ratificada na avaliação de viabilidade (#143).

## Requirements discontinued
<!-- RFs/RNFs removed from the reference PRD, with reason -->
_(nenhum)_

## Out of scope for this round
<!-- What was explicitly left out — NEVER empty:
     empty out of scope is a smell of unconsidered scope -->
- Mudar o comportamento do recurso nos outros quatro métodos.
- Mexer na máquina de status das inscrições.
- O fluxo de recurso (appeal).
- Auto-aplicação automática (o qualification já a tem por configuração).
