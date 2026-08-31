<?php
namespace GovBrAuth;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use League\OAuth2\Client\Provider\GenericProvider;
use MapasCulturais\App;
use MapasCulturais\Entities;
use MapasCulturais\Entities\Agent;
use MapasCulturais\i;

/**
 * Driver de autenticação gov.br (OIDC Authorization Code + PKCE).
 *
 * Deve ser o auth.provider da instância. Login local convive via módulo LocalAuth
 * (AUTH_LOCAL_LOGIN_ENABLED=true).
 */
class Provider extends \MapasCulturais\AuthProvider
{
    private const TX = 'govbrauth.tx';
    private const SID = 'govbrauth.user_id';
    public const FLASH = 'govbrauth.flash';
    private const RATE_PREFIX = 'govbrauth.rl.';

    private ?GenericProvider $oauth = null;
    private array $cfg = [];
    private ?AccountService $accounts = null;

    protected function _init()
    {
        $app = App::i();

        // id determinístico 4 (core registra OpenID/logincidadao/authentik antes)
        $app->registerAuthProvider('govbr');

        $strategy = (array) ($this->_config['strategies']['govbr'] ?? []);
        $config = array_merge([
            'client_id' => env('AUTH_GOV_BR_CLIENT_ID', null),
            'client_secret' => env('AUTH_GOV_BR_SECRET', null),
            'scope' => env('AUTH_GOV_BR_SCOPE', 'openid email profile govbr_confiabilidades govbr_confiabilidades_idtoken'),
            'auth_endpoint' => env('AUTH_GOV_BR_ENDPOINT', null),
            'token_endpoint' => env('AUTH_GOV_BR_TOKEN_ENDPOINT', null),
            'userinfo_endpoint' => env('AUTH_GOV_BR_USERINFO_ENDPOINT', null),
            'redirect_uri' => env('AUTH_GOV_BR_REDIRECT_URI', null),
            'issuer' => env('AUTH_GOV_BR_ISSUER', null),
            'jwks_url' => env('AUTH_GOV_BR_JWKS_URL', null),
            'end_session_endpoint' => env('AUTH_GOV_BR_LOGOUT_URL', null),
            'applySealId' => env('AUTH_GOV_BR_APPLY_SEAL_ID', null),
            'dic_agent_fields_update' => env('AUTH_GOV_BR_DICT_AGENT_FIELDS_UPDATE', '{"name":"full_name","emailPrivado":"email"}'),
            'metadataFieldCPF' => env('AUTH_METADATA_FIELD_DOCUMENT', 'documento'),
            'metadataFieldPhone' => env('AUTH_METADATA_FIELD_PHONE', 'telefone1'),
            'state_ttl' => 600,
            'jwt_algorithms' => ['RS256', 'ES256'],
            'http_timeout' => 10,
            'http_connect_timeout' => 5,
            'rate_limit_max' => 20,
            'rate_limit_window' => 300,
        ], $strategy, array_intersect_key($this->_config, array_flip([
            'client_id', 'client_secret', 'scope', 'auth_endpoint', 'token_endpoint',
            'userinfo_endpoint', 'redirect_uri', 'issuer', 'jwks_url', 'end_session_endpoint',
            'applySealId', 'dic_agent_fields_update', 'metadataFieldCPF', 'metadataFieldPhone',
        ])));

        $this->assertBootGuards($config);
        $this->cfg = $config;
        $this->accounts = new AccountService($config);

        // Expõe botão na UI do LocalAuth
        if (!isset($app->config['auth.config']['strategies']) || !is_array($app->config['auth.config']['strategies'])) {
            $app->config['auth.config']['strategies'] = [];
        }
        $app->config['auth.config']['strategies']['govbr'] = array_merge(
            (array) ($app->config['auth.config']['strategies']['govbr'] ?? []),
            [
                'visible' => !empty($config['client_id']),
            ]
        );

        $redirectUri = $config['redirect_uri']
            ?? rtrim($app->getBaseUrl(), '/') . '/auth/response';

        $this->oauth = new GenericProvider([
            'clientId' => (string) $config['client_id'],
            'clientSecret' => (string) $config['client_secret'],
            'redirectUri' => (string) $redirectUri,
            'urlAuthorize' => (string) $config['auth_endpoint'],
            'urlAccessToken' => (string) $config['token_endpoint'],
            'urlResourceOwnerDetails' => (string) $config['userinfo_endpoint'],
            'timeout' => (int) $config['http_timeout'],
            'connect_timeout' => (int) $config['http_connect_timeout'],
        ]);

        $provider = $this;

        $app->hook('GET(auth.index)', function () use ($app) {
            if (\LocalAuth\Module::isEnabled() && !\LocalAuth\Module::multipleLocalAuthActive()) {
                return;
            }
            $app->redirect($this->createUrl('govbr'));
        });

        $app->hook('<<GET|POST>>(auth.govbr)', function () use ($app, $provider) {
            $app->redirect($provider->beginAuthorization());
        });

        $app->hook('GET(auth.response)', function () use ($app, $provider) {
            $result = $provider->processResponse();
            if ($result === 'need_email') {
                $app->redirect($this->createUrl('govbr-email'));
                return;
            }
            if ($app->auth->isUserAuthenticated()) {
                $app->redirect($app->auth->getRedirectPath());
                return;
            }
            if (!empty($_SESSION[Provider::FLASH])) {
                $app->redirect($this->createUrl('govbr-error'));
                return;
            }
            $app->redirect($this->createUrl(''));
        });

        $app->hook('GET(auth.govbr-email)', function () use ($app, $provider) {
            $pending = $_SESSION[AccountService::PENDING_SESSION] ?? null;
            if (!$pending) {
                $app->redirect($this->createUrl(''));
                return;
            }
            $msg = $_SESSION[Provider::FLASH]['message'] ?? i::__('O e-mail vinculado ao Gov.br já está em uso. Informe um novo e-mail para criar sua conta.', 'govbr');
            unset($_SESSION[Provider::FLASH]);
            $this->render('govbr-email', [
                'message' => $msg,
                'error' => null,
            ]);
        });

        $app->hook('POST(auth.govbr-email)', function () use ($app, $provider) {
            $email = (string) ($app->request->post('email') ?? '');
            $result = $provider->accounts()->completeWithEmail($email);
            if (($result['status'] ?? '') === 'ok') {
                $_SESSION[Provider::FLASH] = [
                    'message' => i::__('Sua conta Gov.br foi usada para criar um novo cadastro. Complete seus dados quando quiser.', 'govbr'),
                    'type' => 'success',
                ];
                $provider->finalizeLogin($result['user']);
                $app->applyHook('auth.successful');
                $app->redirect($app->auth->getRedirectPath());
                return;
            }
            $this->render('govbr-email', [
                'message' => $result['message'] ?? i::__('Informe um novo e-mail para criar sua conta.', 'govbr'),
                'error' => $result['message'] ?? null,
            ]);
        });

        $app->hook('GET(auth.govbr-cancel)', function () use ($app, $provider) {
            $provider->accounts()->clearPending();
            unset($_SESSION[Provider::FLASH]);
            $app->redirect($this->createUrl(''));
        });

        $app->hook('GET(auth.govbr-error)', function () {
            $flash = $_SESSION[Provider::FLASH] ?? ['message' => i::__('Não foi possível autenticar com Gov.br.', 'govbr')];
            unset($_SESSION[Provider::FLASH]);
            $this->render('govbr-error', ['message' => $flash['message'] ?? '']);
        });

        if (!empty($config['end_session_endpoint'])) {
            $app->hook('auth.logout:after', function () use ($app, $provider) {
                $provider->federatedLogout($app);
            });
        }

        // Campos oficiais do gov.br não ficam bloqueados por selo na UI
        $unlockFields = array_keys($this->accounts()->fieldsUpdateMapPublic());
        $app->hook('entity(Agent).get(lockedFields)', function (&$lockedFields) use ($unlockFields) {
            $lockedFields = array_values(array_diff((array) $lockedFields, $unlockFields));
        });

        // Flash pós-login (ex.: após vinculação)
        $app->hook('template(<<*>>.head):end', function () {
            $flash = $_SESSION[Provider::FLASH] ?? null;
            if (!$flash || ($flash['type'] ?? '') !== 'success' || empty($flash['message'])) {
                return;
            }
            unset($_SESSION[Provider::FLASH]);
            echo "<script>document.addEventListener('DOMContentLoaded',function(){"
                . "var n=document.createElement('div');"
                . "n.className='mc-alert mc-alert--success';n.setAttribute('role','status');"
                . "n.textContent=" . json_encode($flash['message'], JSON_UNESCAPED_UNICODE) . ";"
                . "n.style.cssText='position:fixed;top:1rem;right:1rem;z-index:9999;max-width:28rem;padding:1rem;background:#e8f5e9;border:1px solid #2e7d32;border-radius:4px';"
                . "document.body.appendChild(n);setTimeout(function(){n.remove()},8000);});</script>";
        });
    }

