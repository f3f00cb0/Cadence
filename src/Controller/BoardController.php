<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BoardController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('board/index.html.twig');
    }

    #[Route('/board', name: 'board_legacy', methods: ['GET'])]
    public function legacyBoard(): RedirectResponse
    {
        return $this->redirectToRoute('home', [], 301);
    }
}
