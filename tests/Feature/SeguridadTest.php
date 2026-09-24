<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Canal;
use App\Models\Comprobante;
use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use App\Services\Afip\CertificadoCifrado;
use App\Services\Migracion\BackupService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 17: lo que protege la cuenta, los datos de cada empresa y las credenciales.
class SeguridadTest extends ErpTestCase
{
    public function test_cinco_intentos_fallidos_bloquean_el_login_y_quedan_auditados(): void
    {
        Auth::logout(); RateLimiter::clear('login:' . $this->dueno->email . '|127.0.0.1');
        for ($i = 0; $i < 5; $i++) $this->post('/login', ['email' => $this->dueno->email, 'password' => 'mala'])->assertSessionHasErrors('email');
        $r = $this->post('/login', ['email' => $this->dueno->email, 'password' => 'password']);
        $r->assertSessionHasErrors('email'); $this->assertStringContainsString('Demasiados intentos', session('errors')->first('email'));
        $this->assertGuest();
        $this->assertSame(5, AuditLog::withoutGlobalScopes()->where('accion', 'login_fallido')->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('accion', 'login_bloqueado')->count());
        RateLimiter::clear('login:' . $this->dueno->email . '|127.0.0.1');
        $this->post('/login', ['email' => $this->dueno->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_politica_de_contrasenas_en_alta_de_usuarios_y_cambio_propio(): void
    {
        $rol = $this->empresa->roles()->where('slug', 'vendedor')->value('id');
        $base = ['name' => 'Nuevo', 'email' => 'nuevo@test.com', 'role_id' => $rol, 'status' => 'active', 'sucursales' => [$this->sucursal->id]];
        $this->post('/configuracion/usuarios', $base + ['password' => 'soloLetras'])->assertSessionHasErrors('password');
        $this->post('/configuracion/usuarios', $base + ['password' => '12345678'])->assertSessionHasErrors('password');
        $this->post('/configuracion/usuarios', $base + ['password' => 'corta1'])->assertSessionHasErrors('password');
        $this->post('/configuracion/usuarios', $base + ['password' => 'clave2026'])->assertSessionHasNoErrors();
        // Cambio de la propia contraseña: pide la actual y respeta la política.
        $this->post('/configuracion/seguridad/password', ['password_actual' => 'incorrecta', 'password' => 'nueva2026', 'password_confirmation' => 'nueva2026'])->assertSessionHasErrors('password_actual');
        $this->post('/configuracion/seguridad/password', ['password_actual' => 'password', 'password' => 'debil', 'password_confirmation' => 'debil'])->assertSessionHasErrors('password');
        $this->post('/configuracion/seguridad/password', ['password_actual' => 'password', 'password' => 'nueva2026', 'password_confirmation' => 'nueva2026'])->assertSessionHas('success');
        $this->assertTrue(\Hash::check('nueva2026', $this->dueno->fresh()->password));
        $this->assertSame(1, AuditLog::where('accion', 'cambio_clave')->count());
    }

    public function test_recuperacion_de_contrasena_por_mail_sin_revelar_si_el_email_existe(): void
    {
        Notification::fake(); Auth::logout();
        $this->post('/recuperar', ['email' => 'noexiste@test.com'])->assertSessionHas('success');
        $r = $this->post('/recuperar', ['email' => $this->dueno->email]); $r->assertSessionHas('success');
        $this->assertSame(Password::RESET_LINK_SENT, session('estado'), 'estado: ' . session('estado') . ' · status ' . $r->status());
        Notification::assertCount(1);
        Notification::assertSentTo($this->dueno, ResetPassword::class, function ($n, $canales, $u) {
            $url = $n->toMail($u)->actionUrl;
            return str_contains($url, '/restablecer/') && str_contains($url, urlencode($u->email));
        });
        $token = Password::broker()->createToken($this->dueno);
        $this->post('/restablecer', ['token' => $token, 'email' => $this->dueno->email, 'password' => 'sinnumeros', 'password_confirmation' => 'sinnumeros'])->assertSessionHasErrors('password');
        $this->post('/restablecer', ['token' => $token, 'email' => $this->dueno->email, 'password' => 'otra2026', 'password_confirmation' => 'otra2026'])->assertRedirect('/login');
        $this->assertTrue(\Hash::check('otra2026', $this->dueno->fresh()->password));
        $this->post('/restablecer', ['token' => $token, 'email' => $this->dueno->email, 'password' => 'otra2027', 'password_confirmation' => 'otra2027'])->assertSessionHasErrors('email', 'El token no sirve dos veces');
    }

    public function test_credenciales_de_terceros_quedan_cifradas_en_la_base(): void
    {
        $this->empresa->update(['whatsapp_settings' => ['token' => 'EAAB-secreto', 'phone_id' => '123'], 'mercadopago_settings' => ['access_token' => 'APP_USR-secreto']]);
        $raw = DB::table('businesses')->where('id', $this->empresa->id)->first();
        $this->assertStringNotContainsString('secreto', (string) $raw->whatsapp_settings);
        $this->assertStringNotContainsString('secreto', (string) $raw->mercadopago_settings);
        $this->assertSame('EAAB-secreto', $this->empresa->fresh()->whatsapp_settings['token']);
        $c = Canal::create(['business_id' => $this->empresa->id, 'tipo' => 'woocommerce', 'nombre' => 'Woo', 'credenciales' => ['consumer_secret' => 'cs_secreto'], 'activo' => true]);
        $this->assertStringNotContainsString('cs_secreto', (string) DB::table('canales')->where('id', $c->id)->value('credenciales'));
        $this->assertSame('cs_secreto', $c->fresh()->credenciales['consumer_secret']);
    }

    public function test_certificados_afip_se_guardan_cifrados_y_se_descifran_para_usarlos(): void
    {
        Storage::fake('local');
        $cert = "-----BEGIN CERTIFICATE-----\nMIIB-demo\n-----END CERTIFICATE-----"; $key = "-----BEGIN PRIVATE KEY-----\nMIIE-demo\n-----END PRIVATE KEY-----";
        $this->post('/configuracion/afip/certificados', ['cert' => UploadedFile::fake()->createWithContent('cert.crt', 'no es pem'), 'key' => UploadedFile::fake()->createWithContent('private.key', $key)])->assertSessionHasErrors('cert');
        $this->post('/configuracion/afip/certificados', ['cert' => UploadedFile::fake()->createWithContent('cert.crt', $cert), 'key' => UploadedFile::fake()->createWithContent('private.key', $key)])->assertSessionHas('success');
        $b = $this->empresa->fresh();
        $this->assertStringEndsWith('.enc', $b->afip_cert_path);
        $this->assertStringNotContainsString('BEGIN', Storage::disk('local')->get($b->afip_cert_path), 'En disco está cifrado');
        $this->assertSame($cert, file_get_contents(CertificadoCifrado::rutaLegible($b->afip_cert_path)));
        $this->assertSame($key, Crypt::decryptString(Storage::disk('local')->get($b->afip_key_path)));
        // Instalaciones viejas con archivos en claro: el comando los cifra.
        Storage::disk('local')->put("afip/{$b->id}/cert.crt", $cert);
        $b->forceFill(['afip_cert_path' => "afip/{$b->id}/cert.crt"])->save();
        $this->assertSame(1, CertificadoCifrado::cifrarExistentes());
        $this->assertStringEndsWith('.enc', $b->fresh()->afip_cert_path); $this->assertFalse(Storage::disk('local')->exists("afip/{$b->id}/cert.crt"));
    }

    public function test_cabeceras_de_seguridad_limite_de_paginas_publicas_y_tipos_de_archivo(): void
    {
        $r = $this->get('/dashboard'); $r->assertOk();
        $r->assertHeader('X-Frame-Options', 'SAMEORIGIN'); $r->assertHeader('X-Content-Type-Options', 'nosniff'); $r->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        RateLimiter::clear('127.0.0.1');
        for ($i = 0; $i < 60; $i++) $this->get('/p/token-inexistente')->assertNotFound();
        $this->get('/p/token-inexistente')->assertStatus(429, 'La página pública se limita por IP');
        $this->post('/configuracion/importar/previsualizar', ['archivo' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'), 'entidad' => 'clientes'])->assertSessionHasErrors('archivo');
    }

    public function test_una_empresa_no_ve_ni_toca_los_datos_de_otra(): void
    {
        [$otra, $sucB, $duenoB] = $this->crearEmpresa('Otra SA', 'otra');
        $p = Product::withoutGlobalScopes()->create(['business_id' => $otra->id, 'business_location_id' => $sucB->id, 'name' => 'Ajeno', 'sku' => 'AJ1', 'tipo' => 'producto', 'unit' => 'un', 'cost' => 1, 'price' => 2, 'iva' => 21, 'stock' => 0, 'active' => true]);
        $c = Contact::withoutGlobalScopes()->create(['business_id' => $otra->id, 'type' => 'customer', 'name' => 'Cliente ajeno', 'condicion_iva' => 'Consumidor Final', 'is_active' => true, 'lista_precios' => 1]);
        $f = Comprobante::withoutGlobalScopes()->create(['business_id' => $otra->id, 'business_location_id' => $sucB->id, 'contact_id' => $c->id, 'user_id' => $duenoB->id, 'direccion' => 'venta', 'tipo' => 'PRE', 'fecha' => today()->toDateString(), 'condicion' => 'contado', 'estado' => 'borrador', 'total' => 100]);
        // Logueado como el dueño de la empresa de prueba (setUp): nada de lo ajeno aparece ni se puede modificar.
        $this->assertNull(Product::find($p->id)); $this->assertNull(Contact::find($c->id)); $this->assertNull(Comprobante::find($f->id));
        $this->get("/stock/{$p->id}")->assertNotFound();
        $this->get("/clientes/{$c->id}")->assertNotFound();
        $this->get("/comprobantes/{$f->id}")->assertNotFound();
        $this->post("/comprobantes/{$f->id}/emitir")->assertNotFound();
        $this->post("/comprobantes/{$f->id}/anular", ['motivo' => 'prueba'])->assertNotFound();
        $this->post("/clientes/{$c->id}", ['name' => 'Hackeado', 'condicion_iva' => 'Consumidor Final'])->assertNotFound();
        $this->assertSame('Cliente ajeno', Contact::withoutGlobalScopes()->find($c->id)->name);
        $this->getJson('/buscar/articulos/venta?q=Ajeno')->assertOk()->assertJsonCount(0);
        $this->assertSame(0, \App\Models\AuditLog::where('business_id', $otra->id)->count(), 'La auditoría también está aislada');
    }

    public function test_la_copia_de_seguridad_se_restaura_de_verdad(): void
    {
        Storage::fake('local');
        $p = $this->articulo(['name' => 'Para restaurar', 'sku' => 'RES1', 'stock_inicial' => 4]);
        $cli = $this->cliente(['name' => 'Cliente respaldado']);
        $svc = app(BackupService::class);
        $bk = $svc->crear($this->empresa, 'manual', $this->dueno->id);
        $this->assertTrue(Storage::disk('local')->exists($bk->archivo));
        $zip = new \ZipArchive(); $zip->open(Storage::disk('local')->path($bk->archivo));
        $this->assertNotFalse($zip->getFromName('manifest.json')); $this->assertNotFalse($zip->getFromName('datos/products.json')); $zip->close();
        // Se rompe todo y se restaura.
        $cli->update(['name' => 'Cambiado']); DB::table('stock_movements')->delete(); DB::table('stock_depositos')->delete(); DB::table('products')->delete();
        $this->assertNull(Product::withoutGlobalScopes()->find($p->id));
        $r = $svc->restaurar($this->empresa, Storage::disk('local')->path($bk->archivo), $this->dueno->id);
        $this->assertGreaterThan(0, $r['products'] ?? 0);
        $this->assertSame('Para restaurar', Product::withoutGlobalScopes()->find($p->id)?->name);
        $this->assertEqualsWithDelta(4, (float) Product::withoutGlobalScopes()->find($p->id)->stock, 0.001);
        $this->assertSame('Cliente respaldado', $cli->fresh()->name);
        $this->assertSame(2, \App\Models\Backup::count(), 'Antes de restaurar se guarda una copia del estado previo');
    }
}
