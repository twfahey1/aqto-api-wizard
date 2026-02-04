<?php

namespace App\Controller;

use App\Service\ConfigStore;
use App\Service\OAuthTokenManager;
use App\Service\RequestExecutor;
use App\Service\ResponseFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ExecuteController extends AbstractController
{
    #[Route('/execute', name: 'execute', methods: ['POST'])]
    public function execute(
        Request $request,
        ConfigStore $store,
        OAuthTokenManager $oauthTokenManager,
        RequestExecutor $executor,
        ResponseFormatter $formatter,
    ): Response {
        $id = (string)$request->request->get('configId', '');

        if ($id === '') {
            return new Response('Missing configId.', 400);
        }

        $prepared = $oauthTokenManager->prepareForExecute($id);
        if ($prepared['error'] !== null) {
            return $this->render('partials/execute_result.html.twig', [
                'config' => $prepared['config'],
                'result' => [
                    'status' => null,
                    'headers' => [],
                    'contentType' => null,
                    'body' => '',
                    'error' => $prepared['error'],
                ],
                'formatted' => ['pretty' => '', 'detectedType' => 'none'],
            ]);
        }

        $collection = $prepared['collection'];
        $config = $prepared['config'];

        $result = $executor->execute($config, (array)($collection['authProfiles'] ?? []));

        $formatted = $formatter->format($result['body'], $result['contentType']);

        return $this->render('partials/execute_result.html.twig', [
            'config' => $config,
            'result' => $result,
            'formatted' => $formatted,
        ]);
    }
}
