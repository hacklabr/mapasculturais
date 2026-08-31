<?php
namespace GovBrAuth;

use MapasCulturais\App;
use MapasCulturais\Entities;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\User;
use MapasCulturais\i;
use Respect\Validation\Validator as v;

/**
 * Política de identidade gov.br: lookup por CPF, sync, criação e migração MLA.
 */
class AccountService
{
    public const PENDING_SESSION = 'govbrauth.pending';

    public function __construct(private array $cfg)
    {
    }

    /**
     * Resolve ou cria o usuário a partir dos claims/userinfo do gov.br.
     *
     * @return array{status: string, user?: User, reason?: string, message?: string}
     */
    public function resolveOrCreate(array $claims, ?string $accessToken = null): array
    {
        $app = App::i();
        $sub = preg_replace('/\D+/', '', (string) ($claims['sub'] ?? ''));
        if ($sub === '' || strlen($sub) !== 11) {
            return $this->fail('invalid_cpf', i::__('Não foi possível autenticar com Gov.br. Tente novamente ou use outra forma de login.', 'govbr'));
        }

        $authProviderId = $app->getRegisteredAuthProviderId('govbr');
        $user = $app->repo('User')->getByAuth($authProviderId, $sub);
        if ($user) {
            $this->syncAgent($user, $claims, $accessToken);
            $this->audit('GOVBR_LINK_BY_CPF', $user->id, $sub);
            return ['status' => 'ok', 'user' => $user];
        }

        $matches = $this->findActiveAgentsByCpf($sub);
        if (count($matches) > 1) {
            $this->audit('GOVBR_LINK_BLOCKED_CPF_AMBIGUOUS', null, $sub);
            return $this->fail(
                'cpf_ambiguous',
                i::__('Foram encontrados vários cadastros com este CPF. Entre em contato com o suporte para resolver a duplicidade.', 'govbr')
            );
        }

        if (count($matches) === 1) {
            $user = $this->rebindAgent($matches[0], $claims, $sub);
            if (!$user) {
                return $this->fail('rebind_failed', i::__('Não foi possível autenticar com Gov.br. Tente novamente ou use outra forma de login.', 'govbr'));
            }
            $this->syncAgent($user, $claims, $accessToken);
            $this->audit('GOVBR_LINK_BY_CPF', $user->id, $sub);
            return [
                'status' => 'ok',
                'user' => $user,
                'flash' => i::__('Sua conta Gov.br foi vinculada ao seu cadastro existente. Se você não reconhece esta ação, entre em contato com o suporte.', 'govbr'),
            ];
        }

        // Nova conta
        $email = $this->verifiedEmail($claims);
        if ($email !== '' && !$this->emailInUse($email)) {
            $user = $this->createNewAccount($claims, $email, $accessToken);
            $this->audit('GOVBR_ACCOUNT_CREATED', $user->id, $sub);
            return [
                'status' => 'ok',
                'user' => $user,
                'flash' => i::__('Sua conta Gov.br foi usada para criar um novo cadastro. Complete seus dados quando quiser.', 'govbr'),
            ];
        }

        $_SESSION[self::PENDING_SESSION] = [
            'claims' => $claims,
            'access_token' => $accessToken,
            'created_at' => time(),
        ];
        $this->audit('GOVBR_EMAIL_REQUESTED', null, $sub);
        $msg = $email === ''
            ? i::__('Não recebemos um e-mail verificado do Gov.br. Informe um e-mail para criar sua conta.', 'govbr')
            : i::__('O e-mail vinculado ao Gov.br já está em uso. Informe um novo e-mail para criar sua conta.', 'govbr');
        return [
            'status' => 'need_email',
            'reason' => $email === '' ? 'email_missing' : 'email_in_use',
            'message' => $msg,
        ];
    }

    /**
     * Completa criação pendente com e-mail informado pelo usuário.
     *
     * @return array{status: string, user?: User, message?: string}
     */
    public function completeWithEmail(string $email): array
    {
        $pending = $_SESSION[self::PENDING_SESSION] ?? null;
        if (!is_array($pending) || empty($pending['claims']) || empty($pending['created_at'])) {
            return $this->fail('pending_expired', i::__('Sua sessão expirou. Autentique-se novamente pelo Gov.br.', 'govbr'));
        }
        if ((time() - (int) $pending['created_at']) > 600) {
            unset($_SESSION[self::PENDING_SESSION]);
            return $this->fail('pending_expired', i::__('Sua sessão expirou. Autentique-se novamente pelo Gov.br.', 'govbr'));
        }

        $email = trim($email);
        if ($email === '' || !v::email()->validate($email)) {
            $this->audit('GOVBR_EMAIL_REJECTED', null, null);
            return [
                'status' => 'need_email',
                'message' => i::__('O e-mail informado não é válido. Verifique e tente novamente.', 'govbr'),
            ];
        }
        if ($this->emailInUse($email)) {
            $this->audit('GOVBR_EMAIL_REJECTED', null, null);
            return [
                'status' => 'need_email',
                'message' => i::__('O e-mail informado já está em uso. Tente outro e-mail.', 'govbr'),
            ];
        }

        $claims = (array) $pending['claims'];
        $accessToken = isset($pending['access_token']) ? (string) $pending['access_token'] : null;
        unset($_SESSION[self::PENDING_SESSION]);

        $this->audit('GOVBR_EMAIL_VALIDATED', null, null);
        $user = $this->createNewAccount($claims, $email, $accessToken);
        $sub = preg_replace('/\D+/', '', (string) ($claims['sub'] ?? ''));
        $this->audit('GOVBR_ACCOUNT_CREATED', $user->id, $sub);

        return ['status' => 'ok', 'user' => $user];
    }

