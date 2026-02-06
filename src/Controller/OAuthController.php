<?php

namespace App\Controller;

use App\Service\ConfigStore;
use App\Service\OAuthTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OAuthController extends AbstractController
{
    #[Route('/oauth/token-modal', name: 'oauth_token_modal', methods: ['GET'])]
    public function tokenModal(Request $request, ConfigStore $store): Response
    {
        $applyEnv = (string)$request->query->get('applyEnv', 'dev');
        if (!in_array($applyEnv, ['dev', 'live'], true)) {
            $applyEnv = 'dev';
        }

        $configId = trim((string)$request->query->get('configId', ''));
        $configName = trim((string)$request->query->get('configName', ''));
        $origin = trim((string)$request->query->get('origin', ''));

        $mode = (string)$request->query->get('mode', 'fetch');
        if (!in_array($mode, ['fetch', 'refresh'], true)) {
            $mode = 'fetch';
        }

        $tokenUrl = trim((string)$request->query->get('tokenUrl', ''));
        $refreshUrl = trim((string)$request->query->get('refreshUrl', ''));
        $refreshToken = trim((string)$request->query->get('refreshToken', ''));

        $authProfileId = trim((string)$request->query->get('authProfileId', ''));
        $oauthDefaults = [
            'clientId' => '',
            'clientSecret' => '',
            'scope' => '',
            'audience' => '',
            'grantType' => 'client_credentials',
            'username' => '',
            'password' => '',
            'refreshToken' => $refreshToken,
            'tokenUrl' => $tokenUrl,
            'refreshUrl' => $refreshUrl,
        ];

        if ($authProfileId !== '') {
            $collection = $store->loadCollection();
            foreach ((array)($collection['authProfiles'] ?? []) as $p) {
                if (!is_array($p) || (string)($p['id'] ?? '') !== $authProfileId) {
                    continue;
                }
                $oauth = $p['oauth'] ?? null;
                if (is_array($oauth)) {
                    $oauthDefaults['clientId'] = (string)($oauth['clientId'] ?? '');
                    $oauthDefaults['clientSecret'] = (string)($oauth['clientSecret'] ?? '');
                    $oauthDefaults['scope'] = (string)($oauth['scope'] ?? '');
                    $oauthDefaults['audience'] = (string)($oauth['audience'] ?? '');
                    $oauthDefaults['grantType'] = (string)($oauth['grantType'] ?? $oauthDefaults['grantType']);
                    $oauthDefaults['username'] = (string)($oauth['username'] ?? '');
                    $oauthDefaults['password'] = (string)($oauth['password'] ?? '');
                    $oauthDefaults['refreshToken'] = $oauthDefaults['refreshToken'] !== '' ? $oauthDefaults['refreshToken'] : (string)($oauth['refreshToken'] ?? '');
                    $oauthDefaults['tokenUrl'] = $oauthDefaults['tokenUrl'] !== '' ? $oauthDefaults['tokenUrl'] : (string)($oauth['tokenUrl'] ?? '');
                    $oauthDefaults['refreshUrl'] = $oauthDefaults['refreshUrl'] !== '' ? $oauthDefaults['refreshUrl'] : (string)($oauth['refreshUrl'] ?? '');
                }
                break;
            }
        }

        return $this->render('partials/oauth_token_modal.html.twig', [
            'applyEnv' => $applyEnv,
            'configId' => $configId,
            'configName' => $configName,
            'origin' => $origin,
            'mode' => $mode,
            'tokenUrl' => $oauthDefaults['tokenUrl'],
            'refreshUrl' => $oauthDefaults['refreshUrl'],
            'refreshToken' => $oauthDefaults['refreshToken'],
            'authProfileId' => $authProfileId,
            'oauthDefaults' => $oauthDefaults,
        ]);
    }

    #[Route('/oauth/fetch-token', name: 'oauth_fetch_token', methods: ['POST'])]
    public function fetchToken(Request $request, HttpClientInterface $httpClient, ConfigStore $store): Response
    {
        $configId = trim((string)$request->request->get('configId', ''));
        $configName = trim((string)$request->request->get('configName', ''));
        $origin = trim((string)$request->request->get('origin', ''));

        $mode = (string)$request->request->get('mode', 'fetch');
        if (!in_array($mode, ['fetch', 'refresh'], true)) {
            $mode = 'fetch';
        }

        $tokenUrl = trim((string)$request->request->get('tokenUrl', ''));
        $refreshUrl = trim((string)$request->request->get('refreshUrl', ''));
        $bodyMode = (string)$request->request->get('tokenBodyMode', 'form');

        $authProfileId = trim((string)$request->request->get('authProfileId', ''));
        $rememberCredentials = (bool)$request->request->get('rememberCredentials', false);
        $applyEnv = (string)$request->request->get('applyEnv', 'dev');
        if (!in_array($applyEnv, ['dev', 'live'], true)) {
            $applyEnv = 'dev';
        }

        if ($tokenUrl === '') {
            return $this->renderOAuthError('Token URL is required.', [
                'bodyMode' => $bodyMode,
                'mode' => $mode,
                'applyEnv' => $applyEnv,
                'configId' => $configId,
                'configName' => $configName,
                'origin' => $origin,
            ]);
        }

        $options = [
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];

        if ($bodyMode === 'json') {
            $rawJson = (string)$request->request->get('tokenBodyJson', '');
            $decoded = json_decode($rawJson, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                return $this->renderOAuthError('Token payload JSON is invalid: '.json_last_error_msg(), [
                    'bodyMode' => $bodyMode,
                    'mode' => $mode,
                    'applyEnv' => $applyEnv,
                    'configId' => $configId,
                    'configName' => $configName,
                    'origin' => $origin,
                ]);
            }
            $options['json'] = $decoded ?? (object)[];
        } else {
            // These fields are named tokenBodyFormKeys[] / tokenBodyFormValues[].
            // IMPORTANT: InputBag::get() throws BadRequestHttpException when the value is an array.
            $post = $request->request->all();
            $keys = $post['tokenBodyFormKeys'] ?? [];
            $values = $post['tokenBodyFormValues'] ?? [];

            if (!is_array($keys)) {
                $keys = [];
            }
            if (!is_array($values)) {
                $values = [];
            }

            $fields = [];
            $fields = $this->parseKeyValueFields($keys, $values);

            if ($fields === []) {
                // Backwards compatibility: accept textarea format.
                $raw = (string)$request->request->get('tokenBodyForm', '');
                $fields = $this->parseKeyValueLines($raw);
            }

            if ($fields === []) {
                return $this->renderOAuthError('Token payload (form) is empty.', [
                    'bodyMode' => $bodyMode,
                    'mode' => $mode,
                    'applyEnv' => $applyEnv,
                    'configId' => $configId,
                    'configName' => $configName,
                    'origin' => $origin,
                ]);
            }
            $options['body'] = $fields;
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        try {
            $resp = $httpClient->request('POST', $tokenUrl, $options);
            $status = $resp->getStatusCode();
            $body = $resp->getContent(false);

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return $this->renderOAuthError('Token endpoint did not return JSON.', [
                    'status' => $status,
                    'tokenUrl' => $tokenUrl,
                    'bodySnippet' => $this->truncateForDisplay($body),
                    'mode' => $mode,
                    'applyEnv' => $applyEnv,
                    'configId' => $configId,
                    'configName' => $configName,
                    'origin' => $origin,
                ]);
            }

            $accessToken = (string)($decoded['access_token'] ?? '');
            $tokenType = strtolower((string)($decoded['token_type'] ?? 'bearer'));
            $refreshToken = (string)($decoded['refresh_token'] ?? '');
            $expiresIn = $decoded['expires_in'] ?? null;

            if ($accessToken === '') {
                return $this->renderOAuthError('No access_token found in response.', [
                    'status' => $status,
                    'tokenUrl' => $tokenUrl,
                    // Show the full response to aid debugging when the token endpoint returns a non-standard payload.
                    // This app is local-first; users are responsible for securing their stored config files.
                    'response' => $decoded,
                    'mode' => $mode,
                    'applyEnv' => $applyEnv,
                    'configId' => $configId,
                    'configName' => $configName,
                    'origin' => $origin,
                ]);
            }

            $triggerPayload = [
                'accessToken' => $accessToken,
                'tokenType' => $tokenType,
                'refreshToken' => $refreshToken,
                'expiresIn' => $expiresIn,
                'tokenUrl' => $tokenUrl,
                'refreshUrl' => $refreshUrl,
                'status' => $status,
                'applyEnv' => $applyEnv,
                'mode' => $mode,
                'configId' => $configId,
                'configName' => $configName,
                'origin' => $origin,
                'authProfileId' => $authProfileId,
            ];

            // Persist OAuth details into the auth profile when requested.
            if ($rememberCredentials && $authProfileId !== '' && $bodyMode === 'form') {
                $collection = $store->loadCollection();
                $profiles = (array)($collection['authProfiles'] ?? []);

                foreach ($profiles as $pi => $p) {
                    if (!is_array($p) || (string)($p['id'] ?? '') !== $authProfileId) {
                        continue;
                    }

                    $oauth = $p['oauth'] ?? [];
                    $oauth = is_array($oauth) ? $oauth : [];
                    $oauth['tokenUrl'] = $tokenUrl;
                    if ($refreshUrl !== '') {
                        $oauth['refreshUrl'] = $refreshUrl;
                    }

                    // Store common OAuth fields from the posted form payload.
                    $posted = $options['body'] ?? [];
                    if (is_array($posted)) {
                        if (isset($posted['client_id'])) {
                            $oauth['clientId'] = (string)$posted['client_id'];
                        }
                        if (isset($posted['client_secret'])) {
                            $oauth['clientSecret'] = (string)$posted['client_secret'];
                        }
                        if (isset($posted['scope'])) {
                            $oauth['scope'] = (string)$posted['scope'];
                        }
                        if (isset($posted['audience'])) {
                            $oauth['audience'] = (string)$posted['audience'];
                        }
                        if (isset($posted['grant_type'])) {
                            $oauth['grantType'] = (string)$posted['grant_type'];
                        }
                        if (isset($posted['username'])) {
                            $oauth['username'] = (string)$posted['username'];
                        }
                        if (isset($posted['password'])) {
                            $oauth['password'] = (string)$posted['password'];
                        }
                    }

                    // Persist token state as well.
                    $oauth['accessToken'] = $accessToken;
                    $oauth['tokenType'] = $tokenType !== '' ? $tokenType : 'bearer';
                    $oauth['updatedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
                    if ($refreshToken !== '') {
                        $oauth['refreshToken'] = $refreshToken;
                    }
                    if ($expiresIn !== null && is_numeric($expiresIn) && (int)$expiresIn > 0) {
                        $oauth['expiresAt'] = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', (int)$expiresIn))->format(DATE_ATOM);
                    }

                    $p['oauth'] = $oauth;
                    $profiles[$pi] = $p;
                    $collection['authProfiles'] = array_values($profiles);
                    $store->saveCollection($collection);
                    $triggerPayload['persisted'] = true;
                    break;
                }
            }

            $response = $this->render('partials/oauth_token_result.html.twig', [
                'status' => $status,
                'decoded' => $decoded,
                'mode' => $mode,
            ]);

            $response->headers->set('Cache-Control', 'no-store');

            // HTMX: trigger a client-side event containing the token.
            $response->headers->set('HX-Trigger', json_encode([
                'oauth-token-received' => $triggerPayload,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $response;
        } catch (\Throwable $e) {
            return $this->renderOAuthError('Token fetch failed.', [
                'tokenUrl' => $tokenUrl,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'mode' => $mode,
                'applyEnv' => $applyEnv,
                'configId' => $configId,
                'configName' => $configName,
                'origin' => $origin,
            ]);
        }
    }

    #[Route('/oauth/refresh-from-config', name: 'oauth_refresh_from_config', methods: ['POST'])]
    public function refreshFromConfig(Request $request, OAuthTokenManager $tokenManager): Response
    {
        $configId = trim((string)$request->request->get('configId', ''));
        $configName = trim((string)$request->request->get('configName', ''));
        $origin = trim((string)$request->request->get('origin', ''));

        $applyEnv = (string)$request->request->get('applyEnv', 'dev');
        if (!in_array($applyEnv, ['dev', 'live'], true)) {
            $applyEnv = 'dev';
        }

        if ($configId === '') {
            return $this->renderOAuthError('configId is required.', [
                'mode' => 'refresh',
                'applyEnv' => $applyEnv,
                'configId' => $configId,
                'configName' => $configName,
                'origin' => $origin,
            ]);
        }

        $result = $tokenManager->refreshForConfig($configId);
        if ($result['error']) {
            return $this->renderOAuthError($result['error'], [
                'mode' => 'refresh',
                'applyEnv' => $applyEnv,
                'configId' => $configId,
                'configName' => $configName,
                'origin' => $origin,
            ]);
        }

        $collection = $result['collection'];
        $config = $result['config'];

        $authProfileId = trim((string)($config['request']['authProfileId'] ?? ''));
        $oauth = null;

        foreach ((array)($collection['authProfiles'] ?? []) as $p) {
            if (!is_array($p) || (string)($p['id'] ?? '') !== $authProfileId) {
                continue;
            }
            $maybe = $p['oauth'] ?? null;
            if (is_array($maybe)) {
                $oauth = $maybe;
            }
            break;
        }

        if (!is_array($oauth)) {
            return $this->renderOAuthError('OAuth is not configured on this auth profile.', [
                'mode' => 'refresh',
                'applyEnv' => $applyEnv,
                'configId' => $configId,
                'configName' => $configName,
                'origin' => $origin,
                'authProfileId' => $authProfileId,
            ]);
        }

        $accessToken = (string)($oauth['accessToken'] ?? '');
        $tokenType = strtolower((string)($oauth['tokenType'] ?? 'bearer'));
        $refreshToken = (string)($oauth['refreshToken'] ?? '');

        $expiresIn = null;
        $expiresAt = (string)($oauth['expiresAt'] ?? '');
        if ($expiresAt !== '') {
            try {
                $exp = new \DateTimeImmutable($expiresAt);
                $delta = $exp->getTimestamp() - (new \DateTimeImmutable('now'))->getTimestamp();
                if ($delta > 0) {
                    $expiresIn = $delta;
                }
            } catch (\Throwable) {
                $expiresIn = null;
            }
        }

        $triggerPayload = [
            'accessToken' => $accessToken,
            'tokenType' => $tokenType,
            'refreshToken' => $refreshToken,
            'expiresIn' => $expiresIn,
            'tokenUrl' => (string)($oauth['tokenUrl'] ?? ''),
            'refreshUrl' => (string)($oauth['refreshUrl'] ?? ''),
            'status' => 200,
            'applyEnv' => $applyEnv,
            'mode' => 'refresh',
            'configId' => $configId,
            'configName' => $configName,
            'origin' => $origin,
            'authProfileId' => $authProfileId,
            'persisted' => true,
        ];

        $response = new Response('', 200);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('HX-Trigger', json_encode([
            'oauth-token-received' => $triggerPayload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    /**
     * Always return a 200 so HTMX swaps the fragment into the modal.
     *
     * @param array<string, mixed> $details
     */
    private function renderOAuthError(string $message, array $details = []): Response
    {
        $response = $this->render('partials/oauth_token_error.html.twig', [
            'message' => $message,
            'details' => $details === [] ? null : $details,
        ]);

        $response->headers->set('Cache-Control', 'no-store');
        $payload = ['message' => $message];
        foreach (['mode', 'applyEnv', 'configId', 'configName', 'origin', 'status'] as $k) {
            if (array_key_exists($k, $details)) {
                $payload[$k] = $details[$k];
            }
        }

        $response->headers->set('HX-Trigger', json_encode([
            'oauth-token-error' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    private function truncateForDisplay(string $raw, int $maxBytes = 2000): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (strlen($raw) <= $maxBytes) {
            return $raw;
        }

        return substr($raw, 0, $maxBytes)."\n…(truncated)…";
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redactForDiagnostics(array $data): array
    {
        $redacted = [];
        foreach ($data as $key => $value) {
            $keyStr = strtolower((string)$key);
            $isSensitive = str_contains($keyStr, 'secret')
                || str_contains($keyStr, 'password')
                || str_contains($keyStr, 'token');

            if ($isSensitive) {
                $redacted[$key] = is_string($value) && $value !== '' ? '***redacted***' : $value;
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    /**
     * @param array<int, mixed> $keys
     * @param array<int, mixed> $values
     * @return array<string, string>
     */
    private function parseKeyValueFields(array $keys, array $values): array
    {
        $pairs = [];
        $count = max(count($keys), count($values));

        for ($i = 0; $i < $count; $i++) {
            $key = trim((string)($keys[$i] ?? ''));
            $val = (string)($values[$i] ?? '');
            $val = trim($val);

            if ($key === '') {
                continue;
            }

            $pairs[$key] = $val;
        }

        return $pairs;
    }

    /**
     * @return array<string, string>
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

            $pairs[$key] = $val;
        }

        return $pairs;
    }
}
