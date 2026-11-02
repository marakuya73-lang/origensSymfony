<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\ArtefactData;
use App\Artefact\ArtefactRules;
use App\Artefact\InvalidArtefact;
use PHPUnit\Framework\TestCase;

final class ArtefactRulesTest extends TestCase
{
    private const TOKEN = '3f2a9c1e7b4d8a6f5c0b2d9e1a7f4c86';

    public function testBuildsThePublicAddressFromAToken(): void
    {
        self::assertSame('/p/'.self::TOKEN, ArtefactRules::publicPath(self::TOKEN));
    }

    public function testRefusesToBuildAPublicAddressFromTheShortCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArtefactRules::publicPath('M-CAT1-001');
    }

    public function testRejectsTokensThatAreTooShort(): void
    {
        self::assertFalse(ArtefactRules::isValidToken('abc123'));
    }

    public function testRejectsTokensOutsideTheAlphabet(): void
    {
        self::assertFalse(ArtefactRules::isValidToken(substr(self::TOKEN, 0, 31).'g'));
    }

    public function testAcceptsPermanentSizeAndCategoryCodes(): void
    {
        foreach (['P-CAT2-001', 'M-CAT1-001', 'G-CAT3-001', 'P-CAT2-1000'] as $code) {
            self::assertTrue(ArtefactRules::isValidCode($code));
        }
    }

    public function testRejectsUnsupportedIdentityCodes(): void
    {
        self::assertFalse(ArtefactRules::isValidCode('X-CAT2-001'));
        self::assertFalse(ArtefactRules::isValidCode('P-CAT4-001'));
        self::assertFalse(ArtefactRules::isValidCode('P-CAT2-1'));
    }

    public function testFormatsCodesWithoutTruncatingAfter999(): void
    {
        self::assertSame('P-CAT2-001', ArtefactRules::formatCode('P', 2, 1));
        self::assertSame('P-CAT2-1000', ArtefactRules::formatCode('P', 2, 1000));
    }

    public function testRequiresAMainImage(): void
    {
        $this->expectException(InvalidArtefact::class);
        ArtefactData::fromSubmitted('Peça', 'P', 2, '', '', [['name' => 'Pedra', 'note' => '']], 'Cuidado', 'Feito à mão', [], '2026-10-18');
    }
}
