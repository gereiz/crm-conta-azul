<?php

namespace Tests\Unit;

use App\Services\PhoneSanitizerService;
use Tests\TestCase;

class PhoneSanitizerServiceTest extends TestCase
{
    public function test_remove_ninth_digit_for_ddd_3x(): void
    {
        $input = '5531998765432'; // 55 + 31 + 9 + 98765432
        $sanitized = PhoneSanitizerService::sanitize($input);
        $this->assertSame('553198765432', $sanitized);
    }

    public function test_remove_ninth_digit_for_ddd_7x(): void
    {
        $input = '5571999998888'; // 55 + 71 + 9 + 99998888
        $sanitized = PhoneSanitizerService::sanitize($input);
        $this->assertSame('557199998888', $sanitized);
    }

    public function test_remove_ninth_digit_for_ddd_8x(): void
    {
        $input = '5585999998888'; // 55 + 85 + 9 + 99998888
        $sanitized = PhoneSanitizerService::sanitize($input);
        $this->assertSame('558599998888', $sanitized);
    }

    public function test_keep_ninth_digit_for_other_ddd(): void
    {
        $input = '5511999998888'; // 55 + 11 + 9 + 99998888
        $sanitized = PhoneSanitizerService::sanitize($input);
        $this->assertSame('5511999998888', $sanitized);
    }

    public function test_prefix_ddi_and_remove_leading_zero(): void
    {
        $input = '071999998888'; // leading zero + 71 + 9 + 99998888
        $sanitized = PhoneSanitizerService::sanitize($input);
        $this->assertSame('557199998888', $sanitized);
    }

    public function test_keep_international_number_with_plus(): void
    {
        $input = '+18573129041';
        $sanitized = PhoneSanitizerService::sanitize($input);
        $this->assertSame('18573129041', $sanitized);
    }

    public function test_keep_international_number_without_plus_but_long_enough(): void
    {
        $input = '3511913253623';
        $sanitized = PhoneSanitizerService::sanitize($input);
        $this->assertSame('3511913253623', $sanitized);
    }

    public function test_keep_europe_two_digit_ddi_spain(): void
    {
        $input = '34123456789'; // +34 (Espanha) + 9 dígitos
        $sanitized = PhoneSanitizerService::sanitize($input);
        $this->assertSame('34123456789', $sanitized);
    }

    public function test_keep_europe_two_digit_ddi_uk(): void
    {
        $input = '44123456789'; // +44 (Reino Unido) + 9 dígitos
        $sanitized = PhoneSanitizerService::sanitize($input);
        $this->assertSame('44123456789', $sanitized);
    }
}
