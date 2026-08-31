<?php
/**
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 * @var \MapasCulturais\App $app
 * @var string $message
 */

use MapasCulturais\i;

$app = MapasCulturais\App::i();
?>
<div class="login">
    <div class="login__action">
        <div class="login__card">
            <div class="login__card__header">
                <h3><?= i::__('Autenticação Gov.br', 'govbr') ?></h3>
            </div>
            <div class="login__card__content">
                <div class="mc-alert mc-alert--danger" role="alert">
                    <?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="login__buttons" style="margin-top: 1.5rem;">
                    <a class="button button--primary button--large button--md" href="<?= $app->createUrl('auth', '') ?>">
                        <?= i::__('Voltar ao login', 'govbr') ?>
                    </a>
                    <a class="button button--large button--md" href="<?= $app->createUrl('site', '') ?>">
                        <?= i::__('Ir para o início', 'govbr') ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
