<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 * @var int $assignmentId
 * @var OpportunityAppealPhase\Entities\RegistrationAppealReview $assignment
 */

use MapasCulturais\i;

$this->layout = 'default';

$this->import('
    mc-breadcrumb
    opportunity-appeal-correction-evaluation
');

$breadcrumb = [
    ['label' => i::__('Início'), 'url' => $app->createUrl('panel', 'opportunities')],
    ['label' => i::__('Painel de controle'), 'url' => $app->createUrl('panel', 'opportunities')],
    ['label' => i::__('Correção de avaliação (recurso)')],
];

$this->breadcrumb = $breadcrumb;
?>

<div class="main-app">
    <mc-breadcrumb></mc-breadcrumb>
    <opportunity-appeal-correction-evaluation :assignment-id="<?= (int) $assignmentId ?>"></opportunity-appeal-correction-evaluation>
</div>
