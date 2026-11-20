<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\GeneratedProfile;
use App\Artefact\ProfileExchange;
use PHPUnit\Framework\TestCase;

final class ProfileExchangeTest extends TestCase
{
    public function testHandEditedFieldsStayUnlessTheAnswerAsksToChangeThem(): void
    {
        $suggested = $this->profile('Nome da IA', 'aproximadamente 18 cm', 'M');
        $form = ProfileExchange::form((string) json_encode([
            'title' => 'Nome escrito à mão',
            'description' => 'Texto da pessoa.',
            'dimensions' => '',
            'materials' => [['name' => 'Palha', 'note' => 'Clara.']],
            'care' => "Guardar seco.\nSem sol.",
            'handmade' => 'Feito devagar.',
            'size' => 'G',
        ], JSON_THROW_ON_ERROR));

        $kept = ProfileExchange::merge($suggested, $form, ['title', 'dimensions', 'materials', 'size'], []);

        self::assertSame('Nome escrito à mão', $kept->title);
        self::assertSame('', $kept->dimensions);
        self::assertSame('Palha', $kept->materials[0]['name']);
        self::assertSame('G', $kept->size);
        self::assertSame('Uma peça pequena.', $kept->description);

        $changed = ProfileExchange::merge($suggested, $form, ['title', 'dimensions'], ['dimensions']);

        self::assertSame('Nome escrito à mão', $changed->title);
        self::assertSame('aproximadamente 18 cm', $changed->dimensions);
    }

    public function testALaterTurnKeepsARicherDescriptionUnlessARewriteWasAsked(): void
    {
        $current = 'Ela contou a história da peça, o sentido espiritual da proteção e a intenção de cura.';
        $form = ProfileExchange::form((string) json_encode([
            'title' => 'Verso',
            'description' => $current,
            'dimensions' => '',
            'materials' => [['name' => 'Palha', 'note' => '']],
            'care' => ['Guardar seco.'],
            'handmade' => 'Feito devagar.',
            'size' => 'M',
        ], JSON_THROW_ON_ERROR));

        $shortened = ProfileExchange::merge($this->profile('Outro nome', '', 'M'), $form, [], []);
        self::assertSame($current, $shortened->description);

        $enriched = GeneratedProfile::fromModel([
            'title' => 'Verso',
            'description' => $current.' Também falou do sonho à beira do rio.',
            'dimensions' => '',
            'materials' => [['name' => 'Palha', 'note' => '']],
            'care' => ['Guardar seco.'],
            'handmade' => 'Feito devagar.',
            'size' => 'M',
        ]);
        $grown = ProfileExchange::merge($enriched, $form, [], []);
        self::assertSame($current.' Também falou do sonho à beira do rio.', $grown->description);

        $rewritten = ProfileExchange::merge($this->profile('Outro nome', '', 'M'), $form, [], ['description']);
        self::assertSame('Uma peça pequena.', $rewritten->description);
    }

    public function testHistoryAndTouchedFieldsAreLimitedToTheConversation(): void
    {
        $history = ProfileExchange::history((string) json_encode([
            ['role' => 'assistant', 'text' => 'Qual a dimensão?'],
            ['role' => 'system', 'text' => 'ignorar'],
            ['role' => 'user', 'text' => 'Cerca de 18 cm.'],
        ], JSON_THROW_ON_ERROR));
        $touched = ProfileExchange::touched('["title","preço","title","care"]');

        self::assertSame([
            ['role' => 'assistant', 'text' => 'Qual a dimensão?'],
            ['role' => 'user', 'text' => 'Cerca de 18 cm.'],
        ], $history);
        self::assertSame(['title', 'care'], $touched);
    }

    private function profile(string $title, string $dimensions, string $size): GeneratedProfile
    {
        return GeneratedProfile::fromModel([
            'title' => $title,
            'description' => 'Uma peça pequena.',
            'dimensions' => $dimensions,
            'materials' => [['name' => 'Fibra', 'note' => '']],
            'care' => ['Manusear com cuidado.'],
            'handmade' => 'O trançado pede tempo.',
            'size' => $size,
        ]);
    }
}
