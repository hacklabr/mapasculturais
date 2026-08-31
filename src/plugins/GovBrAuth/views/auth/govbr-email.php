<?php
/**
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 * @var \MapasCulturais\App $app
 * @var string $message
 * @var string|null $error
 */

use MapasCulturais\i;

$app = MapasCulturais\App::i();
?>
<div class="login">
    <div class="login__action">
        <div class="login__card">
            <div class="login__card__header">
                <h3><?= i::__('E-mail para nova conta', 'govbr') ?></h3>
                <h6><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></h6>
            </div>
            <div class="login__card__content">
                <?php if (!empty($error)): ?>
                    <div class="mc-alert mc-alert--danger" role="alert">
                        <?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
                <form class="login__form" method="post" action="<?= $app->createUrl('auth', 'govbr-email') ?>">
                    <div class="login__fields">
                        <div class="field">
                            <label for="email"><?= i::__('Novo e-mail', 'govbr') ?></label>
                            <input type="email" name="email" id="email" required autocomplete="email" />
                        </div>
                    </div>
                    <div class="login__buttons">
                        <button class="button button--primary button--large button--md" type="submit">
                            <?= i::__('Continuar', 'govbr') ?>
                        </button>
                        <a class="button button--large button--md" href="<?= $app->createUrl('auth', 'govbr-cancel') ?>">
                            <?= i::__('Cancelar', 'govbr') ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
