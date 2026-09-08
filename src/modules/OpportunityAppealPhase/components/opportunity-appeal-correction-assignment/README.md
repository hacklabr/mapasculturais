# Componente `<opportunity-appeal-correction-assignment>`

Modal de designação de corretores por slot (F2 / issue #18, épica #7 — RF-A30).
Lista as avaliações da fase principal de uma inscrição com recurso deferido,
uma por avaliador, e permite ao gestor marcar quais notas serão corrigidas e
designar **1 corretor por slot** — restrito ao dono do slot e aos membros da
Comissão de Recursos (mesma composição de `RegistrationAppealReview::eligibleCorrectors()`).

Também exibe o acompanhamento por slot (designado / rascunho / enviado /
reaberto, prazo e data de envio), somente leitura. Substituição de corretor e
reabertura não fazem parte do componente.

## Uso

Não recebe props: é aberto programaticamente por evento global (interface
pública consumida pelo F1). A view só precisa importá-lo e renderizá-lo uma
única vez:

```php
<?php $this->import('opportunity-appeal-correction-assignment'); ?>
<opportunity-appeal-correction-assignment></opportunity-appeal-correction-assignment>
```

Abertura (assinatura documentada também no `init.php`):

```js
window.dispatchEvent(new CustomEvent(
    'opportunity-appeal-correction-assignment:open',
    {
        detail: {
            opportunity: Entity|{id},   // fase principal (avaliativa) da inscrição
            registration: Entity|{id, number?}, // inscrição da fase principal (não a da fase de recurso)
        }
    }
));
```

## Eventos emitidos

| Evento   | Payload | Quando |
|----------|---------|--------|
| `saved`  | `{registrationId}` | Após criar todas as designações marcadas com sucesso |

## Fontes de dados (somente endpoints existentes)

| Dado | Fonte | Observação |
|------|-------|------------|
| Slots da inscrição | `GET /api/registrationevaluation/find` (`registration=EQ(id)`) | API filtra por permissão de visão |
| Comissão de Recursos + fase de recurso | Injeção do `init.php` (`$MAPAS.config.appealCorrectionAssignment`) | Espelha os gates de `eligibleCorrectors()`; exige `@control` |
| Criação/leitura de designações | API canônica de `registrationappealreview` | **Disponível somente quando `endpointAvailable`** — requer controller registrado para a entidade no backend |

### Estado bloqueado (`endpointAvailable = false`)

Enquanto a entidade `RegistrationAppealReview` não tiver controller registrado
no backend (criação é backend de out of scope da F2), salvar e acompanhar
ficam desabilitados: o modal exibe aviso explícito e o botão de salvar fica
inativo. O restante da interface (lista de slots e opções de corretor) opera
normalmente. Quando o controller existir, os fluxos ativam-se sem alteração
neste componente.

## Estilo

A estrutura do diálogo vem do `mc-modal` (`.modal-content`/`.modal__*` + botões
`.button--primary`/`.button--text`, responsivo). O conteúdo interno usa classes
utilitárias do tema (`.semibold`, `.{primary|success|warning|danger}__color`,
`.warning__background`) e o `style.css` local do componente (auto-enfileirado,
sem passo de build — mesmo padrão do `mc-modal`), com tokens `--mc-*`.

## Defaults de criação

Por linha marcada cria-se: `status=DESIGNATED`, `correctionType=official`
(correção oficial), prazo e escopo de critérios livres (`null`). A
parametrização completa (prazo, escopo, `record`, visibilidade do parecer) é
tratada em outra onda da épica (CA-4).
