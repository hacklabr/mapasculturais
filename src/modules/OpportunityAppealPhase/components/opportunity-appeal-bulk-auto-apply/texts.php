<?php
use MapasCulturais\i;

return [
    'title' => i::__('Autoaplicar resultado nas designações de correção'),
    // QA 2026-11-23 (#134): copy alinhada ao comportamento real — o lote só
    // marca designações SEM o flag; já-marcadas são informativas; desmarcar
    // é pelo modal de designação enquanto a correção não foi enviada.
    'description' => i::__('Marque as designações que devem aplicar o resultado consolidado no status da inscrição quando o corretor enviar a correção. O lote marca somente as designações que ainda não têm a marcação — as já marcadas aparecem apenas como informação, sem seleção. Para desmarcar, use o modal de designação enquanto a correção não foi enviada; após o envio, a marcação não pode ser alterada.'),
    'loading' => i::__('Carregando designações...'),
    'endpoint unavailable' => i::__('A API de designações de correção não está registrada no backend.'),
    'eligible context required' => i::__('Este modal só pode ser aberto na fase de recurso, por gestores da fase principal, em método de avaliação não-técnico.'),
    'load error' => i::__('Não foi possível carregar as designações de correção.'),
    'empty designations' => i::__('Não há designações de correção elegíveis à autoaplicação nesta fase de recurso (apenas designações ativas e oficiais são elegíveis).'),
    'select all' => i::__('Selecionar todos'),
    'selected count' => i::__('%s selecionada(s)'),
    'select at least one' => i::__('Selecione ao menos uma designação para marcar a autoaplicação.'),
    'current result' => i::__('Resultado atual'),
    'already auto apply' => i::__('Autoaplicação já marcada'),
    'all already marked' => i::__('Todas as designações elegíveis já estão com a autoaplicação marcada. Para desmarcar, use o modal de designação enquanto a correção não foi enviada.'),
    // QA 2026-11-23 (#134): família "marcar" — o lote só marca; a aplicação
    // do resultado acontece no envio da correção pelo corretor. O template
    // já acrescenta o contador "(N)" ao lado do rótulo.
    'apply selected' => i::__('Marcar autoaplicação nas selecionadas'),
    'applying' => i::__('Marcando...'),
    'report summary' => i::__('Autoaplicação marcada em %s designação(ões).'),
    'skipped title' => i::__('Ignoradas'),
    'skipped hint' => i::__('As designações ignoradas não foram alteradas. Elas voltam a ficar elegíveis conforme o motivo informado (por exemplo, após reabertura de correção enviada).'),
    'result not evaluated' => i::__('Não avaliada'),

    // Status da designação (mesmo vocabulário do modal de designação)
    'status designated' => i::__('Designada'),
    'status draft' => i::__('Rascunho'),
    'status sent' => i::__('Enviada'),
    'status reopened' => i::__('Reaberta'),

    // Feedbacks (toast)
    'bulk applied' => i::__('Autoaplicação marcada nas designações selecionadas.'),
    'bulk error' => i::__('Não foi possível marcar a autoaplicação nas designações selecionadas.'),
];
