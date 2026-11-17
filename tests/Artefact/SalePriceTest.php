<?php

declare(strict_types=1);

namespace App\Tests\Artefact;

use App\Artefact\InvalidArtefact;
use App\Artefact\SalePrice;
use PHPUnit\Framework\TestCase;

final class SalePriceTest extends TestCase
{
    public function testParsesBrazilianAmountsIntoCents(): void
    {
        self::assertNull(SalePrice::cents(''));
        self::assertNull(SalePrice::cents('  R$  '));
        self::assertSame(125050, SalePrice::cents('1.250,50'));
        self::assertSame(125050, SalePrice::cents('R$ 1.250,50'));
        self::assertSame(125000, SalePrice::cents('1.250'));
        self::assertSame(98765, SalePrice::cents('987,65'));
        self::assertSame(1000, SalePrice::cents('10'));
        self::assertSame(0, SalePrice::cents('0,00'));
        self::assertSame('1.250,50', SalePrice::input(125050));
        self::assertSame('R$ 1.250,50', SalePrice::brl(125050));
        self::assertSame('1.250,50', SalePrice::excel(125050));
        self::assertSame('', SalePrice::brl(null));
    }

    public function testRejectsAPriceThatIsNotARealAmount(): void
    {
        $this->expectException(InvalidArtefact::class);
        $this->expectExceptionMessage(SalePrice::INVALID);
        SalePrice::cents('-10,00');
    }
}
