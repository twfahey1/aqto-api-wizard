<?php

namespace App\Controller;

use App\Service\ConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ConfigController extends AbstractController
{
    #[Route('/configs/new', name: 'configs_new', methods: ['GET'])]
    public function new(ConfigStore $store): Response
    {
        $collection = $store->loadCollection();

        return $this->render('partials/config_new.html.twig', [
            'authProfiles' => (array)($collection['authProfiles'] ?? []),
        ]);
    }

    #[Route('/configs', name: 'configs_create', methods: ['POST'])]
    public function create(Request $request, ConfigStore $store): Response
    {
        $collection = $store->loadCollection();

        $name = trim((string)$request->request->get('name', ''));
        $method = strtoupper(trim((string)$request->request->get('method', 'GET')));
        $urlDev = trim((string)$request->request->get('urlDev', ''));
        $urlLive = trim((string)$request->request->get('urlLive', ''));
        $activeEnv = (string)$request->request->get('activeEnv', 'dev');

        if ($name === '' || $urlDev === '' || $urlLive === '') {
            return new Response('Missing required fields.', 400);
        }

        if (!in_array($activeEnv, ['dev', 'live'], true)) {
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
                    'oauth' => $oauth,
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
                    'dev' => $urlDev,
                    'live' => $urlLive,
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

        return $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
        ]);
    }

    #[Route('/configs/{id}/env', name: 'configs_set_env', methods: ['POST'])]
    public function setEnv(string $id, Request $request, ConfigStore $store): Response
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

            $cfg['request']['activeEnv'] = $env;
            $configs[$i] = $cfg;
            break;
        }

        $collection['configs'] = $configs;
        $store->saveCollection($collection);

        return $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
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
