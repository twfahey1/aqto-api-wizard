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
            return new Response('Token URL is required.', 400);
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
                return new Response('Token payload JSON is invalid: '.json_last_error_msg(), 400);
            }
            $options['json'] = $decoded ?? (object)[];
        } else {
            $raw = (string)$request->request->get('tokenBodyForm', '');
            $fields = $this->parseKeyValueLines($raw);
            if ($fields === []) {
                return new Response('Token payload (form) is empty.', 400);
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
                return new Response('Token endpoint did not return JSON.', 422);
            }

            $accessToken = (string)($decoded['access_token'] ?? '');
            $tokenType = strtolower((string)($decoded['token_type'] ?? 'bearer'));
            $refreshToken = (string)($decoded['refresh_token'] ?? '');
            $expiresIn = $decoded['expires_in'] ?? null;

            if ($accessToken === '') {
                return new Response('No access_token found in response.', 422);
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
            return new Response('Token fetch failed: '.$e->getMessage(), 422);
        }
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
