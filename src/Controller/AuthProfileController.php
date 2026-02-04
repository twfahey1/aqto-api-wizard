<?php

namespace App\Controller;

use App\Service\ConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class AuthProfileController extends AbstractController
{
    #[Route('/auth-profiles/new', name: 'auth_profiles_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('partials/auth_profile_new.html.twig', [
            'profile' => [
                'id' => '',
                'name' => '',
                'headers' => [],
                'oauth' => [
                    'tokenUrl' => '',
                    'refreshUrl' => '',
                    'clientId' => '',
                    'clientSecret' => '',
                    'scope' => '',
                    'audience' => '',
                ],
            ],
        ]);
    }

    #[Route('/auth-profiles', name: 'auth_profiles_create', methods: ['POST'])]
    public function create(Request $request, ConfigStore $store): Response
    {
        $name = trim((string)$request->request->get('name', ''));
        if ($name === '') {
            return $this->renderFormError('Name is required.', 'create');
        }

        $headers = $this->parseHeadersFromRequest($request);
        $oauth = $this->parseOAuthFromRequest($request);

        $collection = $store->loadCollection();

        $profile = [
            'id' => 'auth_'.Uuid::v4()->toRfc4122(),
            'name' => $name,
            'headers' => $headers,
            ...($oauth !== null ? ['oauth' => $oauth] : []),
        ];

        $collection['authProfiles'] = array_values(array_merge((array)($collection['authProfiles'] ?? []), [$profile]));
        $store->saveCollection($collection);

        $response = $this->render('partials/auth_profile_list.html.twig', [
            'collection' => $collection,
        ]);

        $response->headers->set('HX-Trigger', json_encode([
            'auth-profile-created' => ['id' => (string)$profile['id'], 'name' => $name],
            'auth-profiles-changed' => ['action' => 'created', 'id' => (string)$profile['id']],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    #[Route('/auth-profiles/{id}/edit', name: 'auth_profiles_edit', methods: ['GET'])]
    public function edit(string $id, ConfigStore $store): Response
    {
        $collection = $store->loadCollection();

        $profile = null;
        foreach ((array)($collection['authProfiles'] ?? []) as $p) {
            if (is_array($p) && (string)($p['id'] ?? '') === $id) {
                $profile = $p;
                break;
            }
        }

        if ($profile === null) {
            return new Response('Auth profile not found.', 404);
        }

        return $this->render('partials/auth_profile_edit.html.twig', [
            'profile' => $profile,
        ]);
    }

    #[Route('/auth-profiles/{id}', name: 'auth_profiles_update', methods: ['POST'])]
    public function update(string $id, Request $request, ConfigStore $store): Response
    {
        $name = trim((string)$request->request->get('name', ''));
        if ($name === '') {
            return $this->renderFormError('Name is required.', 'edit');
        }

        $collection = $store->loadCollection();
        $profiles = (array)($collection['authProfiles'] ?? []);

        $idx = null;
        $existing = null;
        foreach ($profiles as $i => $p) {
            if (is_array($p) && (string)($p['id'] ?? '') === $id) {
                $idx = $i;
                $existing = $p;
                break;
            }
        }

        if ($idx === null || $existing === null) {
            return new Response('Auth profile not found.', 404);
        }

        $headers = $this->parseHeadersFromRequest($request);
        $oauthNew = $this->parseOAuthFromRequest($request);

        $oauthExisting = $existing['oauth'] ?? null;
        if (!is_array($oauthExisting)) {
            $oauthExisting = null;
        }

        $clearToken = (bool)$request->request->get('clearToken', false);

        if ($oauthNew !== null && $oauthExisting !== null) {
            // Preserve token state unless explicitly cleared.
            foreach (['tokenType', 'accessToken', 'refreshToken', 'expiresAt', 'updatedAt'] as $k) {
                if ($clearToken) {
                    unset($oauthNew[$k]);
                    continue;
                }
                if (!array_key_exists($k, $oauthNew) && array_key_exists($k, $oauthExisting)) {
                    $oauthNew[$k] = $oauthExisting[$k];
                }
            }
        }

        $updated = [
            'id' => (string)$existing['id'],
            'name' => $name,
            'headers' => $headers,
        ];

        if ($oauthNew !== null) {
            $updated['oauth'] = $oauthNew;
        }

        $profiles[$idx] = $updated;
        $collection['authProfiles'] = array_values($profiles);
        $store->saveCollection($collection);

        $response = $this->render('partials/auth_profile_list.html.twig', [
            'collection' => $collection,
        ]);

        $response->headers->set('HX-Trigger', json_encode([
            'auth-profile-updated' => ['id' => $id, 'name' => $name],
            'auth-profiles-changed' => ['action' => 'updated', 'id' => $id],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    #[Route('/auth-profiles/{id}/delete', name: 'auth_profiles_delete', methods: ['POST'])]
    public function delete(string $id, ConfigStore $store): Response
    {
        $collection = $store->loadCollection();

        $profiles = (array)($collection['authProfiles'] ?? []);
        $profiles = array_values(array_filter($profiles, static fn ($p) => !(is_array($p) && (string)($p['id'] ?? '') === $id)));
        $collection['authProfiles'] = $profiles;

        // Detach from configs that reference it.
        $configs = (array)($collection['configs'] ?? []);
        foreach ($configs as $i => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $req = (array)($cfg['request'] ?? []);
            if ((string)($req['authProfileId'] ?? '') === $id) {
                $req['authProfileId'] = null;
                $cfg['request'] = $req;
                $configs[$i] = $cfg;
            }
        }
        $collection['configs'] = array_values($configs);

        $store->saveCollection($collection);

        $response = $this->render('partials/auth_profile_list.html.twig', [
            'collection' => $collection,
        ]);

        $response->headers->set('HX-Trigger', json_encode([
            'auth-profile-deleted' => ['id' => $id],
            'auth-profiles-changed' => ['action' => 'deleted', 'id' => $id],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    private function renderFormError(string $message, string $mode): Response
    {
        $response = $this->render('partials/auth_profile_error.html.twig', [
            'message' => $message,
        ]);

        $target = $mode === 'edit' ? '#authProfileEditErrors' : '#authProfileCreateErrors';
        $response->headers->set('HX-Retarget', $target);
        $response->headers->set('HX-Reswap', 'innerHTML');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseHeadersFromRequest(Request $request): array
    {
        $post = $request->request->all();

        $names = $post['headersName'] ?? [];
        $values = $post['headersValue'] ?? [];
        $isSecrets = $post['headersIsSecret'] ?? [];
        $kinds = $post['headersSecretKind'] ?? [];
        $exportPolicies = $post['headersExportPolicy'] ?? [];
        $secretRefs = $post['headersSecretRef'] ?? [];
        $placeholders = $post['headersPlaceholder'] ?? [];

        if (!is_array($names)) {
            $names = [];
        }
        if (!is_array($values)) {
            $values = [];
        }
        if (!is_array($isSecrets)) {
            $isSecrets = [];
        }
        if (!is_array($kinds)) {
            $kinds = [];
        }
        if (!is_array($exportPolicies)) {
            $exportPolicies = [];
        }
        if (!is_array($secretRefs)) {
            $secretRefs = [];
        }
        if (!is_array($placeholders)) {
            $placeholders = [];
        }

        $count = max(count($names), count($values), count($isSecrets), count($kinds), count($exportPolicies), count($secretRefs), count($placeholders));
        $headers = [];

        for ($i = 0; $i < $count; $i++) {
            $name = trim((string)($names[$i] ?? ''));
            if ($name === '') {
                continue;
            }

            $value = (string)($values[$i] ?? '');
            $isSecret = (string)($isSecrets[$i] ?? '0') === '1';
            $kind = (string)($kinds[$i] ?? 'custom');
            $exportPolicy = (string)($exportPolicies[$i] ?? 'prompt');
            $incomingSecretRef = trim((string)($secretRefs[$i] ?? ''));
            $incomingPlaceholder = (string)($placeholders[$i] ?? '');

            $header = [
                'name' => $name,
                'value' => $value,
            ];

            if ($isSecret) {
                $secretRef = $incomingSecretRef !== '' ? $incomingSecretRef : ('secrets.'.Uuid::v4()->toRfc4122());
                $placeholder = trim($incomingPlaceholder) !== '' ? $incomingPlaceholder : sprintf('{{AQTO_SECRET:%s}}', $secretRef);

                $header['isSecret'] = true;
                $header['secretKind'] = in_array($kind, ['bearer', 'apiKey', 'custom'], true) ? $kind : 'custom';
                $header['secretRef'] = $secretRef;
                $header['exportPolicy'] = in_array($exportPolicy, ['prompt', 'never', 'always'], true) ? $exportPolicy : 'prompt';
                $header['placeholder'] = $placeholder;

                if (trim($value) === '') {
                    $header['value'] = $placeholder;
                }
            }

            $headers[] = $header;
        }

        return $headers;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseOAuthFromRequest(Request $request): ?array
    {
        $tokenUrl = trim((string)$request->request->get('oauthTokenUrl', ''));
        $refreshUrl = trim((string)$request->request->get('oauthRefreshUrl', ''));
        $clientId = trim((string)$request->request->get('oauthClientId', ''));
        $clientSecret = (string)$request->request->get('oauthClientSecret', '');
        $clientSecret = trim($clientSecret);
        $scope = trim((string)$request->request->get('oauthScope', ''));
        $audience = trim((string)$request->request->get('oauthAudience', ''));

        if ($tokenUrl === '' && $refreshUrl === '' && $clientId === '' && $clientSecret === '' && $scope === '' && $audience === '') {
            return null;
        }

        $oauth = [];
        if ($tokenUrl !== '') {
            $oauth['tokenUrl'] = $tokenUrl;
        }
        if ($refreshUrl !== '') {
            $oauth['refreshUrl'] = $refreshUrl;
        }
        if ($clientId !== '') {
            $oauth['clientId'] = $clientId;
        }
        if ($clientSecret !== '') {
            $oauth['clientSecret'] = $clientSecret;
        }
        if ($scope !== '') {
            $oauth['scope'] = $scope;
        }
        if ($audience !== '') {
            $oauth['audience'] = $audience;
        }

        return $oauth;
    }
}
