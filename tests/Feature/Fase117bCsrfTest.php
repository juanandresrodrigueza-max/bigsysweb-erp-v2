<?php

namespace Tests\Feature;

use Tests\ErpTestCase;

// Fase 117.2: /csrf-renovar devuelve una cookie XSRF-TOKEN fresca para que el front reintente un envío que dio 419.
class Fase117bCsrfTest extends ErpTestCase
{
    public function test_csrf_renovar_responde_y_manda_la_cookie_xsrf(): void
    {
        $r = $this->get('/csrf-renovar')->assertOk()->assertJsonPath('ok', true);
        $r->assertCookie('XSRF-TOKEN');
    }

    public function test_csrf_renovar_funciona_sin_sesion_iniciada(): void
    {
        auth()->logout();
        $this->get('/csrf-renovar')->assertOk()->assertCookie('XSRF-TOKEN');
    }
}
