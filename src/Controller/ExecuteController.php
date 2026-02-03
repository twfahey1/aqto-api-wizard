<?php

namespace App\Controller;

use App\Service\ConfigStore;
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
        RequestExecutor $executor,
        ResponseFormatter $formatter,
    ): Response {
        $id = (string)$request->request->get('configId', '');

        if ($id === '') {
            return new Response('Missing configId.', 400);
        }

        $collection = $store->loadCollection();
        $config = null;

        foreach ((array)($collection['configs'] ?? []) as $cfg) {
            if (is_array($cfg) && (string)($cfg['id'] ?? '') === $id) {
                $config = $cfg;
                break;
            }
        }

        if ($config === null) {
            return new Response('Config not found.', 404);
        }

        $result = $executor->execute($config, (array)($collection['authProfiles'] ?? []));

        $formatted = $formatter->format($result['body'], $result['contentType']);

        return $this->render('partials/execute_result.html.twig', [
            'config' => $config,
            'result' => $result,
            'formatted' => $formatted,
        ]);
    }
}
