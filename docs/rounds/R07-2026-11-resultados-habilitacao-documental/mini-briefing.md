# Mini-briefing — Rodada R07: Aplicar resultados das avaliações na habilitação documental

> **Classe:** RECORD — selado no fechamento da rodada; correções posteriores são adendo datado, nunca reescrita.
> **Origem:** demanda em texto livre (épico #141), triada em 2026-11-26; rascunho aprovado explicitamente pelo humano em 2026-11-26.
> **Variante:** Condensada · **Tarefa de origem:** #142

## Problema / por quê agora

Na habilitação documental, depois de avaliar as inscrições, o gestor precisa mudar o status uma a uma para refletir o resultado. Nos outros quatro métodos existe o botão "Aplicar resultados das avaliações", que faz isso em massa pela lista de inscrições (requisito RF-A13 do documento vivo); no qualification o recurso não existe.

## Para quem / contexto de uso

Gestor do edital (somente quem tem permissão de gerenciar a oportunidade), na lista de inscrições de uma fase com método habilitação documental, após a consolidação das avaliações.

## Comportamento proposto (padrão dos demais métodos)

Botão "Aplicar resultados das avaliações" na área de ações da lista de inscrições, aparecendo só quando o método da fase é qualification, abrindo um modal com duas abas:

- **Por resultado:** escolher o resultado consolidado ("Habilitado" ou "Inabilitado", cada um com a contagem de inscrições) e o status a aplicar; por padrão aplica apenas em inscrições pendentes, com uma caixa para "aplicar para todas as enviadas".
- **Por inscrição:** colar os números das inscrições separados por vírgula e escolher o status a aplicar.

**Status de destino (decisão registrada nesta sessão):** o modal oferece apenas os status **habilitados na Configuração de status da fase** (`statusLabels`), já com os rótulos personalizados definidos ali. Divergência deliberada do padrão — nos outros quatro métodos a lista é fixa (0, 2, 3, 8, 10). A validação do que pode ser aplicado no backend também segue a configuração da fase, não uma lista fixa.

**Mapeamento já existente no método:** Habilitado → Selecionada/aprovada, Inabilitado → Não selecionada/não aprovada (mesmo mapeamento que a auto-aplicação automática do qualification já usa hoje).

## Medida de sucesso

O gestor aplica o resultado consolidado em massa na habilitação documental direto pela tela, sem editar inscrição por inscrição, e a tela confirma quantas inscrições foram atualizadas.

## Restrições

- Seguir o mesmo padrão técnico dos quatro métodos (manutenção simples e previsível).
- Textos traduzíveis (i18n).
- Acesso restrito a quem gerencia o edital.
- Após aplicar, as permissões das inscrições são recalculadas (como já acontece nos demais métodos).

## Fora de escopo

- Mudar o comportamento do recurso nos outros quatro métodos.
- Mexer na máquina de status das inscrições.
- O fluxo de recurso (appeal).
- Auto-aplicação automática (o qualification já a tem por configuração — não é desta rodada).

## Mapa de cobertura (descoberta profunda, demanda de texto livre)

| Âncora | Profundidade | Notas |
|---|---|---|
| Problema / por quê agora | ●●● | Derivado da demanda + ausência confirmada no código |
| Para quem / contexto | ●●● | Derivado do padrão dos quatro métodos |
| Medida de sucesso | ●● | Aplicação em massa funciona e mostra contagem; sem meta de adoção — aceito pelo humano |
| Fronteira fora de escopo | ●●● | 4 itens explícitos |
| Âncora estado atual | ●●● | RF-A13 do documento vivo + as peças dos quatro métodos |

## Critérios de aceite da feature (linguagem testável)

- [ ] Em fase com método habilitação documental, o gestor vê o botão "Aplicar resultados das avaliações" na área de ações da lista de inscrições; em fase com outro método, o botão não aparece.
- [ ] Na aba "Por resultado", ao escolher Habilitado ou Inabilitado e um status, só as inscrições com aquele resultado consolidado mudam de status; sem marcar a caixa, só as pendentes são afetadas; marcando "aplicar para todas as enviadas", todas as enviadas com aquele resultado são afetadas.
- [ ] Na aba "Por inscrição", colar números separados por vírgula aplica o status só a essas inscrições; números inválidos ou inexistentes não quebram a aplicação.
- [ ] O seletor de status de destino lista apenas os status habilitados na Configuração de status da fase, com os rótulos personalizados.
- [ ] Quem não gerencia a oportunidade não vê o botão e não consegue aplicar pela rota.
- [ ] Ao final, a tela informa quantas inscrições foram atualizadas.
