<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RequestExecutor
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, array<string, mixed>> $authProfiles
     * @return array{status:int|null, headers:array<string, array<int, string>>, contentType:string|null, body:string, error:string|null}
     */
    public function execute(array $config, array $authProfiles = []): array
    {
        $request = (array)($config['request'] ?? []);

        $method = strtoupper((string)($request['method'] ?? 'GET'));
        $url = $this->resolveUrl($request);

        if ($url === '') {
            return [
                'status' => null,
                'headers' => [],
                'contentType' => null,
                'body' => '',
                'error' => 'Request URL is missing.',
            ];
        }

        $headers = $this->buildHeaders($request, $authProfiles);
        $query = $this->buildQuery($request);

        $options = [
            'headers' => $headers,
            'query' => $query,
        ];

        $body = (array)($request['body'] ?? []);
        $mode = (string)($body['mode'] ?? 'none');

        if ($mode === 'json') {
            $rawJson = (string)($body['json'] ?? '');
            $decoded = json_decode($rawJson, true);

            if ($rawJson !== '' && ($decoded === null && json_last_error() !== JSON_ERROR_NONE)) {
                throw new \RuntimeException('JSON body is not valid JSON: '.json_last_error_msg());
            }

            $options['json'] = $decoded ?? (object)[];
        } elseif ($mode === 'form') {
            $fields = [];
            foreach ((array)($body['form'] ?? []) as $pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $key = trim((string)($pair['name'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $fields[$key] = (string)($pair['value'] ?? '');
            }

            $options['body'] = $fields;
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        } elseif ($mode === 'raw') {
            $content = (string)($body['content'] ?? '');
            $options['body'] = $content;

            $contentType = (string)($body['contentType'] ?? '');
            if ($contentType !== '') {
                $options['headers']['Content-Type'] = $contentType;
            }
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);
            $contentType = $responseHeaders['content-type'][0] ?? null;
            $content = $response->getContent(false);

            return [
                'status' => $status,
                'headers' => $responseHeaders,
                'contentType' => $contentType,
                'body' => $content,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => null,
                'headers' => [],
                'contentType' => null,
                'body' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $request
     * @param array<int, array<string, mixed>> $authProfiles
     * @return array<string, string>
     */
    private function buildHeaders(array $request, array $authProfiles): array
    {
        $headers = [];

        $authProfileId = trim((string)($request['authProfileId'] ?? ''));
        if ($authProfileId !== '') {
            foreach ($authProfiles as $profile) {
                if (!is_array($profile) || (string)($profile['id'] ?? '') !== $authProfileId) {
                    continue;
                }
                foreach ((array)($profile['headers'] ?? []) as $header) {
                    $this->applyHeader($headers, $header);
                }
                break;
            }
        }

        foreach ((array)($request['headers'] ?? []) as $header) {
            $this->applyHeader($headers, $header);
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     * @param mixed $header
     */
    private function applyHeader(array &$headers, mixed $header): void
    {
        if (!is_array($header)) {
            return;
        }

        $name = trim((string)($header['name'] ?? ''));
        $value = $header['value'] ?? null;
        $value = is_string($value) ? $value : (is_null($value) ? '' : (string)$value);

        if ($name === '') {
            return;
        }

        if (($header['isSecret'] ?? false) && $this->isPlaceholderSecret($value)) {
            throw new \RuntimeException(sprintf('Missing secret for header "%s".', $name));
        }

        $headers[$name] = $value;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function resolveUrl(array $request): string
    {
        if (isset($request['urls']) && is_array($request['urls'])) {
            $urls = (array)$request['urls'];
            $env = (string)($request['activeEnv'] ?? 'dev');
            $env = in_array($env, ['dev', 'live'], true) ? $env : 'dev';

            $selected = trim((string)($urls[$env] ?? ''));
            if ($selected !== '') {
                return $selected;
            }

            $otherEnv = $env === 'dev' ? 'live' : 'dev';
            $fallback = trim((string)($urls[$otherEnv] ?? ''));
            if ($fallback !== '') {
                return $fallback;
            }

            return '';
        }

        return (string)($request['url'] ?? '');
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function buildQuery(array $request): array
    {
        $query = [];
        foreach ((array)($request['query'] ?? []) as $param) {
            if (!is_array($param)) {
                continue;
            }

            $name = trim((string)($param['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $query[$name] = $param['value'] ?? null;
        }

        return $query;
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
