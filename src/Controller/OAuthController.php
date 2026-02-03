<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OAuthController extends AbstractController
{
    #[Route('/oauth/token-modal', name: 'oauth_token_modal', methods: ['GET'])]
    public function tokenModal(): Response
    {
        return $this->render('partials/oauth_token_modal.html.twig');
    }

    #[Route('/oauth/fetch-token', name: 'oauth_fetch_token', methods: ['POST'])]
    public function fetchToken(Request $request, HttpClientInterface $httpClient): Response
    {
        $tokenUrl = trim((string)$request->request->get('tokenUrl', ''));
        $refreshUrl = trim((string)$request->request->get('refreshUrl', ''));
        $bodyMode = (string)$request->request->get('tokenBodyMode', 'form');

        if ($tokenUrl === '') {
            return $this->renderOAuthError('Token URL is required.', [
                'bodyMode' => $bodyMode,
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
                    'response' => $this->redactForDiagnostics($decoded),
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
            ];

            $response = $this->render('partials/oauth_token_result.html.twig', [
                'status' => $status,
                'decoded' => $decoded,
            ]);

            $response->headers->set('Cache-Control', 'no-store');

            // HTMX: trigger a client-side event containing the token.
            $response->headers->set('HX-Trigger', json_encode([
                'oauthTokenReceived' => $triggerPayload,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $response;
        } catch (\Throwable $e) {
            return $this->renderOAuthError('Token fetch failed.', [
                'tokenUrl' => $tokenUrl,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
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
        $response->headers->set('HX-Trigger', json_encode([
            'oauthTokenError' => [
                'message' => $message,
            ],
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
