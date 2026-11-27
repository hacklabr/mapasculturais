<?php

/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    evaluation-method-qualification--apply
');
?>

<div class="col-4 text-right">
    <evaluation-method-qualification--apply :entity="phase" :entities="entities"></evaluation-method-qualification--apply>
</div>
