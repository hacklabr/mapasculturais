<?php

use MapasCulturais\App;
use MapasCulturais\i;

$app = App::i();

$texts = [
    // título e cabeçalho
    'title' => i::__('Designar corretores de nota'),
    'registration' => i::__('Inscrição'),
    'empty slots' => i::__('Nenhuma avaliação encontrada para esta inscrição.'),

    // linha por slot
    'correct this score' => i::__('Corrigir esta nota'),
    'select corrector' => i::__('Selecione o corretor'),
    'slot owner tag' => i::__('avaliador do slot'),
    'committee tag' => i::__('Comissão de Recursos'),
    'already designated' => i::__('Já designado'),
    'eligible context required' => i::__('A designação de corretores exige uma fase de avaliação técnica com fase de recurso ativa e comissão configurada.'),

    // acompanhamento
    'status designated' => i::__('Designado'),
    'status draft' => i::__('Rascunho'),
    'status sent' => i::__('Enviado'),
    'status reopened' => i::__('Reaberto'),
    'deadline' => i::__('Prazo'),
    'sent at' => i::__('Enviada em'),
    'last sent at' => i::__('Último envio em'),
    'no designation' => i::__('sem designação'),
    'progress summary' => i::__('%s de %s slots com designação · %s enviadas'),

    // ações e mensagens
    'save' => i::__('Salvar designações'),
    'saving' => i::__('Salvando designações...'),
    'saved' => i::__('Designações criadas com sucesso.'),
    'save error' => i::__('Não foi possível criar as designações.'),
    'loading' => i::__('Carregando avaliações...'),

    // F5 (#45): substituição e cancelamento de designação
    'select substitute' => i::__('Selecione o novo corretor'),
    'confirm substitution' => i::__('Confirmar'),
    'substituting' => i::__('Substituindo...'),
    'corrector replaced' => i::__('Corretor substituído com sucesso.'),
    'replace error' => i::__('Não foi possível substituir o corretor.'),
    'designation canceled' => i::__('Designação cancelada com sucesso.'),
    'cancel designation error' => i::__('Não foi possível cancelar a designação.'),
    'correction reopened' => i::__('Correção reaberta. O corretor foi notificado.'),
    'reopen error' => i::__('Não foi possível reabrir a correção.'),
    'confirm deadline' => i::__('Salvar prazo'),
    'changing deadline' => i::__('Salvando prazo...'),
    'deadline changed' => i::__('Prazo alterado. O corretor foi notificado.'),
    'deadline change error' => i::__('Não foi possível alterar o prazo.'),
    'confirm reopen' => i::__('Confirmar reabertura'),
    'reopening' => i::__('Reabrindo...'),
    'current deadline' => i::__('Prazo atual:'),
    'current deadline expired' => i::__('Prazo encerrado em'),
    'current no deadline' => i::__('Prazo atual: sem prazo definido'),
    'reopen expired deadline' => i::__('O prazo atual já terminou: defina um novo prazo ou marque "Reabrir sem prazo".'),

    // F6 (#49): seletor de escopo de critérios
    'criteria scope' => i::__('Escopo de critérios'),
    'all criteria released' => i::__('todos liberados'),
    'scope count' => i::__('%s de %s critérios'),
    'select at least one criterion' => i::__('Selecione ao menos um critério'),

    // CA-4 / CA-6: visibilidade no ambiente do corretor
    'show appeal opinion' => i::__('Exibir parecer da Comissão de Recursos para o corretor'),
    'show original valuer name' => i::__('Exibir nome do avaliador original para o corretor'),
    'appeal opinion visible' => i::__('Parecer do recurso: visível'),
    'appeal opinion hidden' => i::__('Parecer do recurso: oculto'),
    'original valuer visible' => i::__('Nome do avaliador original: visível'),
    'original valuer hidden' => i::__('Nome do avaliador original: oculto'),

    // CA-4: prazo da designação
    'deadline in past' => i::__('O prazo final precisa ser uma data futura.'),
    'deadline until' => i::__('O corretor poderá corrigir até'),
    'no deadline hint' => i::__('Sem prazo: o corretor pode corrigir a qualquer momento.'),
    'endpoint unavailable' => i::__('A criação e o acompanhamento de designações estão temporariamente indisponíveis: a API da entidade de designação (RegistrationAppealReview) ainda não está registrada no backend.'),
];

$app->applyHook('component(opportunity-appeal-correction-assignment).texts', [&$texts]);

return $texts;
