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
    }
}
