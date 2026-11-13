<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fases 92 a 94: avisos en la campanita: el diseño nuevo, la app del celular y el repaso de UX.
return new class extends Migration
{
    private function novedades(): array
    {
        return [
            ['titulo' => 'BigSysWeb se ve como la web de BigSys', 'modulo' => null, 'url' => '/dashboard',
             'texto' => 'Renovamos el diseño de todo el sistema con los colores de bigsys.com.ar: ciruela y magenta, tarjetas redondeadas, tipografía más clara y un menú a la izquierda que se lee de un vistazo. No cambió ninguna dirección ni ningún botón de lugar: todo lo que usabas está donde estaba, sólo que más lindo y más fácil de leer, también en el celular.'],
            ['titulo' => 'La app del celular (iPhone y Android) está en camino', 'modulo' => null, 'url' => '/avisos-celular',
             'texto' => 'El sistema ya está preparado para funcionar como app nativa en iPhone y Android: avisos que llegan al toque aunque no tengas el sistema abierto, Bigcito que escucha mejor por el micrófono del teléfono y los links de BigSysWeb que abren directo en la app. Cuando esté publicada en las tiendas, en el celular vas a ver la barra «Instalar la app»; mientras tanto, Avisos al celular sigue funcionando desde el navegador como hasta ahora.'],
            ['titulo' => 'Repaso de todas las pantallas: nada apretado, nada cortado', 'modulo' => null, 'url' => '/dashboard',
             'texto' => 'Revisamos las 133 pantallas del sistema en computadora y en celular. Ahora nada se desborda ni queda cortado en el teléfono, la letra nunca es más chica de 11 píxeles, cada pantalla de Configuración explica para qué sirve, los botones de ícono dicen qué hacen al pasar el mouse, los importes no se parten en dos líneas y los tiempos largos se muestran en horas («1 h 32» en vez de «92 minutos»). Si algo te sigue pareciendo confuso, contalo desde la ayuda: lo leemos todo.'],
        ];
    }

    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('novedades')) return;
        foreach ($this->novedades() as $n) {
            if (DB::table('novedades')->where('titulo', $n['titulo'])->exists()) continue;
            DB::table('novedades')->insert($n + ['publicada_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('novedades')) return;
        DB::table('novedades')->whereIn('titulo', array_column($this->novedades(), 'titulo'))->delete();
    }
};
