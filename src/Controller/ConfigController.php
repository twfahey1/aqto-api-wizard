<?php

namespace App\Controller;

use App\Service\ConfigStore;
use App\Service\ConfigTreeBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ConfigController extends AbstractController
{
    #[Route('/configs/list', name: 'configs_list', methods: ['GET'])]
    public function list(ConfigStore $store, ConfigTreeBuilder $treeBuilder): Response
    {
        $collection = $store->loadCollection();

        return $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
            'tree' => $treeBuilder->build($collection),
        ]);
    }

    #[Route('/configs/new', name: 'configs_new', methods: ['GET'])]
    public function new(ConfigStore $store): Response
    {
        $collection = $store->loadCollection();

        return $this->render('partials/config_new.html.twig', [
            'authProfiles' => (array)($collection['authProfiles'] ?? []),
        ]);
    }

    #[Route('/configs/{id}/edit', name: 'configs_edit', methods: ['GET'])]
    public function edit(string $id, ConfigStore $store): Response
    {
        $collection = $store->loadCollection();

        $config = null;
        foreach ((array)($collection['configs'] ?? []) as $cfg) {
            if (is_array($cfg) && (string)($cfg['id'] ?? '') === $id) {
                $config = $cfg;
                break;
            }
        }

        if ($config === null) {
            return new Response('Config not found.', 404);
        }

        $request = (array)($config['request'] ?? []);
        $body = (array)($request['body'] ?? ['mode' => 'none']);
        $bodyMode = (string)($body['mode'] ?? 'none');

        $jsonBody = '';
        $formBody = '';
        if ($bodyMode === 'json') {
            $jsonBody = (string)($body['json'] ?? '');
        } elseif ($bodyMode === 'form') {
            $lines = [];
            foreach ((array)($body['form'] ?? []) as $pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $k = (string)($pair['name'] ?? '');
                $v = (string)($pair['value'] ?? '');
                if ($k === '') {
                    continue;
                }
                $lines[] = $k.'='.$v;
            }
            $formBody = implode("\n", $lines);
        }

        [$authType, $bearerToken, $apiKeyHeader, $apiKeyValue] = $this->detectInlineAuthDefaults($config);

        $oauthTokenUrl = '';
        $oauthRefreshUrl = '';
        $authProfileId = (string)($request['authProfileId'] ?? '');
        if ($authProfileId !== '') {
            foreach ((array)($collection['authProfiles'] ?? []) as $p) {
                if (!is_array($p) || (string)($p['id'] ?? '') !== $authProfileId) {
                    continue;
                }
                $oauth = (array)($p['oauth'] ?? []);
                $oauthTokenUrl = trim((string)($oauth['tokenUrl'] ?? ''));
                $oauthRefreshUrl = trim((string)($oauth['refreshUrl'] ?? ''));
                break;
            }
        }

        return $this->render('partials/config_edit.html.twig', [
            'config' => $config,
            'authProfiles' => (array)($collection['authProfiles'] ?? []),
            'bodyMode' => $bodyMode,
            'jsonBody' => $jsonBody,
            'formBody' => $formBody,
            'activeEnv' => (string)($request['activeEnv'] ?? 'dev'),
            'authType' => $authType,
            'bearerToken' => $bearerToken,
            'apiKeyHeader' => $apiKeyHeader,
            'apiKeyValue' => $apiKeyValue,
            'oauthTokenUrl' => $oauthTokenUrl,
            'oauthRefreshUrl' => $oauthRefreshUrl,
        ]);
    }

    #[Route('/configs', name: 'configs_create', methods: ['POST'])]
    public function create(Request $request, ConfigStore $store, ConfigTreeBuilder $treeBuilder): Response
    {
        $collection = $store->loadCollection();

        $name = trim((string)$request->request->get('name', ''));
        $method = strtoupper(trim((string)$request->request->get('method', 'GET')));
        $urlDev = trim((string)$request->request->get('urlDev', ''));
        $urlLive = trim((string)$request->request->get('urlLive', ''));
        $activeEnv = (string)$request->request->get('activeEnv', 'dev');

        if ($name === '') {
            return $this->renderCreateConfigError('Name is required.');
        }

        if ($urlDev === '' && $urlLive === '') {
            return $this->renderCreateConfigError('Provide at least one URL (dev or live).');
        }

        if (!in_array($activeEnv, ['dev', 'live'], true)) {
            $activeEnv = 'dev';
        }

        // If the chosen environment has no URL, fall back to the other available one.
        if ($activeEnv === 'dev' && $urlDev === '' && $urlLive !== '') {
            $activeEnv = 'live';
        }
        if ($activeEnv === 'live' && $urlLive === '' && $urlDev !== '') {
            $activeEnv = 'dev';
        }

        $headers = [];

        $authProfileId = trim((string)$request->request->get('authProfileId', ''));
        if ($authProfileId === '') {
            $authProfileId = null;
        }

        $authType = (string)$request->request->get('authType', 'none');
        if ($authType === 'bearer') {
            $token = (string)$request->request->get('bearerToken', '');
            $token = trim($token);
            $secretRef = 'secrets.'.Uuid::v4()->toRfc4122();
            $headers[] = [
                'name' => 'Authorization',
                'value' => $token === '' ? sprintf('{{AQTO_SECRET:%s}}', $secretRef) : 'Bearer '.$token,
                'isSecret' => true,
                'secretKind' => 'bearer',
                'secretRef' => $secretRef,
                'exportPolicy' => 'prompt',
                'placeholder' => sprintf('{{AQTO_SECRET:%s}}', $secretRef),
            ];
        } elseif ($authType === 'apiKey') {
            $headerName = trim((string)$request->request->get('apiKeyHeader', 'X-API-Key'));
            $value = trim((string)$request->request->get('apiKeyValue', ''));
            $secretRef = 'secrets.'.Uuid::v4()->toRfc4122();
            $headers[] = [
                'name' => $headerName !== '' ? $headerName : 'X-API-Key',
                'value' => $value === '' ? sprintf('{{AQTO_SECRET:%s}}', $secretRef) : $value,
                'isSecret' => true,
                'secretKind' => 'apiKey',
                'secretRef' => $secretRef,
                'exportPolicy' => 'prompt',
                'placeholder' => sprintf('{{AQTO_SECRET:%s}}', $secretRef),
            ];
        }

        $saveAuthProfile = (bool)$request->request->get('saveAuthProfile', false);
        $authProfileName = trim((string)$request->request->get('authProfileName', ''));
        $oauthTokenUrl = trim((string)$request->request->get('oauthTokenUrl', ''));
        $oauthRefreshUrl = trim((string)$request->request->get('oauthRefreshUrl', ''));

        if ($saveAuthProfile && $authProfileName !== '' && count($headers) > 0) {
            $profileId = 'auth_'.Uuid::v4()->toRfc4122();

            $oauth = null;
            if ($oauthTokenUrl !== '' || $oauthRefreshUrl !== '') {
                $oauth = array_filter([
                    'tokenUrl' => $oauthTokenUrl !== '' ? $oauthTokenUrl : null,
                    'refreshUrl' => $oauthRefreshUrl !== '' ? $oauthRefreshUrl : null,
                ], static fn ($v) => $v !== null);
            }

            $collection['authProfiles'] = array_values(array_merge(
                (array)($collection['authProfiles'] ?? []),
                [[
                    'id' => $profileId,
                    'name' => $authProfileName,
                    'headers' => $headers,
                    ...($oauth !== null ? ['oauth' => $oauth] : []),
                ]]
            ));
            $authProfileId = $profileId;
            $headers = [];
        }

        $bodyMode = (string)$request->request->get('bodyMode', 'none');
        $body = ['mode' => 'none'];

        if ($bodyMode === 'json') {
            $json = (string)$request->request->get('jsonBody', '');
            $body = [
                'mode' => 'json',
                'json' => $json,
            ];
        } elseif ($bodyMode === 'form') {
            $raw = (string)$request->request->get('formBody', '');
            $pairs = $this->parseKeyValueLines($raw);
            $body = [
                'mode' => 'form',
                'form' => $pairs,
            ];
        }

        $config = [
            'id' => 'cfg_'.Uuid::v4()->toRfc4122(),
            'name' => $name,
            'tags' => [],
            'request' => [
                'method' => $method,
                'urls' => [
                    // dev/live are optional; omit empty values.
                    ...($urlDev !== '' ? ['dev' => $urlDev] : []),
                    ...($urlLive !== '' ? ['live' => $urlLive] : []),
                ],
                'activeEnv' => $activeEnv,
                'authProfileId' => $authProfileId,
                'headers' => $headers,
                'query' => [],
                'body' => $body,
            ],
        ];

        $collection['configs'] = array_values(array_merge((array)($collection['configs'] ?? []), [$config]));
        $store->saveCollection($collection);

        $response = $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
            'tree' => $treeBuilder->build($collection),
        ]);

        $response->headers->set('HX-Trigger', json_encode([
            'config-created' => [
                'id' => (string)$config['id'],
                'name' => $name,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    #[Route('/configs/{id}', name: 'configs_update', methods: ['POST'])]
    public function update(string $id, Request $request, ConfigStore $store, ConfigTreeBuilder $treeBuilder): Response
    {
        $collection = $store->loadCollection();
        $configs = (array)($collection['configs'] ?? []);

        $idx = null;
        $existing = null;
        foreach ($configs as $i => $cfg) {
            if (is_array($cfg) && (string)($cfg['id'] ?? '') === $id) {
                $idx = $i;
                $existing = $cfg;
                break;
            }
        }

        if ($existing === null || $idx === null) {
            return new Response('Config not found.', 404);
        }

        $name = trim((string)$request->request->get('name', ''));
        $method = strtoupper(trim((string)$request->request->get('method', 'GET')));
        $urlDev = trim((string)$request->request->get('urlDev', ''));
        $urlLive = trim((string)$request->request->get('urlLive', ''));
        $activeEnv = (string)$request->request->get('activeEnv', 'dev');

        if ($name === '') {
            return $this->renderEditConfigError('Name is required.');
        }

        if ($urlDev === '' && $urlLive === '') {
            return $this->renderEditConfigError('Provide at least one URL (dev or live).');
        }

        if (!in_array($activeEnv, ['dev', 'live'], true)) {
            $activeEnv = 'dev';
        }

        if ($activeEnv === 'dev' && $urlDev === '' && $urlLive !== '') {
            $activeEnv = 'live';
        }
        if ($activeEnv === 'live' && $urlLive === '' && $urlDev !== '') {
            $activeEnv = 'dev';
        }

        $existingRequest = (array)($existing['request'] ?? []);
        $existingHeaders = (array)($existingRequest['headers'] ?? []);
        $headersWithoutInlineAuth = $this->stripInlineAuthHeaders($existingHeaders);

        $authProfileId = trim((string)$request->request->get('authProfileId', ''));
        if ($authProfileId === '') {
            $authProfileId = null;
        }

        $authType = (string)$request->request->get('authType', 'none');
        $inlineAuthHeaders = [];

        if ($authProfileId === null) {
            if ($authType === 'bearer') {
                $token = trim((string)$request->request->get('bearerToken', ''));
                $secretRef = 'secrets.'.Uuid::v4()->toRfc4122();
                $inlineAuthHeaders[] = [
                    'name' => 'Authorization',
                    'value' => $token === '' ? sprintf('{{AQTO_SECRET:%s}}', $secretRef) : 'Bearer '.$token,
                    'isSecret' => true,
                    'secretKind' => 'bearer',
                    'secretRef' => $secretRef,
                    'exportPolicy' => 'prompt',
                    'placeholder' => sprintf('{{AQTO_SECRET:%s}}', $secretRef),
                ];
            } elseif ($authType === 'apiKey') {
                $headerName = trim((string)$request->request->get('apiKeyHeader', 'X-API-Key'));
                $value = trim((string)$request->request->get('apiKeyValue', ''));
                $secretRef = 'secrets.'.Uuid::v4()->toRfc4122();
                $inlineAuthHeaders[] = [
                    'name' => $headerName !== '' ? $headerName : 'X-API-Key',
                    'value' => $value === '' ? sprintf('{{AQTO_SECRET:%s}}', $secretRef) : $value,
                    'isSecret' => true,
                    'secretKind' => 'apiKey',
                    'secretRef' => $secretRef,
                    'exportPolicy' => 'prompt',
                    'placeholder' => sprintf('{{AQTO_SECRET:%s}}', $secretRef),
                ];
            }
        }

        $saveAuthProfile = (bool)$request->request->get('saveAuthProfile', false);
        $authProfileName = trim((string)$request->request->get('authProfileName', ''));
        $oauthTokenUrl = trim((string)$request->request->get('oauthTokenUrl', ''));
        $oauthRefreshUrl = trim((string)$request->request->get('oauthRefreshUrl', ''));

        if ($saveAuthProfile && $authProfileName !== '' && count($inlineAuthHeaders) > 0) {
            $profileId = 'auth_'.Uuid::v4()->toRfc4122();

            $oauth = null;
            if ($oauthTokenUrl !== '' || $oauthRefreshUrl !== '') {
                $oauth = array_filter([
                    'tokenUrl' => $oauthTokenUrl !== '' ? $oauthTokenUrl : null,
                    'refreshUrl' => $oauthRefreshUrl !== '' ? $oauthRefreshUrl : null,
                ], static fn ($v) => $v !== null);
            }

            $collection['authProfiles'] = array_values(array_merge(
                (array)($collection['authProfiles'] ?? []),
                [[
                    'id' => $profileId,
                    'name' => $authProfileName,
                    'headers' => $inlineAuthHeaders,
                    ...($oauth !== null ? ['oauth' => $oauth] : []),
                ]]
            ));

            $authProfileId = $profileId;
            $inlineAuthHeaders = [];
        }

        $bodyMode = (string)$request->request->get('bodyMode', 'none');
        $body = ['mode' => 'none'];

        if ($bodyMode === 'json') {
            $json = (string)$request->request->get('jsonBody', '');
            $body = [
                'mode' => 'json',
                'json' => $json,
            ];
        } elseif ($bodyMode === 'form') {
            $raw = (string)$request->request->get('formBody', '');
            $pairs = $this->parseKeyValueLines($raw);
            $body = [
                'mode' => 'form',
                'form' => $pairs,
            ];
        }

        $existing['name'] = $name;
        $existingRequest['method'] = $method;
        $existingRequest['urls'] = [
            ...($urlDev !== '' ? ['dev' => $urlDev] : []),
            ...($urlLive !== '' ? ['live' => $urlLive] : []),
        ];
        $existingRequest['activeEnv'] = $activeEnv;
        $existingRequest['authProfileId'] = $authProfileId;
        $existingRequest['headers'] = array_values(array_merge($headersWithoutInlineAuth, $inlineAuthHeaders));
        $existingRequest['body'] = $body;

        $existing['request'] = $existingRequest;
        $configs[$idx] = $existing;
        $collection['configs'] = array_values($configs);

        $store->saveCollection($collection);

        $response = $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
            'tree' => $treeBuilder->build($collection),
        ]);

        $response->headers->set('HX-Trigger', json_encode([
            'config-updated' => [
                'id' => $id,
                'name' => $name,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    #[Route('/configs/{id}/delete', name: 'configs_delete', methods: ['POST'])]
    public function delete(string $id, ConfigStore $store, ConfigTreeBuilder $treeBuilder): Response
    {
        $collection = $store->loadCollection();
        $configs = (array)($collection['configs'] ?? []);

        $configs = array_values(array_filter($configs, static function ($cfg) use ($id) {
            return !(is_array($cfg) && (string)($cfg['id'] ?? '') === $id);
        }));

        $collection['configs'] = $configs;
        $store->saveCollection($collection);

        return $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
            'tree' => $treeBuilder->build($collection),
        ]);
    }

    #[Route('/configs/{id}/apply-bearer', name: 'configs_apply_bearer', methods: ['POST'])]
    public function applyBearer(string $id, Request $request, ConfigStore $store): Response
    {
        $accessToken = trim((string)$request->request->get('accessToken', ''));
        if ($accessToken === '') {
            return new Response('Missing accessToken.', 400);
        }

        $tokenType = trim((string)$request->request->get('tokenType', 'bearer'));
        $refreshToken = trim((string)$request->request->get('refreshToken', ''));
        $expiresIn = $request->request->get('expiresIn', null);

        $collection = $store->loadCollection();

        $configs = (array)($collection['configs'] ?? []);
        $configIdx = null;
        $config = null;

        foreach ($configs as $i => $cfg) {
            if (is_array($cfg) && (string)($cfg['id'] ?? '') === $id) {
                $configIdx = $i;
                $config = $cfg;
                break;
            }
        }

        if ($config === null || $configIdx === null) {
            return new Response('Config not found.', 404);
        }

        $requestDef = (array)($config['request'] ?? []);
        $authProfileId = trim((string)($requestDef['authProfileId'] ?? ''));

        $authHeaderValue = 'Bearer '.$accessToken;
        if ($tokenType !== '' && strtolower($tokenType) !== 'bearer') {
            $authHeaderValue = ucfirst(strtolower($tokenType)).' '.$accessToken;
        }

        if ($authProfileId !== '') {
            $profiles = (array)($collection['authProfiles'] ?? []);
            foreach ($profiles as $pi => $p) {
                if (!is_array($p) || (string)($p['id'] ?? '') !== $authProfileId) {
                    continue;
                }

                $headers = (array)($p['headers'] ?? []);
                $updated = false;

                foreach ($headers as $hi => $h) {
                    if (!is_array($h)) {
                        continue;
                    }

                    $name = strtolower(trim((string)($h['name'] ?? '')));
                    $kind = (string)($h['secretKind'] ?? '');
                    if ($kind === 'bearer' || $name === 'authorization') {
                        $h['name'] = 'Authorization';
                        $h['value'] = $authHeaderValue;
                        $h['isSecret'] = true;
                        $h['secretKind'] = 'bearer';
                        $h['exportPolicy'] = $h['exportPolicy'] ?? 'prompt';
                        $h['placeholder'] = $h['placeholder'] ?? sprintf('{{AQTO_SECRET:%s}}', $h['secretRef'] ?? ('secrets.'.Uuid::v4()->toRfc4122()));
                        $h['secretRef'] = $h['secretRef'] ?? ('secrets.'.Uuid::v4()->toRfc4122());
                        $headers[$hi] = $h;
                        $updated = true;
                        break;
                    }
                }

                if (!$updated) {
                    $secretRef = 'secrets.'.Uuid::v4()->toRfc4122();
                    $headers[] = [
                        'name' => 'Authorization',
                        'value' => $authHeaderValue,
                        'isSecret' => true,
                        'secretKind' => 'bearer',
                        'secretRef' => $secretRef,
                        'exportPolicy' => 'prompt',
                        'placeholder' => sprintf('{{AQTO_SECRET:%s}}', $secretRef),
                    ];
                }

                $p['headers'] = array_values($headers);

                // Persist OAuth token state on the auth profile (v5+).
                $oauth = $p['oauth'] ?? null;
                if (is_array($oauth)) {
                    $oauth['accessToken'] = $accessToken;
                    $oauth['tokenType'] = $tokenType !== '' ? strtolower($tokenType) : 'bearer';
                    $oauth['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
                    if ($refreshToken !== '') {
                        $oauth['refreshToken'] = $refreshToken;
                    }
                    if ($expiresIn !== null && is_numeric($expiresIn) && (int)$expiresIn > 0) {
                        $oauth['expiresAt'] = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', (int)$expiresIn))->format(DATE_ATOM);
                    }
                    $p['oauth'] = $oauth;
                }

                $profiles[$pi] = $p;
                $collection['authProfiles'] = array_values($profiles);
                $store->saveCollection($collection);

                return new Response('OK', 200);
            }

            return new Response('Auth profile not found.', 404);
        }

        // Inline auth: update or add Authorization header.
        $headers = (array)($requestDef['headers'] ?? []);
        $updated = false;

        foreach ($headers as $hi => $h) {
            if (!is_array($h)) {
                continue;
            }
            $name = strtolower(trim((string)($h['name'] ?? '')));
            $kind = (string)($h['secretKind'] ?? '');
            if ($kind === 'bearer' || $name === 'authorization') {
                $h['name'] = 'Authorization';
                $h['value'] = $authHeaderValue;
                $h['isSecret'] = true;
                $h['secretKind'] = 'bearer';
                $h['exportPolicy'] = $h['exportPolicy'] ?? 'prompt';
                $h['placeholder'] = $h['placeholder'] ?? sprintf('{{AQTO_SECRET:%s}}', $h['secretRef'] ?? ('secrets.'.Uuid::v4()->toRfc4122()));
                $h['secretRef'] = $h['secretRef'] ?? ('secrets.'.Uuid::v4()->toRfc4122());
                $headers[$hi] = $h;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $secretRef = 'secrets.'.Uuid::v4()->toRfc4122();
            $headers[] = [
                'name' => 'Authorization',
                'value' => $authHeaderValue,
                'isSecret' => true,
                'secretKind' => 'bearer',
                'secretRef' => $secretRef,
                'exportPolicy' => 'prompt',
                'placeholder' => sprintf('{{AQTO_SECRET:%s}}', $secretRef),
            ];
        }

        $requestDef['headers'] = array_values($headers);
        $config['request'] = $requestDef;
        $configs[$configIdx] = $config;
        $collection['configs'] = array_values($configs);
        $store->saveCollection($collection);

        return new Response('OK', 200);
    }

    private function renderEditConfigError(string $message): Response
    {
        $response = $this->render('partials/config_edit_error.html.twig', [
            'message' => $message,
        ]);

        $response->headers->set('HX-Retarget', '#configEditErrors');
        $response->headers->set('HX-Reswap', 'innerHTML');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param array<int, mixed> $headers
     * @return array<int, mixed>
     */
    private function stripInlineAuthHeaders(array $headers): array
    {
        return array_values(array_filter($headers, static function ($h) {
            if (!is_array($h)) {
                return true;
            }
            $isSecret = (bool)($h['isSecret'] ?? false);
            $kind = (string)($h['secretKind'] ?? '');
            if ($isSecret && in_array($kind, ['bearer', 'apiKey'], true)) {
                return false;
            }
            return true;
        }));
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function detectInlineAuthDefaults(array $config): array
    {
        $request = (array)($config['request'] ?? []);
        $headers = (array)($request['headers'] ?? []);

        foreach ($headers as $h) {
            if (!is_array($h)) {
                continue;
            }

            $kind = (string)($h['secretKind'] ?? '');
            $value = (string)($h['value'] ?? '');

            if ($kind === 'bearer') {
                $token = '';
                $trimmed = trim($value);
                if (str_starts_with($trimmed, 'Bearer ') && !str_contains($trimmed, '{{AQTO_SECRET:')) {
                    $token = trim(substr($trimmed, 7));
                }
                return ['bearer', $token, 'X-API-Key', ''];
            }

            if ($kind === 'apiKey') {
                $name = (string)($h['name'] ?? 'X-API-Key');
                $val = str_contains($value, '{{AQTO_SECRET:') ? '' : $value;
                return ['apiKey', '', $name !== '' ? $name : 'X-API-Key', $val];
            }
        }

        return ['none', '', 'X-API-Key', ''];
    }

    private function renderCreateConfigError(string $message): Response
    {
        $response = $this->render('partials/config_new_error.html.twig', [
            'message' => $message,
        ]);

        // Keep the config list intact; show the error in the modal.
        $response->headers->set('HX-Retarget', '#createConfigErrors');
        $response->headers->set('HX-Reswap', 'innerHTML');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/configs/{id}/env', name: 'configs_set_env', methods: ['POST'])]
    public function setEnv(string $id, Request $request, ConfigStore $store, ConfigTreeBuilder $treeBuilder): Response
    {
        $env = (string)$request->request->get('env', 'dev');
        if (!in_array($env, ['dev', 'live'], true)) {
            $env = 'dev';
        }

        $collection = $store->loadCollection();
        $configs = (array)($collection['configs'] ?? []);

        foreach ($configs as $i => $cfg) {
            if (!is_array($cfg) || (string)($cfg['id'] ?? '') !== $id) {
                continue;
            }

            $urls = (array)($cfg['request']['urls'] ?? []);
            $selectedUrl = trim((string)($urls[$env] ?? ''));
            if ($selectedUrl === '') {
                $otherEnv = $env === 'dev' ? 'live' : 'dev';
                $otherUrl = trim((string)($urls[$otherEnv] ?? ''));
                if ($otherUrl !== '') {
                    $env = $otherEnv;
                }
            }

            $cfg['request']['activeEnv'] = $env;
            $configs[$i] = $cfg;
            break;
        }

        $collection['configs'] = $configs;
        $store->saveCollection($collection);

        return $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
            'tree' => $treeBuilder->build($collection),
        ]);
    }

    /**
     * @return array<int, array{name:string, value:string}>
     */
    private function parseKeyValueLines(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $pairs = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            $key = trim($parts[0] ?? '');
            $val = trim($parts[1] ?? '');

            if ($key === '') {
                continue;
            }

            $pairs[] = ['name' => $key, 'value' => $val];
        }

        return $pairs;
    }
}
