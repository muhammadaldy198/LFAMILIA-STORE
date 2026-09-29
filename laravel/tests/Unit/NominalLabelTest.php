<?php

namespace Tests\Unit;

use App\Support\NominalLabel;
use PHPUnit\Framework\TestCase;

class NominalLabelTest extends TestCase
{
    public function test_removes_only_the_leading_game_name_from_nominals(): void
    {
        self::assertSame('12 Diamond', NominalLabel::clean('Mobile Legends', 'MOBILELEGEND - 12 Diamond'));
        self::assertSame('100 Diamond', NominalLabel::clean('Free Fire', 'FREE FIRE 100 Diamond'));
        self::assertSame('5 Diamonds', NominalLabel::clean('Free Fire', 'Free Fire - 5 Diamonds'));
        self::assertSame('Membership Premium', NominalLabel::clean('Free Fire', 'Membership Premium'));
        self::assertSame('5 Diamonds', NominalLabel::clean('Free Fire', '5 Diamonds'));
        self::assertSame('Free Firebird - 5 Diamonds', NominalLabel::clean('Free Fire', 'Free Firebird - 5 Diamonds'));
    }

    public function test_numeric_order_uses_nominal_instead_of_sku_or_alphabetical_label(): void
    {
        $labels = ['100 Diamond', '5 Diamond', '1000 Diamond', '20 Diamond', '10 Diamond'];
        usort($labels, fn (string $a, string $b) =>
            NominalLabel::numericKey('Free Fire', $a) <=> NominalLabel::numericKey('Free Fire', $b));
        self::assertSame(['5 Diamond', '10 Diamond', '20 Diamond', '100 Diamond', '1000 Diamond'], $labels);
    }
}
