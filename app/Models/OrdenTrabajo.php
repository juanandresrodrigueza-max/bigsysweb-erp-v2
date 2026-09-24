<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

// Orden de trabajo del servicio técnico: equipo que entra, diagnóstico, presupuesto, tareas, hoja de trabajo y entrega con firma.
class OrdenTrabajo extends Model
{
    use \App\Models\Concerns\FechaSoloDia;
    use BelongsToBusiness;

    public const ESTADOS = ['recibido' => 'Recibido', 'diagnostico' => 'En diagnóstico', 'presupuestado' => 'Presupuestado', 'aprobado' => 'Aprobado', 'en_curso' => 'En reparación', 'listo' => 'Listo para retirar', 'entregado' => 'Entregado', 'cancelado' => 'Cancelado'];
    public const PRIORIDADES = ['baja' => 'Baja', 'normal' => 'Normal', 'alta' => 'Urgente'];

    protected $table = 'ordenes_trabajo';
    protected $fillable = ['business_id', 'business_location_id', 'numero', 'contact_id', 'nombre', 'telefono', 'equipo', 'marca_modelo', 'serie', 'falla', 'diagnostico', 'estado', 'prioridad', 'tecnico_id', 'proyecto_id', 'fecha_ingreso', 'fecha_prometida', 'entregado_en', 'presupuesto', 'aprobado_en', 'comprobante_id', 'firma', 'firma_nombre', 'token', 'notas'];
    protected $casts = ['fecha_ingreso' => 'date', 'fecha_prometida' => 'date', 'entregado_en' => 'datetime', 'aprobado_en' => 'datetime', 'presupuesto' => 'decimal:2'];

    public function contact() { return $this->belongsTo(Contact::class); }
    public function tecnico() { return $this->belongsTo(User::class, 'tecnico_id'); }
    public function items() { return $this->hasMany(OrdenTrabajoItem::class); }
    public function tareas() { return $this->hasMany(OrdenTrabajoTarea::class)->orderBy('id'); }
    public function comprobante() { return $this->belongsTo(Comprobante::class); }
    public function business() { return $this->belongsTo(Business::class); }

    public function numeroFormateado(): string { return 'OT-' . str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT); }
    public function clienteNombre(): string { return $this->contact?->name ?? $this->nombre ?? 'Sin nombre'; }
    public function telefonoCliente(): ?string { return $this->telefono ?: $this->contact?->phone; }
    public function urlPublica(): string { return url("/ot/{$this->token}"); }
    public function totalItems(): float { return round((float) $this->items()->sum('total'), 2); }
}
