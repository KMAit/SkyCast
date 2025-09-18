<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Home pages for SkyCast.
 */
final class HomeController extends AbstractController
{
    /**
     * Display the landing page.
     */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'title' => 'SkyCast - Votre météo simplifiée',
            'app_name' => 'SkyCast',
        ]);
    }
}
