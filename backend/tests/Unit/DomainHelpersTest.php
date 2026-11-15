<?php

namespace Tests\Unit;

use App\Domain\Promotions\PromotionService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Phone;
use App\Models\Promotion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainHelpersTest extends TestCase
{
    #[DataProvider('phones')]
    public function test_senegalese_phone_normalization(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Phone::normalizeSenegal($input));
    }

    public static function phones(): array
    {
        return [
            ['77 123 45 67', '+221771234567'],
            ['+221 78 123 45 67', '+221781234567'],
            ['00221 76 123 45 67', '+221761234567'],
            ['33 812 34 56', '+221338123456'],
            ['+33 6 12 34 56 78', null],
            ['12345', null],
            [null, null],
        ];
    }

    public function test_phone_masking_hides_middle_digits(): void
    {
        $this->assertSame('+22177 *** ** 67', Phone::mask('+221771234567'));
    }

    public function test_money_has_no_decimals(): void
    {
        $this->assertSame("12\u{202F}500 FCFA", Money::format(12500));
    }

    public function test_discount_is_integer_capped_and_never_exceeds_subtotal(): void
    {
        $service = new PromotionService;
        $percent = new Promotion(['discount_type' => 'percent', 'discount_value' => 15, 'max_discount_xof' => 1000]);
        $this->assertSame(1000, $service->discountFor($percent, 10000));
        $this->assertSame(499, $service->discountFor(new Promotion(['discount_type' => 'percent', 'discount_value' => 10]), 4999));
        $this->assertSame(3000, $service->discountFor(new Promotion(['discount_type' => 'fixed', 'discount_value' => 5000]), 3000));
    }
}
