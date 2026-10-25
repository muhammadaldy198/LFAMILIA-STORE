<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\AdminUser;
use App\Services\AdminNotificationService;
use App\Services\EditableMessageTemplateService;
use App\Services\OperationalSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class AdminEditableControlsTest extends TestCase
{
    use DatabaseTransactions;

    private function login(): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'Editable Controls Tester',
            'email' => 'editable.'.bin2hex(random_bytes(6)).'@example.test',
            'password' => Hash::make('test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    public function test_email_templates_save_even_without_any_notification_events(): void
    {
        $this->login();
        DB::table('system_settings')->where('key', 'store.message_templates')->delete();
        DB::table('admin_notifications')->delete();

        $this->put('/admin/message-templates', [
            'emails' => [
                'order_created' => [
                    'subject' => '{{default_subject}}',
                    'body' => "Pesanan LFAMILIA:\n{{default_body}}",
                ],
            ],
            'notifications' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $stored = json_decode((string) DB::table('system_settings')
            ->where('key', 'store.message_templates')->value('value'), true);

        $this->assertSame("Pesanan LFAMILIA:\n{{default_body}}", $stored['emails']['order_created']['body']);
        $this->assertSame([], $stored['notifications']);
    }

    public function test_long_custom_notification_title_is_safe_for_database_and_payment_events(): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => 'store.message_templates'], [
            'value' => json_encode(['notifications' => [
                'payment.paid' => [
                    'title' => str_repeat('X', 185).'{{default_title}}',
                    'body' => '{{default_body}}',
                ],
            ]], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rendered = app(EditableMessageTemplateService::class)->renderNotification(
            'payment.paid', 'Pembayaran diterima', 'Invoice lunas'
        );
        $this->assertSame(180, mb_strlen($rendered['title']));
        $id = app(AdminNotificationService::class)->record(
            'payment.paid', 'Pembayaran diterima', 'Invoice lunas',
            'INFO', null, null, [], false
        );
        $this->assertSame(180, mb_strlen((string) DB::table('admin_notifications')->where('id', $id)->value('title')));
    }

    public function test_checkout_duration_falls_back_to_existing_config_until_overridden(): void
    {
        DB::table('system_settings')->where('key', 'operations.checkout_reservation_minutes')->delete();
        config()->set('lfamilia.checkout_reservation_minutes', 47);
        $this->assertSame(47, app(OperationalSettings::class)->value('checkout_reservation_minutes'));

        DB::table('system_settings')->insert([
            'key' => 'operations.checkout_reservation_minutes',
            'value' => json_encode(75, JSON_THROW_ON_ERROR),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(75, app(OperationalSettings::class)->value('checkout_reservation_minutes'));
    }

    public function test_older_queued_email_jobs_without_template_key_fall_back_to_generic(): void
    {
        $reflection = new ReflectionClass(SendTransactionalEmailJob::class);
        $job = $reflection->newInstanceWithoutConstructor();
        foreach (['recipient' => 'customer@example.test', 'subject' => 'Order', 'text' => 'Detail'] as $name => $value) {
            $reflection->getProperty($name)->setValue($job, $value);
        }
        $method = new ReflectionMethod($job, 'emailTemplateKey');
        $this->assertSame('generic', $method->invoke($job));
    }

    public function test_relative_support_link_in_email_uses_absolute_website_url(): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => 'store.support_url'], [
            'value' => json_encode('/contact', JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new SendTransactionalEmailJob('customer@example.test', 'Order', 'Detail');
        $method = new ReflectionMethod($job, 'htmlBody');
        $html = $method->invoke($job);
        $this->assertStringContainsString('href="'.rtrim((string) config('app.url'), '/').'/contact"', $html);
        $this->assertStringNotContainsString('href="/contact"', $html);
    }
}
