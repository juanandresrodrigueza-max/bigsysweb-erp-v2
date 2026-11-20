<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Los tests no necesitan el frontend compilado (en el CI public/build no existe).
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        \App\Models\SistemaConfig::olvidar(); // la configuración leída en una prueba no se arrastra a la siguiente
        \App\Services\Monitoreo\SaludService::$espacioDisco = fn() => [50 * 1024 ** 3, 100 * 1024 ** 3]; // el disco de la máquina de pruebas no cuenta
    }
}
