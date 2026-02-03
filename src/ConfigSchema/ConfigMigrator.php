<?php

namespace App\ConfigSchema;

final class ConfigMigrator
{
    /**
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    public function migrateToLatest(array $collection): array
    {
        $version = (int)($collection['schemaVersion'] ?? 0);

        if ($version === 0) {
            // Treat unknown/legacy as v1 candidate; validator will enforce required fields.
            $collection['schemaVersion'] = SchemaVersion::LATEST;
            return $collection;
        }

        if ($version > SchemaVersion::LATEST) {
            throw new ConfigSchemaException(
                sprintf('Config schemaVersion %d is newer than supported %d.', $version, SchemaVersion::LATEST)
            );
        }

        if ($version === 1 && SchemaVersion::LATEST >= 2) {
            $collection = $this->migrateV1ToV2($collection);
            $version = 2;
        }

        return $collection;
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    private function migrateV1ToV2(array $collection): array
    {
        $collection['schemaVersion'] = 2;

        if (!isset($collection['authProfiles']) || !is_array($collection['authProfiles'])) {
            $collection['authProfiles'] = [];
        }

        $configs = (array)($collection['configs'] ?? []);

        foreach ($configs as $i => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }

            $request = (array)($cfg['request'] ?? []);
            $url = (string)($request['url'] ?? '');

            if (!isset($request['urls']) || !is_array($request['urls'])) {
                $request['urls'] = [
                    'dev' => $url !== '' ? $url : 'https://example.com',
                    'live' => $url !== '' ? $url : 'https://example.com',
                ];
            }

            if (!isset($request['activeEnv'])) {
                $request['activeEnv'] = 'dev';
            }

            if (!array_key_exists('authProfileId', $request)) {
                $request['authProfileId'] = null;
            }

            // Body: v1 had {mode: none|raw, contentType?, content?}. Keep raw mapping.
            if (isset($request['body']) && is_array($request['body'])) {
                $body = (array)$request['body'];
                $mode = (string)($body['mode'] ?? 'none');
                if ($mode === 'raw') {
                    // ok
                } else {
                    $body['mode'] = 'none';
                }
                $request['body'] = $body;
            } else {
                $request['body'] = ['mode' => 'none'];
            }

            unset($request['url']);
            $cfg['request'] = $request;
            $configs[$i] = $cfg;
        }

        $collection['configs'] = $configs;

        return $collection;
    }
}
