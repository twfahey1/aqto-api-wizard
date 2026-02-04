<?php

namespace App\Controller;

use App\Service\ConfigStore;
use App\Service\ConfigTreeBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(ConfigStore $store, ConfigTreeBuilder $treeBuilder): Response
    {
        $collection = $store->loadCollection();

        return $this->render('home/index.html.twig', [
            'collection' => $collection,
            'tree' => $treeBuilder->build($collection),
        ]);
    }
}
