# GovBrAuth — autenticação gov.br para Mapas Culturais 8+

Plugin standalone que autentica cidadãos via Login Único (gov.br) com OIDC
(Authorization Code + PKCE S256), convivendo com o login local do módulo
`LocalAuth` do core.

## Ativação (janela única)

Em instâncias que hoje usam MultipleLocalAuth + gov.br, troque **na mesma release**:

1. `auth.provider` → `\GovBrAuth\Provider` (veja `config/authentication.php`)
2. Remova `MultipleLocalAuth` de `config/plugins.php` e inclua `GovBrAuth`
3. Mantenha `AUTH_LOCAL_LOGIN_ENABLED=true` (default) para o login e-mail/senha

Sem o passo 2, o `LocalAuth` fica em stand-down e o formulário local some (404).

## Variáveis de ambiente

| Env | Descrição |
|---|---|
| `AUTH_GOV_BR_CLIENT_ID` | Client ID (também controla visibilidade do botão) |
| `AUTH_GOV_BR_SECRET` | Client secret |
| `AUTH_GOV_BR_SCOPE` | Escopos OIDC (default inclui confiabilidades) |
| `AUTH_GOV_BR_ENDPOINT` | Authorization endpoint |
| `AUTH_GOV_BR_TOKEN_ENDPOINT` | Token endpoint |
| `AUTH_GOV_BR_USERINFO_ENDPOINT` | UserInfo endpoint |
| `AUTH_GOV_BR_REDIRECT_URI` | Opcional; default `BASE_URL/auth/response` |
| `AUTH_GOV_BR_ISSUER` | `iss` esperado do ID token |
| `AUTH_GOV_BR_JWKS_URL` | JWKS (`https://…/jwk`) |
| `AUTH_GOV_BR_LOGOUT_URL` | `end_session_endpoint` (logout federado) |
| `AUTH_GOV_BR_APPLY_SEAL_ID` | ID do selo Mapas (opcional) |
| `AUTH_GOV_BR_DICT_AGENT_FIELDS_UPDATE` | JSON campo→claim (default name/emailPrivado) |
| `AUTH_METADATA_FIELD_DOCUMENT` | Metadado de CPF no agente (default `documento`) |
| `AUTH_METADATA_FIELD_PHONE` | Metadado de telefone (default `telefone1`) |

Cadastre no gov.br a `redirect_uri` exata e a URL de pós-logout (`BASE_URL`).

## Comportamento

- Identidade: claim `sub` = CPF (`auth_uid`); lookup **somente por CPF**
- Conta existente: autentica e sincroniza `name` / `emailPrivado` (e-mail só se `email_verified`); **nunca** altera `User.email`
- CPF duplicado em agentes ativos: bloqueia e pede suporte
- Contas da era MLA (`auth_provider=0`): re-vínculo por CPF → `auth_provider=4`
- E-mail ausente/colidido na criação: tela `/autenticacao/govbr-email`
- Em todo login: selo (se configurado), foto (Bearer) e telefone verificado
- Logout federado: apenas `post_logout_redirect_uri` (sem `id_token_hint`)

## Runbook pré-deploy

1. Query de sanidade — CPFs duplicados em `AgentMeta` do campo de documento
2. Inventário de strategies sociais restantes do MLA (Google/Decidim etc. ficam sem provedor)
3. Confirmar credenciais de homologação e recadastrar redirect/logout para o plugin novo
4. Staging: conta local pré-existente loga sem redefinir senha; conta gov.br da era MLA resolve o mesmo `usr.id` após primeiro login

## Estrutura

```
GovBrAuth/
├── Plugin.php
├── Provider.php          # AuthProvider / OIDC
├── AccountService.php    # CPF, sync, criação, migração
├── views/auth/
├── assets/img/
└── README.md
```

Dependências OIDC (`league/oauth2-client`, `firebase/php-jwt`) vêm do `composer.json` do core;
o autoload do plugin é o do `App` via `config/plugins.php` (sem `composer.json` próprio).
