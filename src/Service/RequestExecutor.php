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
     * @return array{status:int|null, headers:array<string, array<int, string>>, contentType:string|null, body:string, error:string|null}
     */
    public function execute(array $config): array
    {
        $request = (array)($config['request'] ?? []);

        $method = strtoupper((string)($request['method'] ?? 'GET'));
        $url = (string)($request['url'] ?? '');

        if ($url === '') {
            return [
                'status' => null,
                'headers' => [],
                'contentType' => null,
                'body' => '',
                'error' => 'Request URL is missing.',
            ];
        }

        $headers = $this->buildHeaders($request);
        $query = $this->buildQuery($request);

        $options = [
            'headers' => $headers,
            'query' => $query,
        ];

        $body = (array)($request['body'] ?? []);
        $mode = (string)($body['mode'] ?? 'none');

        if ($mode === 'raw') {
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
     * @return array<string, string>
     */
    private function buildHeaders(array $request): array
    {
        $headers = [];
        foreach ((array)($request['headers'] ?? []) as $header) {
            if (!is_array($header)) {
                continue;
            }

            $name = trim((string)($header['name'] ?? ''));
            $value = $header['value'] ?? null;
            $value = is_string($value) ? $value : (is_null($value) ? '' : (string)$value);

            if ($name === '') {
                continue;
            }

            if (($header['isSecret'] ?? false) && $this->isPlaceholderSecret($value)) {
                throw new \RuntimeException(sprintf('Missing secret for header "%s".', $name));
            }

            $headers[$name] = $value;
        }

        return $headers;
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
