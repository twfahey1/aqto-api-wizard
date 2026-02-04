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

        if ($version === 2 && SchemaVersion::LATEST >= 3) {
            $collection = $this->migrateV2ToV3($collection);
            $version = 3;
        }

        if ($version === 3 && SchemaVersion::LATEST >= 4) {
            $collection = $this->migrateV3ToV4($collection);
            $version = 4;
        }

        if ($version === 4 && SchemaVersion::LATEST >= 5) {
            $collection = $this->migrateV4ToV5($collection);
            $version = 5;
        }

        if ($version === 5 && SchemaVersion::LATEST >= 6) {
            $collection = $this->migrateV5ToV6($collection);
            $version = 6;
        }

        if ($version === 6 && SchemaVersion::LATEST >= 7) {
            $collection = $this->migrateV6ToV7($collection);
            $version = 7;
        }

        return $collection;
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    private function migrateV6ToV7(array $collection): array
    {
        $collection['schemaVersion'] = 7;

        // v7 adds optional authProfiles[].params (query params applied to requests using this auth profile).
        $profiles = (array)($collection['authProfiles'] ?? []);
        foreach ($profiles as $i => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            if (!array_key_exists('params', $profile) || !is_array($profile['params'])) {
                $profile['params'] = [];
            }

            $profiles[$i] = $profile;
        }
        $collection['authProfiles'] = $profiles;

        return $collection;
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    private function migrateV5ToV6(array $collection): array
    {
        $collection['schemaVersion'] = 6;

        if (!isset($collection['folders']) || !is_array($collection['folders'])) {
            $collection['folders'] = [];
        }

        // v6 adds folders + per-item ordering.
        $configs = (array)($collection['configs'] ?? []);
        $sort = 10;
        foreach ($configs as $i => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }

            if (!array_key_exists('folderId', $cfg)) {
                $cfg['folderId'] = null;
            }
            if (!array_key_exists('sort', $cfg) || !is_int($cfg['sort'])) {
                $cfg['sort'] = $sort;
                $sort += 10;
            }

            $configs[$i] = $cfg;
        }
        $collection['configs'] = $configs;

        // Ensure folders have required fields (best-effort).
        $folders = (array)($collection['folders'] ?? []);
        $folderSort = 10;
        foreach ($folders as $i => $folder) {
            if (!is_array($folder)) {
                continue;
            }
            if (!array_key_exists('parentId', $folder)) {
                $folder['parentId'] = null;
            }
            if (!array_key_exists('sort', $folder) || !is_int($folder['sort'])) {
                $folder['sort'] = $folderSort;
                $folderSort += 10;
            }
            $folders[$i] = $folder;
        }
        $collection['folders'] = $folders;

        return $collection;
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    private function migrateV4ToV5(array $collection): array
    {
        $collection['schemaVersion'] = 5;

        // v5 expands authProfiles[].oauth to optionally include credentials and token state.
        // Existing v4 collections are already compatible.

        return $collection;
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    private function migrateV3ToV4(array $collection): array
    {
        $collection['schemaVersion'] = 4;

        // v4 relaxes urls.{dev,live} to be optional (at least one required).
        // Existing v3 collections are already compatible.

        $profiles = (array)($collection['authProfiles'] ?? []);
        foreach ($profiles as $i => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            if (array_key_exists('oauth', $profile) && $profile['oauth'] === null) {
                unset($profile['oauth']);
            }

            $profiles[$i] = $profile;
        }
        $collection['authProfiles'] = $profiles;

        return $collection;
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    private function migrateV2ToV3(array $collection): array
    {
        $collection['schemaVersion'] = 3;

        $profiles = (array)($collection['authProfiles'] ?? []);
        foreach ($profiles as $i => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            // No-op migration; oauth metadata is optional.
            $profiles[$i] = $profile;
        }

        $collection['authProfiles'] = $profiles;

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
