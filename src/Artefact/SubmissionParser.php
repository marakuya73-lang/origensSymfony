<?php

declare(strict_types=1);

namespace App\Artefact;

use App\Entity\Artefact;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

final class SubmissionParser
{
    public function __construct(private ImageStore $images)
    {
    }

    public function parse(Request $request, ?Artefact $current, bool $storeUploads = true): Submission
    {
        if (!$request->isMethod('POST')) {
            $draft = $current instanceof Artefact
                ? ArtefactDraft::fromArtefact($current, $this->images)
                : ArtefactDraft::blank();

            return new Submission($draft, null, null);
        }

        $uploaded = [];
        try {
            if ($storeUploads) {
                $uploaded = $this->images->storeMany($request->files->all('uploads'));
            }
            $paths = $this->paths($request, $uploaded);
            $this->images->assertExist($paths);
            $materials = $this->materials($request);
            $size = $current?->getSize() ?? $request->request->getString('size');
            $category = $current?->getCategory() ?? $request->request->getInt('category');
            $data = ArtefactData::fromSubmitted(
                $request->request->getString('title'),
                $size,
                $category,
                $request->request->getString('description'),
                $request->request->getString('dimensions'),
                $materials,
                $request->request->getString('care'),
                $request->request->getString('handmade'),
                $paths,
                $request->request->getString('record_date'),
            );

            return new Submission(
                ArtefactDraft::fromPosted(
                    $data->title,
                    $data->size,
                    $data->category,
                    $data->description,
                    $data->dimensions,
                    $data->recordDate->format('Y-m-d'),
                    $data->handmade,
                    $data->materials,
                    implode("\n", $data->care),
                    $data->images,
                    $this->images,
                    $current,
                ),
                $data,
                null,
            );
        } catch (InvalidArtefact $exception) {
            return new Submission($this->failedDraft($request, $current, $uploaded), null, $exception->getMessage());
        } catch (BadRequestException) {
            return new Submission($this->failedDraft($request, $current, $uploaded), null, ArtefactData::GENERIC);
        }
    }

    /**
     * @param list<string> $uploaded
     *
     * @return list<string>
     */
    private function paths(Request $request, array $uploaded): array
    {
        $paths = [];
        foreach ($request->request->all('images') as $path) {
            if (!is_string($path) || !ArtefactRules::isValidImagePath($path)) {
                throw new InvalidArtefact(ArtefactData::GENERIC);
            }
            $paths[] = $path;
        }

        foreach ($uploaded as $path) {
            $paths[] = $path;
        }

        $unique = [];
        foreach ($paths as $path) {
            if (!in_array($path, $unique, true)) {
                $unique[] = $path;
            }
        }

        if (count($unique) > 12) {
            throw new InvalidArtefact('Selecione até 12 fotos por peça.');
        }

        return $unique;
    }

    /** @return list<array{name: string, note: string}> */
    private function materials(Request $request): array
    {
        $materials = [];
        foreach ($request->request->all('materials') as $row) {
            if (!is_array($row)) {
                throw new InvalidArtefact(ArtefactData::GENERIC);
            }
            $materials[] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'note' => trim((string) ($row['note'] ?? '')),
            ];
        }

        return $materials;
    }

    /** @param list<string> $uploaded */
    private function failedDraft(Request $request, ?Artefact $current, array $uploaded): ArtefactDraft
    {
        $paths = [];
        try {
            foreach ($request->request->all('images') as $path) {
                if (is_string($path) && $this->images->exists($path)) {
                    $paths[] = $path;
                }
            }
        } catch (BadRequestException) {
            $paths = [];
        }

        foreach ($uploaded as $path) {
            if ($this->images->exists($path)) {
                $paths[] = $path;
            }
        }

        $materials = [];
        try {
            $materials = $this->postedMaterials($request);
        } catch (InvalidArtefact|BadRequestException) {
            $materials = [['name' => '', 'note' => '']];
        }

        return ArtefactDraft::fromPosted(
            $request->request->getString('title'),
            $current?->getSize() ?? $request->request->getString('size'),
            $current?->getCategory() ?? $request->request->getInt('category'),
            $request->request->getString('description'),
            $request->request->getString('dimensions'),
            $request->request->getString('record_date'),
            $request->request->getString('handmade'),
            $materials,
            $request->request->getString('care'),
            $paths,
            $this->images,
            $current,
        );
    }

    /** @return list<array{name: string, note: string}> */
    private function postedMaterials(Request $request): array
    {
        $materials = [];
        foreach ($request->request->all('materials') as $row) {
            if (!is_array($row)) {
                continue;
            }
            $materials[] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'note' => trim((string) ($row['note'] ?? '')),
            ];
        }

        return $materials;
    }
}
