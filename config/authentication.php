<?php
return [
    /*
    'auth.provider' => 'Fake',
    'auth.config' => [],
     */

    // login local via módulo LocalAuth do core
    'auth.provider' => 'Local',

    'auth.config' => [
        'salt' => env('AUTH_SALT', 'SECURITY_SALT'),
        'timeout' => '24 hours',
        'strategies' => [
           'Facebook' => [
               'app_id' => env('AUTH_FACEBOOK_APP_ID', null),
               'app_secret' => env('AUTH_FACEBOOK_APP_SECRET', null),
               'scope' => env('AUTH_FACEBOOK_SCOPE', 'email'),
            ],
            
            'Google' => [
                'visible' => (bool) env('AUTH_GOOGLE_CLIENT_ID', false),
                'client_id' => env('AUTH_GOOGLE_CLIENT_ID', null),
                'client_secret' => env('AUTH_GOOGLE_CLIENT_SECRET', null),
                'redirect_uri' => env('BASE_URL', '') . 'autenticacao/google/oauth2callback',
                'scope' => env('AUTH_GOOGLE_SCOPE', 'email profile'),
                'prompt' => env('AUTH_GOOGLE_PROMPT', null),
            ],

            'LinkedIn' => [
                'api_key' => env('AUTH_LINKEDIN_API_KEY', null),
                'secret_key' => env('AUTH_LINKEDIN_SECRET_KEY', null),
                'redirect_uri' => env('BASE_URL', '') . 'autenticacao/linkedin/oauth2callback',
                'scope' => env('AUTH_LINKEDIN_SCOPE', 'r_emailaddress')
            ],

            'Twitter' => [
                'app_id' => env('AUTH_TWITTER_APP_ID', null),
                'app_secret' => env('AUTH_TWITTER_APP_SECRET', null),
            ],

            'govbr' => [
                'visible' => (bool) env('AUTH_GOV_BR_CLIENT_ID', false),
                'client_id' => env('AUTH_GOV_BR_CLIENT_ID', null),
                'client_secret' => env('AUTH_GOV_BR_SECRET', null),
                'scope' => env('AUTH_GOV_BR_SCOPE', 'openid email profile govbr_confiabilidades govbr_confiabilidades_idtoken'),
                'redirect_uri' => env('AUTH_GOV_BR_REDIRECT_URI', null),
                'auth_endpoint' => env('AUTH_GOV_BR_ENDPOINT', null),
                'token_endpoint' => env('AUTH_GOV_BR_TOKEN_ENDPOINT', null),
                'userinfo_endpoint' => env('AUTH_GOV_BR_USERINFO_ENDPOINT', null),
                'issuer' => env('AUTH_GOV_BR_ISSUER', null),
                'jwks_url' => env('AUTH_GOV_BR_JWKS_URL', null),
                'end_session_endpoint' => env('AUTH_GOV_BR_LOGOUT_URL', null),
                'applySealId' => env('AUTH_GOV_BR_APPLY_SEAL_ID', null),
                'dic_agent_fields_update' => env('AUTH_GOV_BR_DICT_AGENT_FIELDS_UPDATE', '{"name":"full_name","emailPrivado":"email"}'),
                'metadataFieldCPF' => env('AUTH_METADATA_FIELD_DOCUMENT', 'documento'),
                'metadataFieldPhone' => env('AUTH_METADATA_FIELD_PHONE', 'telefone1'),
            ],
        ]
    ]

    /*
    //Example Authentik
    'auth.provider' => 'MapasCulturais\AuthProviders\OpauthAuthentik',
    'auth.config' => [
        'salt' => env('AUTH_SALT', 'SECURITY_SALT'),
        'timeout' => '24 hours',
        'client_id' => env('AUTH_AUTHENTIK_APP_ID', ''),
        'client_secret' => env('AUTH_AUTHENTIK_APP_SECRET', ''),
        'scope' => env('AUTH_AUTHENTIK_SCOPE', 'openid profile email'),
        'login_url' => env('AUTH_AUTHENTIK_LOGIN_URL', ''),
        'logout_url' => env('AUTH_AUTHENTIK_LOGOUT_URL', ''),
        'change_password_url' => env('AUTH_AUTHENTIK_CHANGE_PASSWORD_URL', null),
    ]
     */
];
