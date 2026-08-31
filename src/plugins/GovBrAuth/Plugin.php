<?php
namespace GovBrAuth;

use MapasCulturais\App;
use MapasCulturais\i;

/**
 * Plugin de autenticação gov.br (Login Único).
 *
 * A lógica do fluxo OIDC vive em Provider (auth.provider).
 * Este Plugin registra metadados de rollback e o domínio de tradução.
 */
class Plugin extends \MapasCulturais\Plugin
{
    public function _init()
    {
        i::load_textdomain('govbr', __DIR__ . '/translations');
    }

    public function register()
    {
        $this->registerUserMetadata('previous_auth_provider', [
            'label' => i::__('Provedor de autenticação anterior', 'govbr'),
            'private' => true,
        ]);
        $this->registerUserMetadata('previous_auth_uid', [
            'label' => i::__('UID de autenticação anterior', 'govbr'),
            'private' => true,
        ]);
    }
}
