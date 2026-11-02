<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\ArtefactData;
use App\Artefact\ArtefactRegistrar;
use App\Artefact\ArtefactRules;
use App\Tests\SchemaBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArtefactRegistrarTest extends KernelTestCase
{
    private const IMAGE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        SchemaBuilder::rebuild(static::getContainer()->get(EntityManagerInterface::class));
    }

    public function testAssignsIndependentCodesAndKeepsIdentityOnEdit(): void
    {
        $registrar = $this->registrar();

        $first = $registrar->create($this->data('Primeira', 'M', 1));
        $second = $registrar->create($this->data('Segunda', 'M', 1));
        $other = $registrar->create($this->data('Terceira', 'G', 3));

        self::assertSame('M-CAT1-001', $first->getCode());
        self::assertSame('M-CAT1-002', $second->getCode());
        self::assertSame('G-CAT3-001', $other->getCode());
        self::assertNotSame($first->getPublicToken(), $second->getPublicToken());
        self::assertTrue(ArtefactRules::isValidToken($first->getPublicToken()));

        $code = $first->getCode();
        $token = $first->getPublicToken();
        $createdAt = $first->getCreatedAt();
        $registrar->update($first, $this->data('Primeira revista', 'P', 2));

        self::assertSame('Primeira revista', $first->getTitle());
        self::assertSame('M', $first->getSize());
        self::assertSame(1, $first->getCategory());
        self::assertSame($code, $first->getCode());
        self::assertSame($token, $first->getPublicToken());
        self::assertEquals($createdAt, $first->getCreatedAt());
    }

    public function testDoesNotTruncateTheCounterAfter999(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'INSERT INTO artefact_counters (size, category, last_number) VALUES (:size, :category, 999)',
            ['size' => 'P', 'category' => 2],
        );

        $piece = $this->registrar()->create($this->data('Milésima', 'P', 2));

        self::assertSame('P-CAT2-1000', $piece->getCode());
    }

    private function registrar(): ArtefactRegistrar
    {
        $registrar = static::getContainer()->get(ArtefactRegistrar::class);
        self::assertInstanceOf(ArtefactRegistrar::class, $registrar);

        return $registrar;
    }

    private function data(string $title, string $size, int $category): ArtefactData
    {
        return ArtefactData::fromSubmitted(
            $title,
            $size,
            $category,
            'Descrição breve',
            '20 cm',
            [['name' => 'Pedra', 'note' => 'Nota']],
            "Guardar seco.",
            'Feito à mão com calma.',
            [self::IMAGE],
            '2026-10-18',
        );
    }
}
