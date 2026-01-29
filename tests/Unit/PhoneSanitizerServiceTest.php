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
}
