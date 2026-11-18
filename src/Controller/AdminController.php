<?php

declare(strict_types=1);

namespace App\Controller;

use App\Artefact\ArchiveSnapshot;
use App\Artefact\ArtefactDraft;
use App\Artefact\ArtefactRegistrar;
use App\Artefact\ArtefactRules;
use App\Artefact\AudioClip;
use App\Artefact\GeminiProfileGenerator;
use App\Artefact\ImageStore;
use App\Artefact\InvalidArtefact;
use App\Artefact\ProfileExchange;
use App\Artefact\ProfileGenerationFailed;
use App\Artefact\ProfileJobRunner;
use App\Entity\ProfileJob;
use App\Repository\ProfileJobRepository;
use App\Artefact\SalePrice;
use App\Artefact\SellerTable;
use App\Artefact\SubmissionParser;
use App\Artefact\TabularFile;
use App\Entity\Artefact;
use App\Repository\ArtefactRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
        private ArchiveSnapshot $snapshots,
        private ProfileJobRepository $jobs,
        private ProfileJobRunner $jobRunner,
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
            $answer = $request->request->getString('answer');
            if (!$this->images->exists($path)) {
                throw new InvalidArtefact('Envie pelo menos uma foto da peça antes de gerar o perfil.');
            }
            $audio = AudioClip::read($request->files->get('audio'));
            $history = ProfileExchange::history($request->request->getString('history'));
            $form = ProfileExchange::form($request->request->getString('form'));
            $touched = ProfileExchange::touched($request->request->getString('touched'));
        } catch (InvalidArtefact $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (BadRequestException) {
            return $this->json(['ok' => false, 'error' => 'Não foi possível gerar o perfil. Tente novamente.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($notes) > 4000) {
            return $this->json(['ok' => false, 'error' => 'As notas podem ter até 4000 caracteres.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($answer) > 2000) {
            return $this->json(['ok' => false, 'error' => 'A resposta pode ter até 2000 caracteres.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($history !== [] && trim($answer) === '' && $audio === null) {
            return $this->json(['ok' => false, 'error' => ProfileExchange::ANSWER_REQUIRED], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job = new ProfileJob($path);
        $linked = $this->artefacts->find($request->request->getString('artefact'));
        if ($linked instanceof Artefact) {
            $job->setArtefactId($linked->getId());
            $linked->setAiStatus('queued');
            if ($notes !== '') {
                $linked->setNotes($notes);
            }
        }
        $job->setNotes($notes);
        $job->setAnswer($answer);
        $job->setHistory($request->request->getString('history') !== '' ? $request->request->getString('history') : '[]');
        $job->setForm($request->request->getString('form') !== '' ? $request->request->getString('form') : '{}');
        $job->setTouched($request->request->getString('touched') !== '' ? $request->request->getString('touched') : '[]');
        if ($audio !== null) {
            [$bytes, $audioMime] = $audio;
            try {
                $job->setAudioPath($this->jobRunner->storeAudio($bytes, $audioMime));
                $job->setAudioMime($audioMime);
            } catch (InvalidArtefact $exception) {
                return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        $this->jobs->getEntityManager()->persist($job);
        $this->jobs->getEntityManager()->flush();
        $request->attributes->set('origens_profile_job', $job->getId());

        return $this->json([
            'ok' => true,
            'job' => $job->getId(),
            'status' => 'queued',
            'message' => 'Na fila…',
        ]);
    }

    #[Route('/admin/records/profile/{id}', name: 'admin_artefact_profile_status', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function profileStatus(string $id): Response
    {
        $this->jobRunner->releaseStale();
        $job = $this->jobs->find($id);
        if (!$job instanceof ProfileJob) {
            return $this->json(['ok' => false, 'error' => 'Não foi possível encontrar essa geração.'], Response::HTTP_NOT_FOUND);
        }
        $kick = $job->getStatus() === 'queued'
            && $job->getRunAt() <= new \DateTimeImmutable()
            && $job->getCreatedAt() <= new \DateTimeImmutable('-5 seconds');
        $message = match ($job->getStage()) {
            'listening' => 'Ouvindo o áudio…',
            'looking' => 'Olhando a peça…',
            'writing' => 'Escrevendo…',
            'queued' => 'Na fila…',
            'done' => 'Perfil pronto. Nada foi salvo.',
            'failed' => $job->getError() ?? 'Não foi possível gerar o perfil.',
            'cancelled' => 'Cancelado.',
            default => 'Gerando…',
        };
        $payload = [
            'ok' => true,
            'job' => $job->getId(),
            'status' => $job->getStatus(),
            'stage' => $job->getStage(),
            'message' => $message,
            'error' => $job->getStatus() === 'failed' ? ($job->getError() ?? 'Não foi possível gerar o perfil.') : null,
        ];
        if ($job->getStatus() === 'done' && is_string($job->getResult())) {
            $decoded = json_decode($job->getResult(), true);
            if (is_array($decoded)) {
                $payload['profile'] = $decoded['profile'] ?? null;
                $payload['questions'] = $decoded['questions'] ?? [];
                $payload['complete'] = $decoded['complete'] ?? false;
                $payload['changed'] = $decoded['changed'] ?? [];
            }
        }

        if (!$kick) {
            return $this->json($payload);
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return new StreamedResponse(function () use ($encoded, $id): void {
            echo $encoded;
            $this->jobRunner->detachWebRequest();
            ignore_user_abort(true);
            set_time_limit(0);
            $this->jobRunner->executeId($id);
        }, Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    #[Route('/admin/records/backup', name: 'admin_artefact_backup', methods: ['GET'])]
    public function backup(): BinaryFileResponse
    {
        $path = $this->snapshots->temporaryDownload();
        $this->snapshots->markDownloaded();
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->snapshots->downloadName(new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'))),
        );
        $response->deleteFileAfterSend(true);

        return $response;
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
                $body = $this->files->xlsx($table['headers'], $table['rows'], $table['priceColumn']);
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

    #[Route('/admin/records/{id}/audio', name: 'admin_artefact_audio', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function artefactAudio(string $id): BinaryFileResponse
    {
        $artefact = $this->findArtefact($id);
        $state = $this->jobRunner->confirmedAudio($artefact);
        $path = $this->jobRunner->audioFile($artefact->getAudioPath());
        if ($state['stored'] !== true || $path === null) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $this->jobRunner->playbackType($artefact->getAudioMime(), $artefact->getAudioPath()));
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->headers->set('Cache-Control', 'private');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($path));

        return $response;
    }

    #[Route('/admin/records/{id}', name: 'admin_artefact_patch', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function patchField(Request $request, string $id): JsonResponse
    {
        $token = $request->request->getString('_token');
        if ($token === '') {
            $token = (string) $request->headers->get('X-CSRF-Token', '');
        }
        if (!$this->isCsrfTokenValid('inline', $token)) {
            return $this->json(['ok' => false, 'error' => 'Não foi possível salvar.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $artefact = $this->findArtefact($id);
        $field = $request->request->getString('field');
        $value = $request->request->getString('value');
        try {
            match ($field) {
                'price' => $artefact->setSalePriceCents(SalePrice::cents($value)),
                'size' => $this->assignSize($artefact, $value),
                'category' => $this->assignCategory($artefact, $value),
                'title' => $this->assignTitle($artefact, $value),
                default => throw new InvalidArtefact('Esse campo não pode ser alterado aqui.'),
            };
        } catch (InvalidArtefact $exception) {
            return $this->json(['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em = $this->artefacts->getEntityManager();
        $em->flush();
        $storedId = $artefact->getId();
        $em->clear();
        $stored = $em->find(Artefact::class, $storedId);
        if (!$stored instanceof Artefact) {
            return $this->json(['ok' => false, 'error' => 'Não foi possível gravar a peça. O arquivo não confirmou a gravação.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $saved = match ($field) {
            'price' => SalePrice::brl($stored->getSalePriceCents()),
            'size' => $stored->getSize(),
            'category' => (string) $stored->getCategory(),
            'title' => $stored->getTitle(),
            default => '',
        };

        return $this->json(['ok' => true, 'field' => $field, 'value' => $saved]);
    }

    #[Route('/admin/records/{id}/edit', name: 'admin_artefact_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function edit(Request $request, string $id): Response
    {
        return $this->save($request, $this->findArtefact($id));
    }

    #[Route('/admin/records/{id}/generate', name: 'admin_artefact_generate', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function generateAgain(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            return $this->redirectToRoute('admin_records');
        }

        $artefact = $this->findArtefact($id);
        $job = $this->jobRunner->queueFor($artefact, true);
        if ($job instanceof ProfileJob) {
            $request->attributes->set('origens_profile_job', $job->getId());
        }

        return $this->redirectToRoute('admin_records');
    }

    #[Route('/admin/records/profile/{id}/cancel', name: 'admin_artefact_profile_cancel', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function cancelProfile(Request $request, string $id): JsonResponse
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Não foi possível cancelar.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $job = $this->jobs->find($id);
        if (!$job instanceof ProfileJob) {
            return $this->json(['ok' => false, 'error' => 'Não foi possível encontrar essa geração.'], Response::HTTP_NOT_FOUND);
        }
        $this->jobRunner->cancel($job);

        return $this->json([
            'ok' => true,
            'job' => $job->getId(),
            'status' => 'cancelled',
            'message' => 'Cancelado.',
        ]);
    }

    #[Route('/admin/records/profile/{id}/retry', name: 'admin_artefact_profile_retry', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function retryProfile(Request $request, string $id): JsonResponse
    {
        if (!$this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            return $this->json(['ok' => false, 'error' => 'Não foi possível gerar o perfil. Tente novamente.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $job = $this->jobs->find($id);
        if (!$job instanceof ProfileJob) {
            return $this->json(['ok' => false, 'error' => 'Não foi possível encontrar essa geração.'], Response::HTTP_NOT_FOUND);
        }
        if ($job->getStatus() === 'running') {
            return $this->json(['ok' => true, 'job' => $job->getId(), 'status' => 'running', 'message' => 'Gerando…']);
        }
        $this->jobRunner->requeue($job);
        $request->attributes->set('origens_profile_job', $job->getId());

        return $this->json([
            'ok' => true,
            'job' => $job->getId(),
            'status' => 'queued',
            'message' => 'Na fila…',
        ]);
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

        $touched = ProfileExchange::touched($request->request->getString('locked'));
        $notes = trim($request->request->getString('notes'));
        if (mb_strlen($notes) > 4000) {
            $notes = rtrim(mb_substr($notes, 0, 4000));
        }
        $audioPath = null;
        $audioMime = null;
        $audioDuration = null;
        $clearAudio = $request->request->getString('remove_audio') === '1';
        $audio = AudioClip::read($request->files->get('audio'));
        if ($audio !== null) {
            $clearAudio = false;
            [$bytes, $audioMime] = $audio;
            $reported = $request->request->getInt('audio_ms');
            $audioDuration = $reported > 0 ? $reported : null;
            try {
                $audioPath = $this->jobRunner->storeAudio($bytes, $audioMime);
            } catch (InvalidArtefact $exception) {
                return $this->saveFailed($autosave, $current, $submission->draft, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        $previousAudio = $current?->getAudioPath();

        try {
            if ($current instanceof Artefact) {
                foreach ($this->registrar->update($current, $submission->data, $touched, $notes, $audioPath, $audioMime, $clearAudio, $audioDuration) as $path) {
                    $this->images->delete($path);
                }
                $artefact = $this->artefacts->find($current->getId());
                if (!$artefact instanceof Artefact) {
                    throw new InvalidArtefact('Não foi possível gravar a peça. O arquivo não confirmou a gravação.');
                }
            } else {
                $artefact = $this->registrar->create($submission->data, $touched, $notes, $audioPath, $audioMime, $audioDuration);
            }
        } catch (UniqueConstraintViolationException|InvalidArtefact) {
            return $this->saveFailed($autosave, $current, $submission->draft, 'Não foi possível salvar a peça. Nenhum código existente foi alterado.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($previousAudio !== null && $previousAudio !== $artefact->getAudioPath()) {
            $this->jobRunner->forgetAudio($previousAudio);
        }
        $audioState = $this->audioState($artefact);

        $generate = !$autosave && $request->request->getString('generate') === '1';
        $job = null;
        if ($generate) {
            $job = $this->jobRunner->queueFor($artefact, true);
            if ($job instanceof ProfileJob) {
                $request->attributes->set('origens_profile_job', $job->getId());
            }
        }
        $this->jobRunner->noteWhileRunning($artefact);
        $url = $request->getSchemeAndHttpHost().ArtefactRules::publicPath($artefact->getPublicToken());
        $wantsJson = $autosave || str_contains((string) $request->headers->get('Accept'), 'application/json');

        if ($wantsJson) {
            return $this->json([
                'ok' => true,
                'confirmed' => true,
                'id' => $artefact->getId(),
                'code' => $artefact->getCode(),
                'url' => $url,
                'ai' => $artefact->aiLabel(),
                'job' => $job?->getId(),
                'audio' => $audioState,
                'editUrl' => $this->generateUrl('admin_artefact_edit', ['id' => $artefact->getId()]),
            ]);
        }

        $request->getSession()->set('artefact_saved', [
            'code' => $artefact->getCode(),
            'url' => $url,
            'ai' => $artefact->aiLabel(),
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

    /** @return array{stored: bool, label: string, url: ?string, type: ?string} */
    private function audioState(?Artefact $artefact): array
    {
        if (!$artefact instanceof Artefact) {
            return ['stored' => false, 'label' => 'Sem áudio', 'url' => null, 'type' => null];
        }
        $state = $this->jobRunner->confirmedAudio($artefact);

        return [
            'stored' => $state['stored'],
            'label' => $state['label'],
            'url' => $state['stored'] ? $this->generateUrl('admin_artefact_audio', ['id' => $artefact->getId()]) : null,
            'type' => $state['type'] ?? null,
        ];
    }

    private function editor(?Artefact $current, ArtefactDraft $draft, string $error, int $status = 200): Response
    {
        $token = $current?->getPublicToken() ?? '';

        return $this->admin('admin/editor.html.twig', [
            'draft' => $draft,
            'error' => $error,
            'notes' => $current?->getNotes() ?? '',
            'aiLabel' => $current?->aiLabel() ?? '',
            'publicUrl' => $current instanceof Artefact && ArtefactRules::isValidToken($token)
                ? ArtefactRules::publicPath($token)
                : '',
            'action' => $current instanceof Artefact
                ? $this->generateUrl('admin_artefact_edit', ['id' => $current->getId()])
                : $this->generateUrl('admin_artefact_new'),
            'uploadUrl' => $this->generateUrl('admin_artefact_upload'),
            'profileUrl' => $this->generateUrl('admin_artefact_profile'),
            'recordId' => $current?->getId() ?? '',
            'audio' => $this->audioState($current),
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
                'title' => $artefact->getTitle() !== '' ? $artefact->getTitle() : 'Peça em registro',
                'titleInput' => $artefact->getTitle(),
                'createdOn' => $artefact->createdOn(),
                'price' => SalePrice::brl($artefact->getSalePriceCents()),
                'size' => $artefact->getSize(),
                'category' => $artefact->getCategory(),
                'image' => is_string($first) && ArtefactRules::isValidImagePath($first) ? $this->images->webPath($first) : null,
                'url' => ArtefactRules::isValidToken($token)
                    ? $request->getSchemeAndHttpHost().ArtefactRules::publicPath($token)
                    : '',
                'path' => ArtefactRules::isValidToken($token) ? ArtefactRules::publicPath($token) : '',
                'aiLabel' => $artefact->aiLabel(),
                'aiStatus' => $artefact->getAiStatus(),
                'hasAudio' => $this->jobRunner->confirmedAudio($artefact)['stored'],
            ];
        }

        return $this->admin('admin/records.html.twig', [
            'rows' => $rows,
            'exportError' => $exportError,
            'backup' => $this->snapshots->status(),
        ], $status);
    }

    private function assignTitle(Artefact $artefact, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArtefact('O nome da peça não pode ficar vazio.');
        }
        if (mb_strlen($value) > 180) {
            throw new InvalidArtefact('O nome da peça pode ter até 180 caracteres.');
        }
        $artefact->setTitle($value);
        $artefact->markManual('title');
    }

    private function assignSize(Artefact $artefact, string $value): void
    {
        if (!in_array($value, ['P', 'M', 'G'], true)) {
            throw new InvalidArtefact('Escolha P, M ou G.');
        }
        $artefact->setSize($value);
    }

    private function assignCategory(Artefact $artefact, string $value): void
    {
        if (!in_array($value, ['1', '2', '3'], true)) {
            throw new InvalidArtefact('Escolha a categoria 1, 2 ou 3.');
        }
        $artefact->setCategory((int) $value);
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
