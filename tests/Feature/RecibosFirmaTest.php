<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\ReciboExterno;
use App\Models\SueldoConcepto;
use App\Services\Sueldos\RecibosFirmaService;
use App\Services\Sueldos\SueldosService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\ErpTestCase;

// Fase 38: enviar y firmar los recibos (empleador una vez, empleado con fecha e IP), historial y reenvío;
// los PDF de afuera separados por hoja y repartidos por CUIL, DNI o legajo sin duplicar; y los datos del período.
class RecibosFirmaTest extends ErpTestCase
{
    private const FIRMA = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function liquidacion(): array
    {
        SueldoConcepto::cargarPlantilla($this->empresa->id, 'comercio');
        $base = ['business_id' => $this->empresa->id, 'sueldo_basico' => 800000, 'jornada' => 1, 'modalidad' => 'mensual', 'activo' => true, 'fecha_ingreso' => '2020-01-01'];
        $ana = Empleado::create($base + ['legajo' => 1, 'nombre' => 'ANA PEREZ', 'cuil' => '27-30111222-3', 'documento' => '30111222', 'email' => 'ana@test.com', 'telefono' => '3511112222']);
        $beto = Empleado::create($base + ['legajo' => 2, 'nombre' => 'BETO GOMEZ', 'cuil' => '20-28333444-5', 'documento' => '28333444', 'telefono' => '3513334444']);
        $liq = app(SueldosService::class)->liquidar($this->empresa->fresh(), '2026-09', 'mensual', [$ana->id => ['dias' => 30], $beto->id => ['dias' => 30]]);
        return [$liq, $ana, $beto];
    }

    public function test_enviar_firmar_con_ip_firma_del_empleador_historial_y_reenvio(): void
    {
        Mail::fake();
        [$liq, $ana, $beto] = $this->liquidacion();
        $this->post("/sueldos/{$liq->id}/enviar")->assertStatus(422); // borrador
        app(SueldosService::class)->confirmar($liq->fresh());
        $this->post('/sueldos/firma-empleador', ['firma' => self::FIRMA, 'nombre' => 'Juan Dueño'])->assertSessionHas('success');
        $this->assertSame('Juan Dueño', $this->empresa->fresh()->sueldos['firma_empleador_nombre']);
        $r = $this->post("/sueldos/{$liq->id}/enviar")->assertSessionHas('success');
        $envios = session('envios'); $this->assertCount(2, $envios);
        $this->assertTrue(collect($envios)->firstWhere('empleado', 'ANA PEREZ')['mail']); $this->assertStringContainsString('wa.me', collect($envios)->firstWhere('empleado', 'BETO GOMEZ')['link_whatsapp']);
        $ia = $liq->items()->where('empleado_id', $ana->id)->first(); $this->assertNotNull($ia->enviado_en); $this->assertNotNull($ia->token);
        // El empleado abre el link (sin usuario), ve el recibo y firma
        auth()->logout();
        $this->get("/recibo/{$ia->token}")->assertOk()->assertSee('ANA PEREZ'); $this->assertNotNull($ia->fresh()->visto_en);
        $this->get("/recibo/{$ia->token}/ver")->assertOk()->assertSee('Firma del empleador');
        $this->post("/recibo/{$ia->token}", ['firma' => 'nada', 'conformidad' => 'conforme'])->assertStatus(422);
        $this->post("/recibo/{$ia->token}", ['firma' => self::FIRMA, 'conformidad' => 'disconforme'])->assertSessionHasErrors('observacion');
        $this->withServerVariables(['REMOTE_ADDR' => '190.1.2.3'])->post("/recibo/{$ia->token}", ['firma' => self::FIRMA, 'conformidad' => 'disconforme', 'observacion' => 'Faltan las horas extra'])->assertRedirect("/recibo/{$ia->token}");
        $ia->refresh(); $this->assertNotNull($ia->firmado_en); $this->assertSame('190.1.2.3', $ia->firma_ip); $this->assertSame('disconforme', $ia->conformidad);
        $this->get("/recibo/{$ia->token}")->assertSee('Firmado');
        $this->post("/recibo/{$ia->token}", ['firma' => self::FIRMA, 'conformidad' => 'conforme'])->assertStatus(422); // no se firma dos veces
        $this->get("/recibo/{$ia->token}/ver")->assertSee('Firmado digitalmente')->assertSee('Faltan las horas extra');
        // Historial y reenvío sólo a los que faltan
        $this->actingAs($this->dueno);
        $h = collect(app(RecibosFirmaService::class)->historial('2026-09'))->keyBy('empleado');
        $this->assertSame('190.1.2.3', $h['ANA PEREZ']['ip']); $this->assertNull($h['BETO GOMEZ']['firmado']);
        $this->post("/sueldos/{$liq->id}/enviar", ['faltan' => 1]); $this->assertCount(1, session('envios'));
        $csv = $this->get('/sueldos/recibos?csv=1&periodo=2026-09')->assertOk()->getContent();
        $this->assertStringContainsString('ANA PEREZ', $csv); $this->assertStringContainsString('190.1.2.3', $csv);
        $this->get('/sueldos/recibos')->assertOk()->assertInertia(fn($p) => $p->component('Sueldos/Recibos', false)->has('historial', 2)->where('firma_empleador.nombre', 'Juan Dueño'));
    }

