<?php

namespace App\Controller;

use App\ConfigSchema\ConfigSchemaException;
use App\Service\ConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class ImportExportController extends AbstractController
{
    #[Route('/configs/export/options', name: 'configs_export_options', methods: ['GET'])]
    public function exportOptions(ConfigStore $store): Response
    {
        $collection = $store->loadCollection();

        $secrets = [];

        foreach (($collection['authProfiles'] ?? []) as $profileIndex => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            // OAuth secrets stored on auth profiles (v5+).
            $oauth = $profile['oauth'] ?? null;
            if (is_array($oauth)) {
                foreach (['clientSecret' => 'OAuth client_secret', 'refreshToken' => 'OAuth refresh_token', 'accessToken' => 'OAuth access_token', 'password' => 'OAuth password'] as $field => $label) {
                    $value = (string)($oauth[$field] ?? '');
                    if ($value === '') {
                        continue;
                    }

                    $secretRef = sprintf('auth.%s.oauth.%s', $profile['id'] ?? $profileIndex, $field);
                    $secrets[] = [
                        'configName' => 'Auth: '.($profile['name'] ?? '(unnamed)'),
                        'headerName' => $label,
                        'secretKind' => 'custom',
                        'secretRef' => $secretRef,
                        'hasValue' => true,
                    ];
                }
            }

            foreach (($profile['headers'] ?? []) as $headerIndex => $header) {
                if (!is_array($header) || !($header['isSecret'] ?? false)) {
                    continue;
                }

                $secretRef = (string)($header['secretRef'] ?? '');
                if ($secretRef === '') {
                    $secretRef = sprintf('auth.%s.header.%s', $profile['id'] ?? $profileIndex, $header['name'] ?? $headerIndex);
                }

                $secrets[] = [
                    'configName' => 'Auth: '.($profile['name'] ?? '(unnamed)'),
                    'headerName' => $header['name'] ?? '(header)',
                    'secretKind' => $header['secretKind'] ?? 'custom',
                    'secretRef' => $secretRef,
                    'hasValue' => (string)($header['value'] ?? '') !== '',
                ];
            }
        }

        foreach (($collection['configs'] ?? []) as $configIndex => $config) {
            foreach (($config['request']['headers'] ?? []) as $headerIndex => $header) {
                if (!is_array($header)) {
                    continue;
                }

                if (!($header['isSecret'] ?? false)) {
                    continue;
                }

                $secretRef = (string)($header['secretRef'] ?? '');
                if ($secretRef === '') {
                    // Deterministic fallback; keeps prompt stable.
                    $secretRef = sprintf('cfg.%s.header.%s', $config['id'] ?? $configIndex, $header['name'] ?? $headerIndex);
                }

                $secrets[] = [
                    'configName' => $config['name'] ?? '(unnamed)',
                    'headerName' => $header['name'] ?? '(header)',
                    'secretKind' => $header['secretKind'] ?? 'custom',
                    'secretRef' => $secretRef,
                    'hasValue' => (string)($header['value'] ?? '') !== '',
                ];
            }
        }

        return $this->render('partials/export_options.html.twig', [
            'secrets' => $secrets,
        ]);
    }

    #[Route('/configs/export/download', name: 'configs_export_download', methods: ['POST'])]
    public function exportDownload(Request $request, ConfigStore $store): Response
    {
        $collection = $store->loadCollection();

        /** @var array<int, string> $includeSecretRefs */
        $includeSecretRefs = $request->request->all('includeSecretRefs');
        $includeSecretRefs = array_values(array_filter(array_map('strval', $includeSecretRefs)));

        $collection = $this->applyExportSecretsPolicy($collection, $includeSecretRefs);
        $collection['meta'] = array_merge((array)($collection['meta'] ?? []), [
            'exportedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'secretsIncluded' => count($includeSecretRefs) > 0,
        ]);

        $json = json_encode(
            $collection,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            return new Response('Failed to encode export JSON: '.json_last_error_msg(), 500);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'aqto_export_');
        if ($tmp === false) {
            return new Response('Unable to create export file.', 500);
        }

        file_put_contents($tmp, $json."\n");

        $date = (new \DateTimeImmutable())->format('Ymd-His');
        $filename = sprintf('aqto-configs-%s%s.json', $date, count($includeSecretRefs) > 0 ? '-with-secrets' : '');

        $response = new BinaryFileResponse($tmp);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-store');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

        // Remove the temp file after it's sent.
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[Route('/configs/import', name: 'configs_import', methods: ['POST'])]
    public function import(Request $request, ConfigStore $store): Response
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if ($file === null) {
            return new Response('No file uploaded.', 400);
        }

        if (!$file->isValid()) {
            return new Response('Upload failed.', 400);
        }

        $raw = file_get_contents($file->getPathname());
        if ($raw === false || trim($raw) === '') {
            return new Response('Uploaded file is empty.', 400);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return new Response('Uploaded file is not valid JSON: '.json_last_error_msg(), 400);
        }

        try {
            // Load triggers migrate + validate + normalize.
            // Merge strategy: append imported configs; on id collision, generate a new id.
            $existing = $store->loadCollection();

            $existingConfigs = (array)($existing['configs'] ?? []);
            $existingIds = [];
            foreach ($existingConfigs as $cfg) {
                if (is_array($cfg) && isset($cfg['id'])) {
                    $existingIds[(string)$cfg['id']] = true;
                }
            }

            $incomingConfigs = (array)($decoded['configs'] ?? []);
            $importedCount = 0;

            foreach ($incomingConfigs as $cfg) {
                if (!is_array($cfg)) {
                    continue;
                }

                $id = (string)($cfg['id'] ?? '');
                if ($id === '' || isset($existingIds[$id])) {
                    $cfg['id'] = 'imported_'.bin2hex(random_bytes(8));
                }

                $existingConfigs[] = $cfg;
                $existingIds[(string)$cfg['id']] = true;
                $importedCount++;
            }

            $existing['configs'] = $existingConfigs;
            $store->saveCollection($existing);

            return $this->render('partials/import_result.html.twig', [
                'importedCount' => $importedCount,
            ]);
        } catch (ConfigSchemaException $e) {
            return $this->render('partials/import_result.html.twig', [
                'importedCount' => 0,
                'errors' => $e->getErrors(),
                'errorMessage' => $e->getMessage(),
            ], new Response('', 422));
        }
    }

    /**
     * @param array<string, mixed> $collection
     * @param array<int, string> $includeSecretRefs
     * @return array<string, mixed>
     */
    private function applyExportSecretsPolicy(array $collection, array $includeSecretRefs): array
    {
        $include = array_fill_keys($includeSecretRefs, true);

        foreach (($collection['authProfiles'] ?? []) as $profileIndex => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            // OAuth secrets stored on auth profiles (v5+).
            $oauth = $profile['oauth'] ?? null;
            if (is_array($oauth)) {
                foreach (['clientSecret', 'refreshToken', 'accessToken', 'password'] as $field) {
                    $value = (string)($oauth[$field] ?? '');
                    if ($value === '') {
                        continue;
                    }

                    $secretRef = sprintf('auth.%s.oauth.%s', $profile['id'] ?? $profileIndex, $field);
                    if (!isset($include[$secretRef])) {
                        $oauth[$field] = sprintf('{{AQTO_SECRET:%s}}', $secretRef);
                    }
                }

                $collection['authProfiles'][$profileIndex]['oauth'] = $oauth;
            }

            foreach (($profile['headers'] ?? []) as $headerIndex => $header) {
                if (!is_array($header) || !($header['isSecret'] ?? false)) {
                    continue;
                }

                $secretRef = (string)($header['secretRef'] ?? '');
                if ($secretRef === '') {
                    $secretRef = sprintf('auth.%s.header.%s', $profile['id'] ?? $profileIndex, $header['name'] ?? $headerIndex);
                }

                if (!isset($include[$secretRef])) {
                    $placeholder = (string)($header['placeholder'] ?? sprintf('{{AQTO_SECRET:%s}}', $secretRef));
                    $header['value'] = $placeholder;
                }

                $collection['authProfiles'][$profileIndex]['headers'][$headerIndex] = $header;
            }
        }

        foreach (($collection['configs'] ?? []) as $configIndex => $config) {
            if (!is_array($config)) {
                continue;
            }

            foreach (($config['request']['headers'] ?? []) as $headerIndex => $header) {
                if (!is_array($header)) {
                    continue;
                }

                if (!($header['isSecret'] ?? false)) {
                    continue;
                }

                $secretRef = (string)($header['secretRef'] ?? '');
                if ($secretRef === '') {
                    $secretRef = sprintf('cfg.%s.header.%s', $config['id'] ?? $configIndex, $header['name'] ?? $headerIndex);
                }

                if (!isset($include[$secretRef])) {
                    $placeholder = (string)($header['placeholder'] ?? sprintf('{{AQTO_SECRET:%s}}', $secretRef));
                    $header['value'] = $placeholder;
                }

                $collection['configs'][$configIndex]['request']['headers'][$headerIndex] = $header;
            }
        }

        return $collection;
    }
}
