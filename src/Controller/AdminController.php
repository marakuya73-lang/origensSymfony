<?php

declare(strict_types=1);

namespace App\Controller;

use App\Artefact\ArtefactDraft;
use App\Artefact\ArtefactRegistrar;
use App\Artefact\ArtefactRules;
use App\Artefact\AudioClip;
use App\Artefact\GeminiProfileGenerator;
use App\Artefact\ImageStore;
use App\Artefact\InvalidArtefact;
use App\Artefact\ProfileGenerationFailed;
use App\Artefact\SellerTable;
use App\Artefact\SubmissionParser;
use App\Artefact\TabularFile;
use App\Entity\Artefact;
use App\Repository\ArtefactRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    public function __construct(
        private ArtefactRepository $artefacts,
        private ArtefactRegistrar $registrar,
        private SubmissionParser $parser,
        private ImageStore $images,
        private GeminiProfileGenerator $profiles,
        private SellerTable $sheet,
        private TabularFile $files,
    ) {
    }

    #[Route('/admin/records', name: 'admin_records', methods: ['GET'])]
    public function records(Request $request): Response
    {
        return $this->listing($request);
    }

    #[Route('/admin/records/profile', name: 'admin_artefact_profile', methods: ['POST'])]
    public function profile(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Não foi possível gerar o perfil. Tente novamente.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $path = $request->request->getString('image');
            $notes = $request->request->getString('notes');
            $image = $this->images->read($path);
            $mime = $this->images->mimeType($path);
            $audio = AudioClip::read($request->files->get('audio'));
        } catch (InvalidArtefact $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (BadRequestException) {
            return $this->json(['ok' => false, 'error' => 'Não foi possível gerar o perfil. Tente novamente.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($notes) > 4000) {
            return $this->json(['ok' => false, 'error' => 'As notas podem ter até 4000 caracteres.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $audioBytes = null;
        $audioMime = null;
        if ($audio !== null) {
            [$audioBytes, $audioMime] = $audio;
        }

        try {
            $profile = $this->profiles->suggest($image, $mime, $audioBytes, $audioMime, $notes);
        } catch (ProfileGenerationFailed $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], $exception->status());
        }

        return $this->json(['ok' => true, 'profile' => $profile->toArray()]);
    }

    #[Route('/admin/records/export', name: 'admin_artefact_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('export', $request->request->getString('_token'))) {
            return $this->redirectToRoute('admin_records');
        }

        try {
            $ids = $request->request->all('ids');
        } catch (BadRequestException) {
            $ids = [];
        }

        $format = $request->request->getString('format');
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            return $this->listing($request, 'Escolha CSV ou XLSX para exportar.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $selected = $this->artefacts->findSelected($ids);
        if ($selected === []) {
            return $this->listing($request, 'Selecione ao menos uma peça para exportar.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $table = $this->sheet->build($selected, $request->getSchemeAndHttpHost().$request->getBasePath());
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        try {
            if ($format === 'xlsx') {
                $body = $this->files->xlsx($table['headers'], $table['rows']);
                $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                $filename = 'origens-artefactos-'.$day.'.xlsx';
            } else {
                $body = $this->files->csv($table['headers'], $table['rows']);
                $type = 'text/csv; charset=UTF-8';
                $filename = 'origens-artefactos-'.$day.'.csv';
            }
        } catch (\RuntimeException) {
            return $this->listing($request, 'Não foi possível gerar a planilha. Tente o CSV.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = new Response($body);
        $response->headers->set('Content-Type', $type);
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    #[Route('/admin/records/new', name: 'admin_artefact_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->save($request, null);
    }

    #[Route('/admin/records/{id}/edit', name: 'admin_artefact_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function edit(Request $request, string $id): Response
    {
        return $this->save($request, $this->findArtefact($id));
    }

    #[Route('/admin/records/{id}/delete', name: 'admin_artefact_delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('delete', $request->request->getString('_token'))) {
            return $this->redirectToRoute('admin_records');
        }

        $artefact = $this->findArtefact($id);
        $photos = $artefact->getImages();
        $this->registrar->remove($artefact);
        foreach ($photos as $path) {
            $this->images->delete($path);
        }

        return $this->redirectToRoute('admin_records');
    }

    #[Route('/admin/records/images', name: 'admin_artefact_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            return $this->json(['error' => 'Não foi possível enviar a foto. Tente novamente.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'Use fotos JPG, PNG ou WebP de até 10 MB.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $path = $this->images->store($file);
        } catch (InvalidArtefact $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'path' => $path,
            'url' => $request->getBasePath().'/'.$this->images->webPath($path),
        ]);
    }

    #[Route('/admin/records/saved', name: 'admin_artefact_saved', methods: ['GET'])]
    public function saved(Request $request): Response
    {
        $saved = $request->getSession()->get('artefact_saved');
        if (!is_array($saved) || !isset($saved['code'], $saved['url']) || !is_string($saved['code']) || !is_string($saved['url'])) {
            return $this->redirectToRoute('admin_records');
        }

        return $this->admin('admin/saved.html.twig', ['saved' => $saved]);
    }

    #[Route('/admin/records/saved/close', name: 'admin_artefact_close', methods: ['POST'])]
    public function closeSaved(Request $request): Response
    {
        if ($this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            $request->getSession()->remove('artefact_saved');
        }

        return $this->redirectToRoute('admin_records');
    }

    private function save(Request $request, ?Artefact $current): Response
    {
        $autosave = $request->headers->get('X-Origens-Autosave') === '1';

        if ($request->isMethod('POST') && !$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            $submission = $this->parser->parse($request, $current, false);

            return $this->saveFailed($autosave, $current, $submission->draft, 'Não foi possível salvar. Tente novamente.');
        }

        $submission = $this->parser->parse($request, $current, true);
        if (!$request->isMethod('POST')) {
            return $this->editor($current, $submission->draft, '');
        }

        if (!$submission->ok() || $submission->data === null) {
            return $this->saveFailed($autosave, $current, $submission->draft, $submission->error ?? 'Não foi possível salvar. Tente novamente.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            if ($current instanceof Artefact) {
                foreach ($this->registrar->update($current, $submission->data) as $path) {
                    $this->images->delete($path);
                }
                $artefact = $current;
            } else {
                $artefact = $this->registrar->create($submission->data);
            }
        } catch (UniqueConstraintViolationException|InvalidArtefact) {
            return $this->saveFailed($autosave, $current, $submission->draft, 'Não foi possível salvar a peça. Nenhum código existente foi alterado.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($autosave) {
            return $this->json([
                'ok' => true,
                'id' => $artefact->getId(),
                'code' => $artefact->getCode(),
                'editUrl' => $this->generateUrl('admin_artefact_edit', ['id' => $artefact->getId()]),
            ]);
        }

        $request->getSession()->set('artefact_saved', [
            'code' => $artefact->getCode(),
            'url' => $request->getSchemeAndHttpHost().ArtefactRules::publicPath($artefact->getPublicToken()),
        ]);

        return $this->redirectToRoute('admin_artefact_saved');
    }

    private function saveFailed(bool $autosave, ?Artefact $current, ArtefactDraft $draft, string $error, int $status = 200): Response
    {
        if ($autosave) {
            return $this->json(['ok' => false, 'error' => $error], $status === 200 ? Response::HTTP_UNPROCESSABLE_ENTITY : $status);
        }

        return $this->editor($current, $draft, $error, $status);
    }

    private function editor(?Artefact $current, ArtefactDraft $draft, string $error, int $status = 200): Response
    {
        return $this->admin('admin/editor.html.twig', [
            'draft' => $draft,
            'error' => $error,
            'action' => $current instanceof Artefact
                ? $this->generateUrl('admin_artefact_edit', ['id' => $current->getId()])
                : $this->generateUrl('admin_artefact_new'),
            'uploadUrl' => $this->generateUrl('admin_artefact_upload'),
            'profileUrl' => $this->generateUrl('admin_artefact_profile'),
        ], $status);
    }

    private function listing(Request $request, string $exportError = '', int $status = 200): Response
    {
        $rows = [];
        foreach ($this->artefacts->recent() as $artefact) {
            $photos = $artefact->getImages();
            $first = $photos[0] ?? null;
            $token = $artefact->getPublicToken();
            $rows[] = [
                'id' => $artefact->getId(),
                'code' => $artefact->getCode(),
                'title' => $artefact->getTitle(),
                'createdOn' => $artefact->createdOn(),
                'image' => is_string($first) && ArtefactRules::isValidImagePath($first) ? $this->images->webPath($first) : null,
                'url' => ArtefactRules::isValidToken($token)
                    ? $request->getSchemeAndHttpHost().ArtefactRules::publicPath($token)
                    : '',
                'path' => ArtefactRules::isValidToken($token) ? ArtefactRules::publicPath($token) : '',
            ];
        }

        return $this->admin('admin/records.html.twig', [
            'rows' => $rows,
            'exportError' => $exportError,
        ], $status);
    }

    private function findArtefact(string $id): Artefact
    {
        $artefact = $this->artefacts->find($id);
        if (!$artefact instanceof Artefact) {
            throw $this->createNotFoundException();
        }

        return $artefact;
    }

    /** @param array<string, mixed> $parameters */
    private function admin(string $view, array $parameters, int $status = 200): Response
    {
        $response = $this->render($view, $parameters);
        $response->setStatusCode($status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
