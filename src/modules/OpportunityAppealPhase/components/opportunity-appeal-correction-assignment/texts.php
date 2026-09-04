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
    'no designation' => i::__('sem designação'),
    'progress summary' => i::__('%s de %s slots com designação · %s enviadas'),

    // ações e mensagens
    'save' => i::__('Salvar designações'),
    'saving' => i::__('Salvando designações...'),
    'saved' => i::__('Designações criadas com sucesso.'),
    'save error' => i::__('Não foi possível criar as designações.'),
    'endpoint unavailable' => i::__('A criação e o acompanhamento de designações estão temporariamente indisponíveis: a API da entidade de designação (RegistrationAppealReview) ainda não está registrada no backend.'),
];

$app->applyHook('component(opportunity-appeal-correction-assignment).texts', [&$texts]);

return $texts;
