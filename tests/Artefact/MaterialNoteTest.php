<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\MaterialNote;
use App\Artefact\SymbolicTradition;
use PHPUnit\Framework\TestCase;

final class MaterialNoteTest extends TestCase
{
    public function testANoteDoesNotStartWithTheNameOrTheTraditionFormula(): void
    {
        self::assertSame(
            'Pele, proteção e o que atravessou o tempo.',
            MaterialNote::polish('couro', 'Co. Na tradição, o couro é pele, proteção e o que atravessou o tempo.'),
        );
        self::assertSame(
            'O ancestral, estrutura e memória do que sustentou a vida.',
            MaterialNote::polish('osso', 'O osso é o ancestral, estrutura e memória do que sustentou a vida.'),
        );
        self::assertSame(
            'Casca aromática, verde-escura, de árvore que sombreia o terreiro.',
            MaterialNote::polish('aroeira', 'Casca aromática, verde-escura, de árvore que sombreia o terreiro.'),
        );
    }

    public function testEachFeatherSpeciesBecomesItsOwnMaterial(): void
    {
        $rows = MaterialNote::expand(
            'penas',
            'Pena de corujão-orelhudo e pena de pavão. Em muitas tradições, a pena liga o chão ao alto.',
        );

        self::assertSame(['pena de corujão-orelhudo', 'pena de pavão'], array_column($rows, 'name'));
        self::assertStringStartsNotWith('pena de', mb_strtolower($rows[0]['note']));
        self::assertStringNotContainsString('Na tradição', $rows[0]['note']);
        self::assertStringNotContainsString('Em muitas tradições', $rows[0]['note']);
        self::assertStringNotContainsString('pavão', mb_strtolower($rows[0]['note']));
        self::assertStringNotContainsString('Em muitas tradições', $rows[1]['note']);
        self::assertDoesNotMatchRegularExpression('/^[.\s]/', $rows[1]['note']);
    }

    public function testBacuralIsCorrectedBeforeTheNoteIsCleaned(): void
    {
        $tradition = SymbolicTradition::open();
        $name = $tradition->correct('bacural');
        $note = MaterialNote::polish($name, $tradition->correct('Na tradição, o bacural canta.'));

        self::assertSame('bacurau', $name);
        self::assertSame('Canta.', $note);
    }
}
