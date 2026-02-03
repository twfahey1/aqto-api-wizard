<?php

namespace App\Controller;

use App\Service\ConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(ConfigStore $store): Response
    {
        $collection = $store->loadCollection();

        return $this->render('home/index.html.twig', [
            'collection' => $collection,
        ]);
    }
}
