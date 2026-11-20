<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\DescriptionVoice;
use PHPUnit\Framework\TestCase;

final class DescriptionVoiceTest extends TestCase
{
    public function testStripsALeadingAttributionAndKeepsTheSentence(): void
    {
        self::assertSame(
            'A palha trançada guarda a casa.',
            DescriptionVoice::polish('Como dito, a palha trançada guarda a casa.'),
        );
        self::assertSame(
            'O verde acalma. A peça segue em silêncio.',
            DescriptionVoice::polish('Segundo a tradição, o verde acalma. A peça segue em silêncio.'),
        );
        self::assertSame(
            'As penas abrem a noite.',
            DescriptionVoice::polish('Como se vê, as penas abrem a noite.'),
        );
        self::assertSame(
            'A cura mora no centro.',
            DescriptionVoice::polish('A artesã contou que a cura mora no centro.'),
        );
    }

    public function testLeavesAFlowingSentenceUntouched(): void
    {
        $text = 'A peça respira como se vê o rio ao entardecer. A tradição da casa é o cuidado com a palha.';

        self::assertSame($text, DescriptionVoice::polish($text));
    }

    public function testDoesNotTouchTheMaterialNote(): void
    {
        $payload = DescriptionVoice::polishPayload([
            'title' => 'Conforme mencionado, abano de palha',
            'description' => 'Segundo o relato, a palha fecha o círculo.',
            'materials' => [['name' => 'Palha', 'note' => 'Como dito, clara.']],
        ]);

        self::assertSame('Abano de palha', $payload['title']);
        self::assertSame('A palha fecha o círculo.', $payload['description']);
        self::assertSame('Como dito, clara.', $payload['materials'][0]['note']);
    }
}
