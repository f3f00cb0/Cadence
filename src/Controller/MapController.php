<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MapController extends AbstractController
{
    #[Route('/map', name: 'map', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('map/index.html.twig');
    }
}