    public function clearPending(): void
    {
        unset($_SESSION[self::PENDING_SESSION]);
    }

    public function verifiedEmail(array $claims): string
    {
        $email = (string) ($claims['email'] ?? '');
        if ($email === '') {
            return '';
        }
        $verified = $claims['email_verified'] ?? null;
        if (in_array($verified, [true, 'true', 1, '1'], true)) {
            return $email;
        }
        return '';
    }

    public function emailInUse(string $email): bool
    {
        $app = App::i();
        $q = $app->em->createQuery('SELECT u.id FROM MapasCulturais\Entities\User u WHERE LOWER(u.email) = :email');
        $q->setParameter('email', strtolower($email));
        return (bool) $q->getOneOrNullResult();
    }

    /**
     * @return Agent[]
     */
    public function findActiveAgentsByCpf(string $cpfDigits): array
    {
        $app = App::i();
        $field = (string) ($this->cfg['metadataFieldCPF'] ?? 'documento');
        $masked = self::maskCpf($cpfDigits, '###.###.###-##');

        $metas = [];
        foreach ([$masked, $cpfDigits] as $value) {
            foreach ($app->repo('AgentMeta')->findBy(['key' => $field, 'value' => $value]) as $meta) {
                $metas[$meta->id] = $meta;
            }
        }

        $agents = [];
        foreach ($metas as $meta) {
            $agent = $meta->owner;
            if (!$agent instanceof Agent) {
                continue;
            }
            if ((int) $agent->status <= 0) {
                continue;
            }
            $agents[$agent->id] = $agent;
        }

        return array_values($agents);
    }

    public function rebindAgent(Agent $agent, array $claims, string $sub): ?User
    {
        $app = App::i();
        $app->disableAccessControl();

        try {
            if (!$agent->isUserProfile) {
                $user = new User();
                $user->authProvider = 'govbr';
                $user->authUid = $sub;
                $email = $this->verifiedEmail($claims);
                $user->email = $email !== '' ? $email : uniqid('govbr-') . '@invalid.local';
                $app->em->persist($user);
                $app->em->flush();

                $agent->userId = $user->id;
                $agent->save(true);
                $agent->refresh();

                $user->profile = $agent;
                $user->save(true);
                $app->enableAccessControl();
                return $user;
            }

            $user = $agent->user;
            if (!$user instanceof User) {
                $app->enableAccessControl();
                return null;
            }

            // Preserva identidade anterior para rollback administrativo
            $user->previous_auth_provider = $user->authProvider;
            $user->previous_auth_uid = $user->authUid;
            $user->authProvider = 'govbr';
            $user->authUid = $sub;
            $user->save(true);

            $app->enableAccessControl();
            return $user;
        } catch (\Throwable $e) {
            $app->enableAccessControl();
            $app->log->error('[govbrauth] rebindAgent: ' . $e->getMessage());
            return null;
        }
    }

    public function createNewAccount(array $claims, string $email, ?string $accessToken = null): User
    {
        $app = App::i();
        $provider = $app->auth;
        if (!$provider instanceof Provider) {
            throw new \RuntimeException('govbr_provider_required');
        }

        $sub = preg_replace('/\D+/', '', (string) ($claims['sub'] ?? ''));
        $user = $provider->createUserFromService([
            'uid' => $sub,
            'email' => $email,
            'name' => (string) ($claims['name'] ?? ''),
            'phone' => $this->verifiedPhone($claims),
            'cpf' => $sub,
            'claims' => $claims,
            'access_token' => $accessToken,
        ]);

        return $user;
    }

