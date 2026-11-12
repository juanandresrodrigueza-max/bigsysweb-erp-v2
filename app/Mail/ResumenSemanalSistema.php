<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

// Fase 76: los lunes, el dueño de BigSys recibe cómo le fue al sistema la semana pasada con uso real.
class ResumenSemanalSistema extends Mailable
{
    public function __construct(public array $r) {}

    public function build(): static
    {
        $r = $this->r; $li = fn(array $xs) => $xs ? '<ul>' . implode('', array_map(fn($x) => '<li>' . $x . '</li>', $xs)) . '</ul>' : '<p>Nada.</p>';
        $html = '<h2>BigSysWeb · semana del ' . e($r['desde']) . ' al ' . e($r['hasta']) . '</h2>'
            . '<p><b>Uso:</b> ' . (int) $r['uso']['empresas'] . ' empresas, ' . (int) $r['uso']['activas'] . ' usaron el sistema esta semana, ' . (int) $r['uso']['usuarios_activos'] . ' usuarios activos, ' . number_format((float) $r['uso']['vistas'], 0, ',', '.') . ' pantallas vistas.</p>'
            . '<p><b>Las que más usan:</b></p>' . $li(array_map(fn($f) => e($f['nombre']) . ' · ' . (int) $f['vistas'] . ' vistas · ' . (int) $f['usuarios_activos'] . ' usuario(s)', $r['ranking']))
            . '<p><b>En riesgo (sin uso hace 7 días o más):</b></p>' . $li(array_map(fn($f) => e($f['nombre']) . ' · ' . ($f['sin_uso_dias'] === null ? 'nunca entró' : $f['sin_uso_dias'] . ' días sin uso'), $r['en_riesgo']))
            . '<p><b>Módulos más usados:</b> ' . e(implode(', ', array_map(fn($m) => $m['label'] . ' (' . $m['empresas'] . ')', $r['modulos']))) . '</p>'
            . '<p><b>Errores de la semana:</b> ' . (int) $r['errores']['total'] . ' en ' . (int) $r['errores']['distintos'] . ' lugar(es) distinto(s), ' . (int) $r['errores']['sin_resolver'] . ' sin resolver.</p>' . $li(array_map(fn($e) => '<code>' . e($e['codigo']) . '</code> ' . e($e['que']) . ' · ' . (int) $e['veces'] . ' vez/veces · ' . e($e['donde']), $r['errores']['principales']))
            . '<p><b>Salud ahora:</b> ' . e($r['salud']['estado']) . '.</p>' . $li(array_map(fn($c) => '<b>' . e($c['nombre']) . '</b>: ' . e($c['detalle']), $r['salud']['con_problemas']))
            . '<p><b>Copias de seguridad:</b> ' . (int) $r['copias']['hechas'] . ' en la semana' . ($r['copias']['sin_copia_48h'] ? '; <b>sin copia en 48 h</b>: ' . e(implode(', ', $r['copias']['sin_copia_48h'])) : '; todas las empresas con copia al día') . '.</p>'
            . '<p><b>Soporte:</b> ' . (int) $r['soporte']['abiertos'] . ' ticket(s) abiertos, ' . (int) $r['soporte']['nuevos'] . ' nuevos en la semana.</p>'
            . (isset($r['navegacion']) ? '<p><b>Navegación (semana anterior → esta):</b> pantallas por persona y día ' . e($r['navegacion']['antes']['pantallas_por_dia']) . ' → ' . e($r['navegacion']['ahora']['pantallas_por_dia'])
                . ' · acciones por cada 100 vistas ' . e($r['navegacion']['antes']['acciones_por_100_vistas']) . ' → ' . e($r['navegacion']['ahora']['acciones_por_100_vistas'])
                . ' · tickets de «no encuentro» ' . (int) $r['navegacion']['antes']['tickets_no_encuentro'] . ' → ' . (int) $r['navegacion']['ahora']['tickets_no_encuentro'] . '.</p>' : '')
            . '<p>Paneles: ' . e(url('/admin/uso')) . ' · ' . e(url('/admin/salud')) . '</p>';
        return $this->subject('BigSysWeb · resumen semanal: ' . (int) $r['uso']['activas'] . ' empresas activas, ' . (int) $r['errores']['total'] . ' errores')->html($html);
    }
}
