<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OAuthTokenManager
{
    public function __construct(
        private readonly ConfigStore $store,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Ensures the config's auth profile (if OAuth-enabled) has a valid bearer token.
     * Persists refreshed/fetched tokens back into the config collection.
     *
     * @return array{collection: array<string,mixed>, config: array<string,mixed>, error: string|null}
     */
    public function prepareForExecute(string $configId): array
    {
        $collection = $this->store->loadCollection();

        $configs = (array)($collection['configs'] ?? []);
        $config = null;
        foreach ($configs as $cfg) {
            if (is_array($cfg) && (string)($cfg['id'] ?? '') === $configId) {
                $config = $cfg;
                break;
            }
        }

        if (!is_array($config)) {
            return [
                'collection' => $collection,
                'config' => [],
                'error' => 'Config not found.',
            ];
        }

        $request = (array)($config['request'] ?? []);
        $authProfileId = trim((string)($request['authProfileId'] ?? ''));

        if ($authProfileId === '') {
            return [
                'collection' => $collection,
                'config' => $config,
                'error' => null,
            ];
        }

        $profiles = (array)($collection['authProfiles'] ?? []);
        $profileIndex = null;
        $profile = null;

        foreach ($profiles as $i => $p) {
            if (is_array($p) && (string)($p['id'] ?? '') === $authProfileId) {
                $profileIndex = $i;
                $profile = $p;
                break;
            }
        }

        if (!is_array($profile) || $profileIndex === null) {
            return [
                'collection' => $collection,
                'config' => $config,
                'error' => 'Auth profile not found.',
            ];
        }

        $oauth = $profile['oauth'] ?? null;
        if (!is_array($oauth)) {
            return [
                'collection' => $collection,
                'config' => $config,
                'error' => null,
            ];
        }

        $tokenUrl = trim((string)($oauth['tokenUrl'] ?? ''));
        $refreshUrl = trim((string)($oauth['refreshUrl'] ?? ''));

        if ($tokenUrl === '' && $refreshUrl === '') {
            return [
                'collection' => $collection,
                'config' => $config,
                'error' => null,
            ];
        }

        $accessToken = trim((string)($oauth['accessToken'] ?? ''));
        $tokenType = strtolower(trim((string)($oauth['tokenType'] ?? 'bearer')));
        $expiresAt = trim((string)($oauth['expiresAt'] ?? ''));

        $now = new \DateTimeImmutable('now');
        $isValid = false;

        if ($accessToken !== '' && !$this->isPlaceholderSecret($accessToken)) {
            if ($expiresAt === '') {
                // No expiry info; treat as valid and let the API tell us otherwise.
                $isValid = true;
            } else {
                try {
                    $exp = new \DateTimeImmutable($expiresAt);
                    // 60s leeway.
                    $isValid = $exp->getTimestamp() > ($now->getTimestamp() + 60);
                } catch (\Throwable) {
                    $isValid = false;
                }
            }
        }

        if (!$isValid) {
            $refreshToken = trim((string)($oauth['refreshToken'] ?? ''));

            $didUpdate = false;
            $error = null;

            if (($refreshUrl !== '' || $tokenUrl !== '') && $refreshToken !== '' && !$this->isPlaceholderSecret($refreshToken)) {
                $url = $refreshUrl !== '' ? $refreshUrl : $tokenUrl;
                $resp = $this->requestToken(
                    $url,
                    $this->buildRefreshPayload($oauth, $refreshToken),
                    $tokenUrl,
                    $refreshUrl,
                );

                if ($resp['ok']) {
                    $oauth = $this->applyTokenResponse($oauth, $resp['decoded']);
                    $didUpdate = true;
                } else {
                    $error = $resp['error'];
                }
            }

            if (!$didUpdate) {
                $clientId = trim((string)($oauth['clientId'] ?? ''));
                $clientSecret = trim((string)($oauth['clientSecret'] ?? ''));

                if ($clientId === '' || $clientSecret === '' || $this->isPlaceholderSecret($clientSecret)) {
                    return [
                        'collection' => $collection,
                        'config' => $config,
                        'error' => 'OAuth credentials missing on auth profile (client_id/client_secret). Open the OAuth modal once and enable “Remember credentials”.',
                    ];
                }

                if ($tokenUrl === '') {
                    return [
                        'collection' => $collection,
                        'config' => $config,
                        'error' => 'OAuth tokenUrl is missing on auth profile.',
                    ];
                }

                $resp = $this->requestToken(
                    $tokenUrl,
                    $this->buildClientCredentialsPayload($oauth),
                    $tokenUrl,
                    $refreshUrl,
                );

                if (!$resp['ok']) {
                    $msg = 'OAuth token fetch failed.';
                    if ($error) {
                        $msg .= ' Refresh also failed: '.$error;
                    }
                    if ($resp['error']) {
                        $msg .= ' '.$resp['error'];
                    }

                    return [
                        'collection' => $collection,
                        'config' => $config,
                        'error' => $msg,
                    ];
                }

                $oauth = $this->applyTokenResponse($oauth, $resp['decoded']);
                $didUpdate = true;
            }

            if ($didUpdate) {
                $profile['oauth'] = $oauth;
                $profile = $this->ensureBearerHeader($profile, (string)($oauth['accessToken'] ?? ''), (string)($oauth['tokenType'] ?? 'bearer'));
                $profiles[$profileIndex] = $profile;
                $collection['authProfiles'] = array_values($profiles);

                $this->store->saveCollection($collection);

                // Reload config from the persisted collection to keep downstream behavior consistent.
                $collection = $this->store->loadCollection();
                foreach ((array)($collection['configs'] ?? []) as $cfg) {
                    if (is_array($cfg) && (string)($cfg['id'] ?? '') === $configId) {
                        $config = $cfg;
                        break;
                    }
                }

                return [
                    'collection' => $collection,
                    'config' => $config,
                    'error' => null,
                ];
            }
        }

        // Token is valid; make sure the profile header is in sync.
        $profile = $this->ensureBearerHeader($profile, $accessToken, $tokenType);
        $profiles[$profileIndex] = $profile;
        $collection['authProfiles'] = array_values($profiles);
        $this->store->saveCollection($collection);

        return [
            'collection' => $collection,
            'config' => $config,
            'error' => null,
        ];
    }

    /**
     * @param array<string,mixed> $oauth
     * @return array<string,string>
     */
    private function buildClientCredentialsPayload(array $oauth): array
    {
        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => (string)($oauth['clientId'] ?? ''),
            'client_secret' => (string)($oauth['clientSecret'] ?? ''),
        ];

        $scope = trim((string)($oauth['scope'] ?? ''));
        if ($scope !== '') {
            $payload['scope'] = $scope;
        }

        $audience = trim((string)($oauth['audience'] ?? ''));
        if ($audience !== '') {
            $payload['audience'] = $audience;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $oauth
     * @return array<string,string>
     */
    private function buildRefreshPayload(array $oauth, string $refreshToken): array
    {
        $payload = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        $clientId = trim((string)($oauth['clientId'] ?? ''));
        $clientSecret = trim((string)($oauth['clientSecret'] ?? ''));

        if ($clientId !== '') {
            $payload['client_id'] = $clientId;
        }
        if ($clientSecret !== '' && !$this->isPlaceholderSecret($clientSecret)) {
            $payload['client_secret'] = $clientSecret;
        }

        $scope = trim((string)($oauth['scope'] ?? ''));
        if ($scope !== '') {
            $payload['scope'] = $scope;
        }

        return $payload;
    }

    /**
     * @param array<string,string> $payload
     * @return array{ok:bool, decoded:array<string,mixed>, error:string|null}
     */
    private function requestToken(string $url, array $payload, string $tokenUrl, string $refreshUrl): array
    {
        try {
            $resp = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $payload,
            ]);

            $status = $resp->getStatusCode();
            $body = $resp->getContent(false);

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return [
                    'ok' => false,
                    'decoded' => [],
                    'error' => sprintf('Token endpoint did not return JSON (HTTP %d).', $status),
                ];
            }

            if (!isset($decoded['access_token']) || (string)$decoded['access_token'] === '') {
                return [
                    'ok' => false,
                    'decoded' => $decoded,
                    'error' => 'No access_token found in token response.',
                ];
            }

            return [
                'ok' => true,
                'decoded' => $decoded,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'decoded' => [],
                'error' => 'Token request failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $oauth
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private function applyTokenResponse(array $oauth, array $decoded): array
    {
        $now = new \DateTimeImmutable('now');

        $accessToken = (string)($decoded['access_token'] ?? '');
        $tokenType = strtolower((string)($decoded['token_type'] ?? 'bearer'));
        $refreshToken = (string)($decoded['refresh_token'] ?? '');
        $expiresIn = $decoded['expires_in'] ?? null;

        $oauth['accessToken'] = $accessToken;
        $oauth['tokenType'] = $tokenType !== '' ? $tokenType : 'bearer';
        $oauth['updatedAt'] = $now->format(DATE_ATOM);

        if ($refreshToken !== '') {
            $oauth['refreshToken'] = $refreshToken;
        }

        $seconds = is_numeric($expiresIn) ? (int)$expiresIn : null;
        if ($seconds !== null && $seconds > 0) {
            $oauth['expiresAt'] = $now->modify(sprintf('+%d seconds', $seconds))->format(DATE_ATOM);
        }

        return $oauth;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function ensureBearerHeader(array $profile, string $accessToken, string $tokenType): array
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '' || $this->isPlaceholderSecret($accessToken)) {
            return $profile;
        }

        $scheme = strtolower(trim($tokenType)) === 'bearer' ? 'Bearer' : ucfirst(strtolower(trim($tokenType)));
        $authHeaderValue = $scheme.' '.$accessToken;

        $headers = (array)($profile['headers'] ?? []);
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
                $headers[$hi] = $h;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $headers[] = [
                'name' => 'Authorization',
                'value' => $authHeaderValue,
                'isSecret' => true,
                'secretKind' => 'bearer',
                // secretRef etc are added elsewhere; keep minimal.
            ];
        }

        $profile['headers'] = array_values($headers);
        return $profile;
    }

    private function isPlaceholderSecret(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return true;
        }

        if (str_starts_with($trimmed, '{{AQTO_SECRET:') && str_ends_with($trimmed, '}}')) {
            return true;
        }

        if ($trimmed === '<<REDACTED>>') {
            return true;
        }

        return false;
    }
}
