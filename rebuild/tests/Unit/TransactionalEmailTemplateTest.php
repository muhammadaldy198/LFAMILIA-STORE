<?php

namespace Tests\Unit;

use App\Jobs\SendTransactionalEmailJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TransactionalEmailTemplateTest extends TestCase
{
    public function test_branded_email_escapes_untrusted_content_and_preserves_line_breaks(): void
    {
        $job = new SendTransactionalEmailJob('customer@example.com', '<script>alert(1)</script>', "Hello <b>customer</b>\nSecond line");
        $method = new ReflectionMethod($job, 'htmlBody');
        $html = $method->invoke($job);

        $this->assertStringContainsString('LFAMILIA STORE', $html);
        $this->assertStringContainsString('support@lfamiliastore.my.id', $html);
        $this->assertStringContainsString('lfamilia-footer-desktop-wordmark.jpg', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('Hello &lt;b&gt;customer&lt;/b&gt;<br>Second line', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>customer</b>', $html);
    }
}
