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
            return $this->render('partials/oauth_token_error.html.twig', [
                'message' => 'Token URL is required.',
            ], new Response('', 400));
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
                return $this->render('partials/oauth_token_error.html.twig', [
                    'message' => 'Token payload JSON is invalid: '.json_last_error_msg(),
                ], new Response('', 400));
            }
            $options['json'] = $decoded ?? (object)[];
        } else {
            $keys = $request->request->get('tokenBodyFormKeys', []);
            $values = $request->request->get('tokenBodyFormValues', []);

            $fields = [];
            if (is_array($keys) && is_array($values)) {
                $fields = $this->parseKeyValueFields($keys, $values);
            }

            if ($fields === []) {
                // Backwards compatibility: accept textarea format.
                $raw = (string)$request->request->get('tokenBodyForm', '');
                $fields = $this->parseKeyValueLines($raw);
            }

            if ($fields === []) {
                return $this->render('partials/oauth_token_error.html.twig', [
                    'message' => 'Token payload (form) is empty.',
                ], new Response('', 400));
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
                return $this->render('partials/oauth_token_error.html.twig', [
                    'message' => 'Token endpoint did not return JSON.',
                    'details' => [
                        'status' => $status,
                        'body' => $body,
                    ],
                ], new Response('', 422));
            }

            $accessToken = (string)($decoded['access_token'] ?? '');
            $tokenType = strtolower((string)($decoded['token_type'] ?? 'bearer'));
            $refreshToken = (string)($decoded['refresh_token'] ?? '');
            $expiresIn = $decoded['expires_in'] ?? null;

            if ($accessToken === '') {
                return $this->render('partials/oauth_token_error.html.twig', [
                    'message' => 'No access_token found in response.',
                    'details' => $decoded,
                ], new Response('', 422));
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

            // HTMX: trigger a client-side event containing the token.
            $response->headers->set('HX-Trigger', json_encode([
                'oauthTokenReceived' => $triggerPayload,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $response;
        } catch (\Throwable $e) {
            return $this->render('partials/oauth_token_error.html.twig', [
                'message' => 'Token fetch failed: '.$e->getMessage(),
            ], new Response('', 422));
        }
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