    public function accounts(): AccountService
    {
        return $this->accounts ??= new AccountService($this->cfg);
    }

    /** Wrapper público para createUser (final protected na base). */
    public function createUserFromService(array $data): Entities\User
    {
        return $this->createUser($data);
    }

    public function beginAuthorization(): string
    {
        $this->audit('GOVBR_AUTH_INIT', null, null);

        $_SESSION[self::TX] = [
            'state' => bin2hex(random_bytes(32)),
            'nonce' => bin2hex(random_bytes(16)),
            'verifier' => rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '='),
            'created_at' => time(),
        ];

        return $this->oauth->getAuthorizationUrl([
            'response_type' => 'code',
            'scope' => $this->cfg['scope'] ?? 'openid',
            'state' => $_SESSION[self::TX]['state'],
            'nonce' => $_SESSION[self::TX]['nonce'],
            'code_challenge' => rtrim(strtr(base64_encode(
                hash('sha256', $_SESSION[self::TX]['verifier'], true)
            ), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @return bool|string true|false|'need_email'
     */
    public function processResponse(): bool|string
    {
        $app = App::i();

        try {
            if (!$this->checkRateLimit($app)) {
                $this->setFlash(i::__('O serviço Gov.br está temporariamente indisponível. Tente novamente mais tarde ou entre com e-mail e senha.', 'govbr'));
                $this->_setAuthenticatedUser();
                $app->applyHook('auth.failed');
                return false;
            }

            $raw = array_merge($_GET, $_POST);
            $params = [];
            foreach (['code', 'state', 'error', 'error_description'] as $key) {
                if (isset($raw[$key]) && is_string($raw[$key])) {
                    $params[$key] = $raw[$key];
                }
            }

            $this->audit('GOVBR_CALLBACK_RECEIVED', null, null);

            $tx = $_SESSION[self::TX] ?? null;
            unset($_SESSION[self::TX]);

            if (!empty($params['error'])) {
                $msg = ($params['error'] === 'access_denied')
                    ? i::__('Você cancelou a autenticação no Gov.br. Escolha outra forma de entrar.', 'govbr')
                    : i::__('Não foi possível autenticar com Gov.br. Tente novamente ou use outra forma de login.', 'govbr');
                $this->setFlash($msg);
                return $this->fail($app, 'idp_error');
            }

            if (empty($params['code']) || empty($params['state'])
                || !$tx
                || !hash_equals((string) $tx['state'], (string) $params['state'])
                || (time() - (int) $tx['created_at']) > (int) ($this->cfg['state_ttl'] ?? 600)
            ) {
                $this->setFlash(i::__('Não foi possível autenticar com Gov.br. Tente novamente ou use outra forma de login.', 'govbr'));
                return $this->fail($app, 'state_or_callback');
            }

            $token = $this->oauth->getAccessToken('authorization_code', [
                'code' => $params['code'],
                'code_verifier' => $tx['verifier'],
            ]);

            $idToken = $token->getValues()['id_token'] ?? '';
            if (!is_string($idToken) || $idToken === '') {
                $this->setFlash(i::__('Não foi possível autenticar com Gov.br. Tente novamente ou use outra forma de login.', 'govbr'));
                return $this->fail($app, 'no_id_token');
            }

            $claims = $this->validateIdToken($idToken, (string) $tx['nonce']);
            if ($claims === null) {
                $this->audit('GOVBR_TOKEN_VALIDATION_FAILED', null, null);
                $this->setFlash(i::__('Não foi possível autenticar com Gov.br. Tente novamente ou use outra forma de login.', 'govbr'));
                return $this->fail($app, 'invalid_id_token');
            }

            $this->audit('GOVBR_TOKEN_VALIDATED', null, (string) ($claims['sub'] ?? ''));

            // Userinfo complementa (picture etc.)
            $accessToken = $token->getToken();
            try {
                $resourceOwner = $this->oauth->getResourceOwner($token)->toArray();
                $claims = array_merge($claims, $resourceOwner);
            } catch (\Throwable $e) {
                $app->log->warning('[govbrauth] userinfo: ' . $e->getMessage());
            }

            // Ignora sessão pré-existente no callback gov.br (herança de sessão)
            unset($_SESSION[self::SID], $_SESSION['mapasculturais.auth.local_user_id']);

            $result = $this->accounts()->resolveOrCreate($claims, is_string($accessToken) ? $accessToken : null);

            if (($result['status'] ?? '') === 'need_email') {
                $this->setFlash($result['message'] ?? '');
                return 'need_email';
            }

            if (($result['status'] ?? '') !== 'ok' || empty($result['user'])) {
                $this->setFlash($result['message'] ?? i::__('Não foi possível autenticar com Gov.br.', 'govbr'));
                return $this->fail($app, $result['reason'] ?? 'resolve_failed');
            }

            if (!empty($result['flash'])) {
                $this->setFlash((string) $result['flash'], 'success');
            }

            $this->finalizeLogin($result['user']);
            $app->applyHook('auth.successful');
            return true;

        } catch (\Throwable $e) {
            $app->log->error('[govbrauth] processResponse: ' . $e->getMessage());
            $this->audit('auth.login.failed', null, 'exception');
            $this->setFlash(i::__('Não foi possível autenticar com Gov.br. Tente novamente ou use outra forma de login.', 'govbr'));
            return $this->fail($app, 'exception');
        }
    }

    public function finalizeLogin(Entities\User $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SID] = $user->id;
        $this->_setAuthenticatedUser($user);
        $this->audit('auth.login.success', $user->id, null);
    }

    private function validateIdToken(string $idToken, string $nonce): ?array
    {
        $app = App::i();
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true);
        if (!is_array($header) || empty($header['alg'])) {
            return null;
        }

        $allowed = (array) ($this->cfg['jwt_algorithms'] ?? ['RS256']);
        if (!in_array((string) $header['alg'], $allowed, true)) {
            $app->log->error('[govbrauth] algoritmo de ID token não permitido: ' . $header['alg']);
            return null;
        }

        try {
            JWT::$leeway = 60;
            $claims = (array) JWT::decode(
                $idToken,
                JWK::parseKeySet($this->fetchJwks((string) ($header['kid'] ?? '')))
            );
        } catch (\Throwable $e) {
            $app->log->error('[govbrauth] ID token inválido: ' . get_class($e) . ' ' . $e->getMessage());
            return null;
        }

        $iss = (string) ($this->cfg['issuer'] ?? '');
        if ($iss !== '' && !hash_equals($iss, (string) ($claims['iss'] ?? ''))) {
            return null;
        }
        $aud = is_array($claims['aud'] ?? null) ? $claims['aud'] : (array) ($claims['aud'] ?? []);
        if (!in_array((string) $this->cfg['client_id'], array_map('strval', $aud), true)) {
            return null;
        }
        if (isset($claims['azp']) && !hash_equals((string) $this->cfg['client_id'], (string) $claims['azp'])) {
            return null;
        }
        if ($nonce !== '' && !isset($claims['nonce'])) {
            return null;
        }
        if (isset($claims['nonce']) && !hash_equals($nonce, (string) $claims['nonce'])) {
            return null;
        }
        if (empty($claims['sub'])) {
            return null;
        }

        return $claims;
    }

    private function fetchJwks(string $kid): array
    {
        $app = App::i();
        $url = (string) ($this->cfg['jwks_url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('jwks_not_configured');
        }

        $cacheKey = 'govbrauth.jwks.' . sha1($url);
        $cached = $app->cache->fetch($cacheKey);
        $jwks = is_array($cached) && ($cached['fetched_at'] ?? 0) > (time() - 900)
            ? $cached['keys']
            : $this->downloadJwks($app, $url, $cacheKey);

        if ($kid !== '' && !$this->jwksHasKid($jwks, $kid)) {
            $jwks = $this->downloadJwks($app, $url, $cacheKey);
            if (!$this->jwksHasKid($jwks, $kid)) {
                throw new \RuntimeException('invalid_token');
            }
        }
        return $jwks;
    }

    private function downloadJwks(App $app, string $url, string $cacheKey): array
    {
        $keys = json_decode((string) $this->oauth->getHttpClient()->get($url)->getBody(), true);
        if (!is_array($keys) || !isset($keys['keys'])) {
            throw new \RuntimeException('jwks_unavailable');
        }
        $app->cache->save($cacheKey, ['keys' => $keys, 'fetched_at' => time()]);
        return $keys;
    }

    private function jwksHasKid(array $jwks, string $kid): bool
    {
        foreach (($jwks['keys'] ?? []) as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return true;
            }
        }
        return false;
    }

    protected function _createUser($data)
    {
        $app = App::i();
        $app->disableAccessControl();

        $user = new Entities\User;
        $user->authProvider = 'govbr';
        $user->authUid = $data['uid'];
        $user->email = $data['email'] !== '' ? $data['email'] : uniqid('govbr-') . '@invalid.local';
        $app->em->persist($user);

        $agent = new Agent($user);
        $agent->status = (int) env('STATUS_CREATE_AGENT', 1);
        $agent->name = $data['name'] ?? '';
        $agent->emailPrivado = $user->email;

        $cpfField = (string) ($this->cfg['metadataFieldCPF'] ?? 'documento');
        if (!empty($data['cpf'])) {
            $agent->$cpfField = AccountService::maskCpf((string) $data['cpf'], '###.###.###-##');
        }

        $phoneField = (string) ($this->cfg['metadataFieldPhone'] ?? 'telefone1');
        if (!empty($data['phone'])) {
            $agent->$phoneField = $data['phone'];
        }

        $agent->save();
        $app->em->persist($agent);
        $app->em->flush();

        $user->profile = $agent;
        $user->save(true);

        $app->enableAccessControl();

        if (!empty($data['claims'])) {
            $this->accounts()->applySeal($user);
            $this->accounts()->downloadAvatar(
                $agent,
                (array) $data['claims'],
                isset($data['access_token']) ? (string) $data['access_token'] : null
            );
        }

        return $user;
    }

    public function _getAuthenticatedUser()
    {
        $user_id = $_SESSION[self::SID] ?? null;
        return $user_id ? App::i()->repo('User')->find($user_id) : null;
    }

    public function _cleanUserSession()
    {
        unset($_SESSION[self::SID], $_SESSION[self::TX], $_SESSION[AccountService::PENDING_SESSION]);
    }

    public function federatedLogout(App $app): void
    {
        $endpoint = (string) ($this->cfg['end_session_endpoint'] ?? '');
        if ($endpoint === '') {
            return;
        }
        // Roteiro oficial: apenas post_logout_redirect_uri (sem id_token_hint)
        $app->redirect(
            $endpoint . '?post_logout_redirect_uri=' . urlencode($app->getBaseUrl())
        );
    }

    private function fail(App $app, string $reason): bool
    {
        $app->log->error('[govbrauth] ' . $reason);
        $this->_setAuthenticatedUser();
        $app->applyHook('auth.failed');
        return false;
    }

    private function setFlash(string $message, string $type = 'error'): void
    {
        if ($message === '') {
            return;
        }
        $_SESSION[self::FLASH] = ['message' => $message, 'type' => $type];
    }

    private function checkRateLimit(App $app): bool
    {
        $ip = (string) ($app->request->getIp() ?: ($_SERVER['REMOTE_ADDR'] ?? '0'));
        $key = self::RATE_PREFIX . substr(hash('sha256', $ip), 0, 16);
        $window = (int) ($this->cfg['rate_limit_window'] ?? 300);
        $max = (int) ($this->cfg['rate_limit_max'] ?? 20);

        $bucket = $app->cache->fetch($key);
        if (!is_array($bucket) || ($bucket['started_at'] ?? 0) < (time() - $window)) {
            $bucket = ['started_at' => time(), 'count' => 0];
        }
        $bucket['count'] = (int) $bucket['count'] + 1;
        $app->cache->save($key, $bucket, $window);

        return $bucket['count'] <= $max;
    }

    private function assertBootGuards(array $config): void
    {
        $app = App::i();
        $production = ($app->config['app.mode'] ?? '') === 'production';
        $secret = (string) ($config['client_secret'] ?? '');
        $knownDefaults = ['', 'SECURITY_SALT', 'placeholder', 'changeme'];

        if ($production && in_array($secret, $knownDefaults, true)) {
            throw new \RuntimeException(
                '[govbrauth] client_secret vazio/placeholder em produção. Defina AUTH_GOV_BR_SECRET.'
            );
        }

        if ($production) {
            foreach (['auth_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_url'] as $key) {
                $url = (string) ($config[$key] ?? '');
                if ($url !== '' && strncmp($url, 'https://', 8) !== 0) {
                    throw new \RuntimeException("[govbrauth] endpoint '{$key}' deve ser https:// em produção");
                }
            }
        }
    }

    private function audit(string $event, ?int $userId, ?string $identifier): void
    {
        $context = ['event' => $event, 'provider' => 'govbr', 'timestamp' => date('c')];
        if ($identifier !== null && $identifier !== '') {
            $context['identifier_hash'] = substr(bin2hex(hash('sha256', $identifier, true)), 0, 16);
        }
        if ($userId !== null) {
            $context['user_id'] = $userId;
        }
        App::i()->log->info('AUTH ' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
}
