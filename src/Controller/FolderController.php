<?php

namespace App\Controller;

use App\Service\ConfigStore;
use App\Service\ConfigTreeBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class FolderController extends AbstractController
{
    #[Route('/folders', name: 'folders_create', methods: ['POST'])]
    public function create(Request $request, ConfigStore $store, ConfigTreeBuilder $treeBuilder): Response
    {
        $collection = $store->loadCollection();

        $name = trim((string)$request->request->get('name', ''));
        $parentId = trim((string)$request->request->get('parentId', ''));
        if ($parentId === '') {
            $parentId = null;
        }

        if ($name === '') {
            return new Response('Folder name is required.', 400);
        }

        $folders = (array)($collection['folders'] ?? []);
        $folderById = [];
        foreach ($folders as $f) {
            if (is_array($f) && (string)($f['id'] ?? '') !== '') {
                $folderById[(string)$f['id']] = $f;
            }
        }

        if ($parentId !== null && !array_key_exists($parentId, $folderById)) {
            $parentId = null;
        }

        $sort = $this->nextSortForParent($folders, $parentId);

        $folders[] = [
            'id' => 'fld_'.Uuid::v4()->toRfc4122(),
            'name' => $name,
            'parentId' => $parentId,
            'sort' => $sort,
        ];

        $collection['folders'] = array_values($folders);
        $store->saveCollection($collection);

        return $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
            'tree' => $treeBuilder->build($collection),
        ]);
    }

    #[Route('/folders/{id}/rename', name: 'folders_rename', methods: ['POST'])]
    public function rename(string $id, Request $request, ConfigStore $store, ConfigTreeBuilder $treeBuilder): Response
    {
        $collection = $store->loadCollection();
        $name = trim((string)$request->request->get('name', ''));
        if ($name === '') {
            return new Response('Folder name is required.', 400);
        }

        $folders = (array)($collection['folders'] ?? []);
        $found = false;

        foreach ($folders as $i => $f) {
            if (!is_array($f) || (string)($f['id'] ?? '') !== $id) {
                continue;
            }
            $f['name'] = $name;
            $folders[$i] = $f;
            $found = true;
            break;
        }

        if (!$found) {
            return new Response('Folder not found.', 404);
        }

        $collection['folders'] = array_values($folders);
        $store->saveCollection($collection);

        return $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
            'tree' => $treeBuilder->build($collection),
        ]);
    }

    #[Route('/folders/{id}/delete', name: 'folders_delete', methods: ['POST'])]
    public function delete(string $id, ConfigStore $store, ConfigTreeBuilder $treeBuilder): Response
    {
        $collection = $store->loadCollection();

        $folders = (array)($collection['folders'] ?? []);
        $deleted = null;
        foreach ($folders as $f) {
            if (is_array($f) && (string)($f['id'] ?? '') === $id) {
                $deleted = $f;
                break;
            }
        }

        if ($deleted === null) {
            return new Response('Folder not found.', 404);
        }

        $parentId = $deleted['parentId'] ?? null;
        $parentId = is_string($parentId) ? $parentId : null;

        // Remove the folder
        $folders = array_values(array_filter($folders, static fn ($f) => !(is_array($f) && (string)($f['id'] ?? '') === $id)));

        // Move child folders up one level
        foreach ($folders as $i => $f) {
            if (!is_array($f)) {
                continue;
            }
            if ((string)($f['parentId'] ?? '') === $id) {
                $f['parentId'] = $parentId;
                $folders[$i] = $f;
            }
        }

        // Move configs in the deleted folder up one level
        $configs = (array)($collection['configs'] ?? []);
        foreach ($configs as $i => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            if ((string)($cfg['folderId'] ?? '') === $id) {
                $cfg['folderId'] = $parentId;
                $configs[$i] = $cfg;
            }
        }

        $collection['folders'] = array_values($folders);
        $collection['configs'] = array_values($configs);

        // Normalize sibling sort orders for the affected parent.
        $collection = $this->normalizeSorts($collection, $parentId);

        $store->saveCollection($collection);

        return $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
            'tree' => $treeBuilder->build($collection),
        ]);
    }

    #[Route('/folders/tree-update', name: 'folders_tree_update', methods: ['POST'])]
    public function treeUpdate(Request $request, ConfigStore $store, ConfigTreeBuilder $treeBuilder): Response
    {
        $payload = json_decode((string)$request->getContent(), true);
        if (!is_array($payload)) {
            return new Response('Invalid JSON payload.', 400);
        }

        $collection = $store->loadCollection();
        $folders = array_values(array_filter((array)($collection['folders'] ?? []), static fn ($f) => is_array($f)));
        $configs = array_values(array_filter((array)($collection['configs'] ?? []), static fn ($c) => is_array($c)));

        $folderById = [];
        foreach ($folders as $i => $f) {
            $fid = (string)($f['id'] ?? '');
            if ($fid !== '') {
                $folderById[$fid] = $i;
            }
        }

        $configById = [];
        foreach ($configs as $i => $c) {
            $cid = (string)($c['id'] ?? '');
            if ($cid !== '') {
                $configById[$cid] = $i;
            }
        }

        // Apply updates
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string)($row['type'] ?? '');
            $id = (string)($row['id'] ?? '');
            $parentId = $row['parentId'] ?? null;
            $parentId = is_string($parentId) && trim($parentId) !== '' ? trim($parentId) : null;
            $sort = isset($row['sort']) && is_numeric($row['sort']) ? (int)$row['sort'] : 0;

            if ($type === 'folder' && $id !== '' && array_key_exists($id, $folderById)) {
                if ($parentId !== null && !array_key_exists($parentId, $folderById)) {
                    $parentId = null;
                }
                $f = $folders[$folderById[$id]];
                $f['parentId'] = $parentId;
                $f['sort'] = $sort;
                $folders[$folderById[$id]] = $f;
            }

            if ($type === 'config' && $id !== '' && array_key_exists($id, $configById)) {
                if ($parentId !== null && !array_key_exists($parentId, $folderById)) {
                    $parentId = null;
                }
                $c = $configs[$configById[$id]];
                $c['folderId'] = $parentId;
                $c['sort'] = $sort;
                $configs[$configById[$id]] = $c;
            }
        }

        $collection['folders'] = array_values($folders);
        $collection['configs'] = array_values($configs);

        if ($this->hasFolderCycle($collection)) {
            return new Response('Invalid move: folder cycle detected.', 400);
        }

        $store->saveCollection($collection);

        return $this->render('partials/config_list.html.twig', [
            'collection' => $collection,
            'tree' => $treeBuilder->build($collection),
        ]);
    }

    /**
     * @param array<int, mixed> $folders
     */
    private function nextSortForParent(array $folders, ?string $parentId): int
    {
        $max = 0;
        foreach ($folders as $f) {
            if (!is_array($f)) {
                continue;
            }
            $pid = $f['parentId'] ?? null;
            $pid = is_string($pid) ? $pid : null;
            if (($pid ?? null) !== ($parentId ?? null)) {
                continue;
            }
            $s = (int)($f['sort'] ?? 0);
            if ($s > $max) {
                $max = $s;
            }
        }

        return $max + 10;
    }

    /**
     * @param array<string, mixed> $collection
     */
    private function normalizeSorts(array $collection, ?string $parentId): array
    {
        $folders = array_values(array_filter((array)($collection['folders'] ?? []), static fn ($f) => is_array($f)));
        $configs = array_values(array_filter((array)($collection['configs'] ?? []), static fn ($c) => is_array($c)));

        $items = [];
        foreach ($folders as $i => $f) {
            $pid = $f['parentId'] ?? null;
            $pid = is_string($pid) ? $pid : null;
            if (($pid ?? null) === ($parentId ?? null)) {
                $items[] = ['type' => 'folder', 'idx' => $i, 'sort' => (int)($f['sort'] ?? 0)];
            }
        }
        foreach ($configs as $i => $c) {
            $fid = $c['folderId'] ?? null;
            $fid = is_string($fid) ? $fid : null;
            if (($fid ?? null) === ($parentId ?? null)) {
                $items[] = ['type' => 'config', 'idx' => $i, 'sort' => (int)($c['sort'] ?? 0)];
            }
        }

        usort($items, static fn ($a, $b) => (int)$a['sort'] <=> (int)$b['sort']);

        $sort = 10;
        foreach ($items as $it) {
            if ($it['type'] === 'folder') {
                $folders[$it['idx']]['sort'] = $sort;
            } else {
                $configs[$it['idx']]['sort'] = $sort;
            }
            $sort += 10;
        }

        $collection['folders'] = $folders;
        $collection['configs'] = $configs;
        return $collection;
    }

    /**
     * @param array<string, mixed> $collection
     */
    private function hasFolderCycle(array $collection): bool
    {
        $folders = array_values(array_filter((array)($collection['folders'] ?? []), static fn ($f) => is_array($f)));
        $parentById = [];

        foreach ($folders as $f) {
            $id = (string)($f['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $pid = $f['parentId'] ?? null;
            $pid = is_string($pid) && trim($pid) !== '' ? trim($pid) : null;
            $parentById[$id] = $pid;
        }

        foreach ($parentById as $id => $_) {
            $seen = [];
            $cur = $id;
            while (true) {
                $pid = $parentById[$cur] ?? null;
                if ($pid === null) {
                    break;
                }
                if ($pid === $id) {
                    return true;
                }
                if (isset($seen[$pid])) {
                    return true;
                }
                $seen[$pid] = true;
                $cur = $pid;
            }
        }

        return false;
    }
}
