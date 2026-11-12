<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Halaman akar kini mengarah ke halaman pilot Fase 4 (Buku Kas
     * Pengeluaran PPKD). Tamu lalu diarahkan ke formulir masuk Laravel.
     */
    public function test_halaman_akar_mengarah_ke_landing_aklap(): void
    {
        $this->get('/')->assertRedirect('/aklap');

        $this->get('/aklap')->assertRedirect('/masuk');
    }
}
