<?php

namespace Tests\Feature;

use Tests\TestCase;

class MaintenancePageTest extends TestCase
{
    public function test_maintenance_page_shows_the_message_without_navigation_buttons(): void
    {
        $html = view('errors.503')->render();

        $this->assertStringContainsString('dibenerin', $html);
        $this->assertStringContainsString('Pesananmu', $html);
        // Keputusan pemilik 19 Nov 2026: tanpa tombol navigasi — selama
        // maintenance seluruh toko menjawab 503, jadi tombol ke lacak
        // pesanan atau beranda hanya memutar pengunjung kembali ke
        // halaman ini.
        $this->assertStringNotContainsString('Cek pesanan', $html);
        $this->assertStringNotContainsString('Kembali ke beranda', $html);
    }
}
