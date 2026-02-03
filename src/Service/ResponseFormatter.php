<?php

namespace App\Service;

final class ResponseFormatter
{
    /**
     * @return array{detectedType:string, pretty:string}
     */
    public function format(string $body, ?string $contentType): array
    {
        $type = $this->detectType($body, $contentType);

        return [
            'detectedType' => $type,
            'pretty' => match ($type) {
                'json' => $this->prettyJson($body),
                'xml' => $this->prettyXml($body),
                'html' => $body, // Display as escaped source in Twig.
                default => $body,
            },
        ];
    }

    private function detectType(string $body, ?string $contentType): string
    {
        $ct = strtolower((string)($contentType ?? ''));

        if (str_contains($ct, 'json') || str_contains($ct, '+json')) {
            return 'json';
        }

        if (str_contains($ct, 'xml') || str_contains($ct, '+xml')) {
            return 'xml';
        }

        if (str_contains($ct, 'html')) {
            return 'html';
        }

        $trimmed = ltrim($body);
        if ($trimmed === '') {
            return 'text';
        }

        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $decoded = json_decode($body, true);
            if ($decoded !== null || json_last_error() === JSON_ERROR_NONE) {
                return 'json';
            }
        }

        if (str_starts_with($trimmed, '<')) {
            // Could be XML or HTML; assume XML if it parses.
            if ($this->canParseXml($body)) {
                return 'xml';
            }

            return 'html';
        }

        return 'text';
    }

    private function prettyJson(string $body): string
    {
        $decoded = json_decode($body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return $body;
        }

        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $pretty === false ? $body : $pretty;
    }

    private function canParseXml(string $body): bool
    {
        if (!class_exists(\DOMDocument::class)) {
            return false;
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;

        return @$dom->loadXML($body) === true;
    }

    private function prettyXml(string $body): string
    {
        if (!class_exists(\DOMDocument::class)) {
            return $body;
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        if (@$dom->loadXML($body) !== true) {
            return $body;
        }

        $xml = $dom->saveXML();

        return $xml === false ? $body : $xml;
    }
}
