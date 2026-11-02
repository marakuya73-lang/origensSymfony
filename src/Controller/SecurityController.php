<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/auth', name: 'auth_redirect')]
    public function auth(): Response
    {
        return $this->redirectToRoute('admin_login');
    }

    #[Route('/admin', name: 'admin_entry', methods: ['GET'])]
    public function admin(): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_records');
        }

        return $this->redirectToRoute('admin_login');
    }

    #[Route('/', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_records');
        }

        $error = $authenticationUtils->getLastAuthenticationError()
            ? 'Não foi possível entrar. Verifique sua senha e tente novamente.'
            : '';

        $response = $this->render('admin/login.html.twig', ['error' => $error]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
