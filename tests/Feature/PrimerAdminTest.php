<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrimerAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_planes_y_superadmin_sin_datos_demo(): void
    {
        $this->artisan('erp:primer-admin', ['email' => 'yo@bigsys.com.ar'])->assertSuccessful();
        $u = User::where('email', 'yo@bigsys.com.ar')->first();
        $this->assertTrue((bool) $u->is_superadmin);
        $this->assertGreaterThan(0, Plan::count());
        $this->assertSame(1, User::count()); // nada de demo
        $h = $u->password;
        $this->artisan('erp:primer-admin', ['email' => 'yo@bigsys.com.ar'])->assertSuccessful();
        $this->assertSame($h, $u->fresh()->password); // otra vez no la cambia
    }
}