    public function syncAgent(User $user, array $claims, ?string $accessToken = null): void
    {
        $app = App::i();
        $agent = $user->profile;
        if (!$agent instanceof Agent) {
            return;
        }

        $sub = preg_replace('/\D+/', '', (string) ($claims['sub'] ?? ''));
        $field = (string) ($this->cfg['metadataFieldCPF'] ?? 'documento');
        $currentCpf = preg_replace('/\D+/', '', (string) ($agent->$field ?? ''));
        if ($currentCpf !== '' && $sub !== '' && $currentCpf !== $sub) {
            $app->log->error('[govbrauth] sync bloqueado: CPF do perfil diverge do sub');
            return;
        }

        $map = $this->fieldsUpdateMap();
        $info = [
            'full_name' => (string) ($claims['name'] ?? ''),
            'email' => $this->verifiedEmail($claims),
            'phone_number' => $this->verifiedPhone($claims),
            'cpf' => $sub,
        ];

        $app->disableAccessControl();

        foreach ($map as $entityKey => $ref) {
            $value = $info[$ref] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($entityKey === 'emailPrivado') {
                $agent->emailPrivado = $value;
                continue;
            }
            if ($entityKey === 'name') {
                $agent->name = $value;
                continue;
            }
            $agent->$entityKey = $value;
        }

        // Sempre garante CPF no metadado configurado
        if ($sub !== '') {
            $agent->$field = self::maskCpf($sub, '###.###.###-##');
        }

        $phoneField = (string) ($this->cfg['metadataFieldPhone'] ?? 'telefone1');
        $phone = $this->verifiedPhone($claims);
        if ($phone !== '') {
            $agent->$phoneField = $phone;
        }

        $agent->save(true);
        $app->enableAccessControl();

        $this->applySeal($user);
        $this->downloadAvatar($agent, $claims, $accessToken);
    }

    public function applySeal(User $user): void
    {
        $sealId = $this->cfg['applySealId'] ?? null;
        if (!$sealId) {
            return;
        }

        $app = App::i();
        $agent = $user->profile;
        $seal = $app->repo('Seal')->find((int) $sealId);
        if (!$seal || !$agent) {
            return;
        }

        $app->disableAccessControl();
        foreach ($agent->getSealRelations() as $relation) {
            if ((int) $relation->seal->id === (int) $seal->id) {
                $app->enableAccessControl();
                return;
            }
        }
        $agent->createSealRelation($seal);
        $app->enableAccessControl();
    }

    public function downloadAvatar(Agent $agent, array $claims, ?string $accessToken): void
    {
        $url = (string) ($claims['picture'] ?? '');
        if ($url === '' || !$accessToken) {
            return;
        }

        try {
            $ctx = stream_context_create([
                'http' => [
                    'header' => "Authorization: Bearer {$accessToken}\r\n",
                    'timeout' => 10,
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false || $body === '' || mb_strpos($body, 'não encontrada') !== false) {
                return;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'govbr');
            if ($tmp === false) {
                return;
            }
            file_put_contents($tmp, $body);

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmp);
            finfo_close($finfo);
            if (!in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'], true)) {
                @unlink($tmp);
                return;
            }

            $className = $agent->fileClassName;
            $file = new $className([
                'name' => md5((string) microtime(true)) . '.jpg',
                'type' => $mime,
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => filesize($tmp),
            ]);
            $file->group = 'avatar';
            $file->owner = $agent;
            $file->save(true);
        } catch (\Throwable $e) {
            App::i()->log->error('[govbrauth] downloadAvatar: ' . $e->getMessage());
        }
    }

    public function verifiedPhone(array $claims): string
    {
        $phone = (string) ($claims['phone_number'] ?? '');
        if ($phone === '') {
            return '';
        }
        $verified = $claims['phone_number_verified'] ?? null;
        return in_array($verified, [true, 'true', 1, '1'], true) ? $phone : '';
    }

    /** @return array<string,string> entity_field => claim_ref */
    public function fieldsUpdateMapPublic(): array
    {
        return $this->fieldsUpdateMap();
    }

    /** @return array<string,string> entity_field => claim_ref */
    private function fieldsUpdateMap(): array
    {
        $raw = $this->cfg['dic_agent_fields_update'] ?? '{"name":"full_name","emailPrivado":"email"}';
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || $raw === []) {
            return ['name' => 'full_name', 'emailPrivado' => 'email'];
        }
        return $raw;
    }

    private function fail(string $reason, string $message): array
    {
        App::i()->log->error('[govbrauth] ' . $reason);
        return ['status' => 'error', 'reason' => $reason, 'message' => $message];
    }

    private function audit(string $event, ?int $userId, ?string $identifier): void
    {
        $context = [
            'event' => $event,
            'provider' => 'govbr',
            'timestamp' => date('c'),
        ];
        if ($identifier !== null && $identifier !== '') {
            $context['identifier_hash'] = substr(bin2hex(hash('sha256', $identifier, true)), 0, 16);
        }
        if ($userId !== null) {
            $context['user_id'] = $userId;
        }
        App::i()->log->info('AUTH ' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }

    public static function maskCpf(string $val, string $mask): string
    {
        if (strlen($val) === strlen($mask)) {
            return $val;
        }
        $masked = '';
        $k = 0;
        for ($i = 0; $i <= strlen($mask) - 1; $i++) {
            $masked .= $mask[$i] === '#' ? ($val[$k++] ?? '') : $mask[$i];
        }
        return $masked;
    }
}
