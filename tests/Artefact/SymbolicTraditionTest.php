<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\SymbolicTradition;
use PHPUnit\Framework\TestCase;

final class SymbolicTraditionTest extends TestCase
{
    public function testSelectsTheStoneAndTheFeatherThatWereNamed(): void
    {
        $ids = array_column($this->tradition()->select('Ela falou de uma ametista e de uma pena de coruja.'), 'id');

        self::assertContains('ametista', $ids);
        self::assertContains('coruja', $ids);
        self::assertContains('pena', $ids);
        self::assertNotContains('citrino', $ids);
        self::assertNotContains('turmalina-negra', $ids);
        self::assertNotContains('aries', $ids);
    }

    public function testQuartzoRosaDoesNotAlsoSelectClearQuartzOrTheColourAlone(): void
    {
        $ids = array_column($this->tradition()->select('A peça é de quartzo rosa.'), 'id');

        self::assertSame(['quartzo-rosa'], $ids);
    }

    public function testPromptCarriesTheMatchedTextAndOnlyASentenceForTheRest(): void
    {
        $prompt = $this->tradition()->promptFor('Ametista na mesa.');

        self::assertStringContainsString('recolhimento do espírito', $prompt);
        self::assertStringContainsString('abanilho', $prompt);
        self::assertStringNotContainsString('O citrino, quartzo que a tradição vê dourado', $prompt);
        self::assertStringContainsString('o citrino acompanha a alegria', $prompt);
        self::assertStringNotContainsString('signo de fogo regido por Marte', $prompt);
    }

    public function testGlossaryRewritesMishearingsAndCloseSpellings(): void
    {
        $tradition = $this->tradition();

        self::assertSame(
            'O abanilho e o abano ficaram no cocar.',
            $tradition->correct('O abanilio e o habano ficaram no cocár.'),
        );
        self::assertSame('Abanilho', $tradition->correct('Abanilio'));
        self::assertSame('um abanilho pequeno', $tradition->correct('um abaniliho pequeno'));
        self::assertSame('coçar a mão', $tradition->correct('coçar a mão'));
        self::assertSame('O ábano escuro', $tradition->correct('O ábano escuro'));
        self::assertSame('maravilha', $tradition->correct('maravilha'));
    }

    public function testGlossaryCorrectsTheReturnedProfile(): void
    {
        $payload = $this->tradition()->correctPayload([
            'title' => 'Abanilio de penas',
            'description' => 'O habano abre o caminho.',
            'dimensions' => '',
            'handmade' => 'Feito no abanoo.',
            'materials' => [['name' => 'buzio', 'note' => 'Contas de buzios.']],
            'care' => ['Não molhar o abanillo.'],
            'questions' => ['O abanilyo é de arara?'],
            'size' => 'M',
            'changed' => ['description'],
        ]);

        self::assertSame('Abanilho de penas', $payload['title']);
        self::assertSame('O abano abre o caminho.', $payload['description']);
        self::assertSame('Feito no abano.', $payload['handmade']);
        self::assertSame('búzio', $payload['materials'][0]['name']);
        self::assertSame('Contas de búzios.', $payload['materials'][0]['note']);
        self::assertSame('Não molhar o abanilho.', $payload['care'][0]);
        self::assertSame('O abanilho é de arara?', $payload['questions'][0]);
        self::assertSame(['description'], $payload['changed']);
    }

    private function tradition(): SymbolicTradition
    {
        return SymbolicTradition::open();
    }
}