    public function test_recibos_externos_por_hoja_por_cuil_dni_legajo_sin_duplicar(): void
    {
        Storage::fake();
        [, $ana, $beto] = $this->liquidacion();
        $carlos = Empleado::create(['business_id' => $this->empresa->id, 'legajo' => 77, 'nombre' => 'CARLOS RUIZ', 'cuil' => null, 'sueldo_basico' => 1, 'modalidad' => 'mensual', 'activo' => true, 'fecha_ingreso' => '2020-01-01']);
        $html = '<div>Recibo de haberes Septiembre 2026<br>Empleado: Ana Perez CUIL 27-30111222-3</div>'
            . '<div style="page-break-before:always">Recibo de haberes Septiembre 2026<br>Empleado Beto DNI 28.333.444</div>'
            . '<div style="page-break-before:always">Recibo de haberes Septiembre 2026<br>Legajo N° 77</div>'
            . '<div style="page-break-before:always">Recibo de haberes Septiembre 2026<br>Alguien que no es de acá</div>';
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->output();
        $this->post('/sueldos/externos', ['archivo' => UploadedFile::fake()->createWithContent('sueldos_sep.pdf', $pdf)])->assertSessionHas('error'); // queda una sin asignar
        $rs = ReciboExterno::orderBy('pagina')->get(); $this->assertCount(4, $rs);
        $this->assertSame([$ana->id, 'cuil'], [$rs[0]->empleado_id, $rs[0]->identificado_por]);
        $this->assertSame([$beto->id, 'dni'], [$rs[1]->empleado_id, $rs[1]->identificado_por]);
        $this->assertSame([$carlos->id, 'legajo'], [$rs[2]->empleado_id, $rs[2]->identificado_por]);
        $this->assertNull($rs[3]->empleado_id); $this->assertSame('2026-09', $rs[0]->periodo);
        $this->assertStringStartsWith('%PDF', Storage::get($rs[0]->path));
        // El mismo PDF con otro nombre no se duplica
        $this->post('/sueldos/externos', ['archivo' => UploadedFile::fake()->createWithContent('renombrado.pdf', $pdf)])->assertSessionHasErrors('archivo');
        // Asignar la que no se pudo, mandarla y firmarla
        $this->post("/sueldos/externos/{$rs[3]->id}/asignar", ['empleado_id' => $beto->id])->assertSessionHas('success');
        $this->assertSame('a_mano', $rs[3]->fresh()->identificado_por);
        $this->post("/sueldos/enviar/externo/{$rs[3]->id}")->assertSessionHas('success');
        $t = $rs[3]->fresh()->token; auth()->logout();
        $this->get("/recibo/{$t}/ver")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->post("/recibo/{$t}", ['firma' => self::FIRMA, 'conformidad' => 'conforme']); $this->assertNotNull($rs[3]->fresh()->firmado_en);
    }

    public function test_datos_del_periodo_para_todos_y_costo_laboral(): void
    {
        [$liq] = $this->liquidacion();
        app(SueldosService::class)->confirmar($liq->fresh());
        $this->post('/sueldos/periodo', ['periodo' => '2026-09', 'fecha_pago' => '2026-10-04', 'deposito_fecha' => '2026-09-10', 'deposito_banco' => 'BCO NACION', 'deposito_periodo' => '2026-08'])->assertSessionHas('success');
        $l = $liq->fresh(); $this->assertSame('2026-10-04', $l->fecha->toDateString()); $this->assertSame('BCO NACION', $l->deposito_banco);
        $c = app(RecibosFirmaService::class)->costoLaboral();
        $this->assertCount(1, $c['meses']); $this->assertEqualsWithDelta((float) $l->total_bruto + (float) $l->total_no_rem + (float) $l->total_contribuciones, $c['total'], 0.01);
    }
}
