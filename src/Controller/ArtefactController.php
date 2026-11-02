<?php

declare(strict_types=1);

namespace App\Controller;

use App\Artefact\ArtefactRules;
use App\Artefact\ImageStore;
use App\Artefact\PublicArtefact;
use App\Repository\ArtefactRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ArtefactController extends AbstractController
{
    public function __construct(
        private ArtefactRepository $artefacts,
        private ImageStore $images,
    ) {
    }

    #[Route('/p/{slug}', name: 'artefact_public', methods: ['GET'], requirements: ['slug' => '[A-Za-z0-9-]{1,64}'])]
    public function show(string $slug): Response
    {
        if (!ArtefactRules::isValidToken($slug)) {
            return $this->missing();
        }

        $artefact = $this->artefacts->findByToken($slug);
        if ($artefact === null) {
            return $this->missing();
        }

        $response = $this->renderRecord(PublicArtefact::fromEntity($artefact, $this->images));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function renderRecord(PublicArtefact $artefact): Response
    {
        return $this->render('artefact/record.html.twig', ['a' => $artefact]);
    }

    private function missing(): Response
    {
        $response = new Response($this->renderView('artefact/missing.html.twig'), Response::HTTP_NOT_FOUND);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
