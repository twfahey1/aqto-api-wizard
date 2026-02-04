<?php

namespace App\Service;

final class ConfigTreeBuilder
{
    /**
     * @param array<string, mixed> $collection
     * @return array<int, array<string, mixed>>
     */
    public function build(array $collection): array
    {
        $folders = array_values(array_filter((array)($collection['folders'] ?? []), static fn ($f) => is_array($f)));
        $configs = array_values(array_filter((array)($collection['configs'] ?? []), static fn ($c) => is_array($c)));

        $folderById = [];
        foreach ($folders as $folder) {
            $id = (string)($folder['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $folderById[$id] = $folder;
        }

        $foldersByParent = [];
        foreach ($folderById as $id => $folder) {
            $parentId = $folder['parentId'] ?? null;
            $parentId = is_string($parentId) ? $parentId : null;
            if ($parentId !== null && !array_key_exists($parentId, $folderById)) {
                $parentId = null;
            }
            $key = $parentId ?? '';
            $foldersByParent[$key] = $foldersByParent[$key] ?? [];
            $foldersByParent[$key][] = $folder;
        }

        $configsByFolder = [];
        foreach ($configs as $cfg) {
            $folderId = $cfg['folderId'] ?? null;
            $folderId = is_string($folderId) ? $folderId : null;
            if ($folderId !== null && !array_key_exists($folderId, $folderById)) {
                $folderId = null;
            }
            $key = $folderId ?? '';
            $configsByFolder[$key] = $configsByFolder[$key] ?? [];
            $configsByFolder[$key][] = $cfg;
        }

        $buildChildren = function (?string $parentId, array $visited) use (&$buildChildren, $foldersByParent, $configsByFolder): array {
            $key = $parentId ?? '';

            $childFolders = $foldersByParent[$key] ?? [];
            usort($childFolders, static function ($a, $b): int {
                $sa = (int)($a['sort'] ?? 0);
                $sb = (int)($b['sort'] ?? 0);
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }
                return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            });

            $childConfigs = $configsByFolder[$key] ?? [];
            usort($childConfigs, static function ($a, $b): int {
                $sa = (int)($a['sort'] ?? 0);
                $sb = (int)($b['sort'] ?? 0);
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }
                return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            });

            $items = [];

            foreach ($childFolders as $folder) {
                $id = (string)($folder['id'] ?? '');
                if ($id === '' || in_array($id, $visited, true)) {
                    continue;
                }

                $items[] = [
                    'type' => 'folder',
                    'id' => $id,
                    'name' => (string)($folder['name'] ?? ''),
                    'sort' => (int)($folder['sort'] ?? 0),
                    'parentId' => $parentId,
                    'children' => $buildChildren($id, array_merge($visited, [$id])),
                ];
            }

            foreach ($childConfigs as $cfg) {
                $items[] = [
                    'type' => 'config',
                    'id' => (string)($cfg['id'] ?? ''),
                    'sort' => (int)($cfg['sort'] ?? 0),
                    'config' => $cfg,
                ];
            }

            usort($items, static function ($a, $b): int {
                $sa = (int)($a['sort'] ?? 0);
                $sb = (int)($b['sort'] ?? 0);
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }

                // folders before configs for equal sort for stability
                $ta = (string)($a['type'] ?? '');
                $tb = (string)($b['type'] ?? '');
                if ($ta !== $tb) {
                    return $ta === 'folder' ? -1 : 1;
                }

                $na = $ta === 'folder' ? (string)($a['name'] ?? '') : (string)($a['config']['name'] ?? '');
                $nb = $tb === 'folder' ? (string)($b['name'] ?? '') : (string)($b['config']['name'] ?? '');
                return strcasecmp($na, $nb);
            });

            return $items;
        };

        return $buildChildren(null, []);
    }
}
