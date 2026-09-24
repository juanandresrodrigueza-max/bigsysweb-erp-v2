<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Business extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'email', 'phone', 'logo', 'currency', 'country',
        'timezone', 'locale', 'date_format', 'time_format', 'financial_year_start_month',
        'cuit', 'razon_social', 'condicion_iva', 'afip_punto_venta',
        'afip_cert_path', 'afip_key_path', 'afip_produccion', 'is_active', 'owner_id',
        'mercadopago_settings', 'tiendanube_settings', 'recordatorios', 'whatsapp_settings', 'cbu_fce', 'impuestos', 'cierre_ejercicio_mes', 'vertical', 'suspended_at', 'suspension_motivo', 'notas_internas', 'alta_por', 'onboarding_completado_en', 'onboarding', 'backup_auto', 'tienda', 'fidelizacion', 'pos', 'avisos', 'verticales_extra', 'sueldos', 'rentabilidad', 'tarjetas', 'arba_settings', 'crm_settings', 'address', 'city', 'province', 'iibb', 'inicio_actividades', 'marca',
    ];

    public const VERTICALES = ['corralon' => 'Corralón / materiales', 'gastronomia' => 'Gastronomía', 'retail' => 'Comercio / indumentaria', 'minimarket' => 'Minimarket / almacén', 'servicios' => 'Servicios', 'industria' => 'Industria / producción', 'hoteleria' => 'Hotelería / alojamiento', 'otro' => 'Otro'];
    public const VERTICALES_MODULOS = ['gastronomia', 'retail', 'minimarket', 'hoteleria', 'servicios'];

    protected $casts = ['marca' => 'array', 'inicio_actividades' => 'date', 
        'verticales_extra' => 'array', 'sueldos' => 'array', 'rentabilidad' => 'array', 'tarjetas' => 'array',
        'is_active'            => 'boolean',
        'recordatorios'        => 'array',
        'impuestos'            => 'array',
        'onboarding'           => 'array',
        'tienda'               => 'array',
        'pos'                  => 'array',
        'avisos'               => 'array',
        'fidelizacion'         => 'array',
        'onboarding_completado_en' => 'datetime',
        'backup_auto'          => 'boolean',
        'whatsapp_settings'    => 'encrypted:array',
        'suspended_at'         => 'datetime',
        'afip_produccion'      => 'boolean',
        'mercadopago_settings' => 'encrypted:array',
        'arba_settings'        => 'encrypted:array',
        'crm_settings'         => 'encrypted:array',
        'tiendanube_settings'  => 'encrypted:array',
    ];

    protected $hidden = ['afip_cert_path', 'afip_key_path', 'mercadopago_settings', 'tiendanube_settings', 'arba_settings', 'crm_settings'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    // Usuarios de otras empresas con acceso a esta (contador multiempresa).
    public function usuariosExternos(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_businesses')->withPivot('role_id')->withTimestamps()->where('users.business_id', '!=', $this->id);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // Suscripción vigente: en prueba, activa o en gracia (todavía puede operar).
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->whereIn('status', Subscription::VIGENTES)->latestOfMany();
    }

    public function pagosSuscripcion(): HasMany
    {
        return $this->hasMany(PagoSuscripcion::class);
    }

    public function isSubscriptionActive(): bool
    {
        return $this->activeSubscription()->exists();
    }

    // La empresa no puede operar: dada de baja, suspendida a mano o sin suscripción vigente.
    public function bloqueada(): bool
    {
        return ! $this->is_active || $this->suspended_at !== null || ! $this->isSubscriptionActive();
    }

    public function motivoBloqueo(): string
    {
        if (! $this->is_active) {
            return 'La empresa fue dada de baja.';
        }
        if ($this->suspended_at) {
            return $this->suspension_motivo ?: 'La empresa está suspendida.';
        }
        $ultima = $this->subscription;
        return match ($ultima?->status) {
            'suspended' => 'La suscripción está suspendida por falta de pago.',
            'cancelled' => 'La suscripción fue cancelada.',
            default => 'La empresa no tiene una suscripción vigente.',
        };
    }

    // Módulos habilitados por el plan vigente; sin plan, solo el core.
    public function modulosActivos(): array
    {
        $todos = config('erp.modulos');
        $core  = array_keys(array_filter($todos, fn($m) => $m['core']));
        $plan  = $this->activeSubscription?->plan;
        if (! $plan) {
            return $core;
        }
        $features = (array) ($plan->features ?? []);
        $modulos = in_array('*', $features, true) ? array_keys($todos) : array_values(array_unique(array_merge($core, array_intersect(array_keys($todos), $features))));
        // Los verticales se muestran según el rubro de la empresa: un corralón no necesita ver "Gastronomía".
        $permitidos = $this->verticalesPermitidos();
        return array_values(array_filter($modulos, fn($m) => ! in_array($m, self::VERTICALES_MODULOS, true) || in_array($m, $permitidos, true)));
    }

    // Verticales visibles: los del rubro principal más los que el dueño habilitó a mano (una ferretería con cabañas, un taller con local).
    public function verticalesPermitidos(): array
    {
        $base = match ($this->vertical) {
            'gastronomia' => ['gastronomia', 'retail'],
            'minimarket' => ['minimarket'],
            'hoteleria' => ['hoteleria', 'retail'],
            'servicios' => ['servicios', 'retail'],
            default => ['retail'],
        };
        return array_values(array_unique(array_merge($base, (array) ($this->verticales_extra ?? []))));
    }

    // Datos del emisor para facturar e imprimir: los de la sucursal si tiene CUIT propio, si no los de la empresa.
    // Devuelve una copia de la empresa con los datos fiscales reemplazados, así todo lo que ya usa "la empresa" sigue funcionando.
    public function emisorPara(?BusinessLocation $loc): Business
    {
        if (! $loc || ! $loc->tieneCuitPropio()) return $this;
        $e = clone $this;
        $e->forceFill(['cuit' => $loc->cuit, 'razon_social' => $loc->razon_social ?: $this->razon_social, 'condicion_iva' => $loc->condicion_iva ?: $this->condicion_iva,
            'iibb' => $loc->iibb ?: $this->iibb, 'inicio_actividades' => $loc->inicio_actividades ?: $this->inicio_actividades,
            'address' => $loc->address ?: $this->address, 'city' => $loc->city ?: $this->city, 'province' => $loc->province ?: $this->province, 'phone' => $loc->phone ?: $this->phone, 'email' => $loc->email ?: $this->email,
            'afip_cert_path' => $loc->afip_cert_path, 'afip_key_path' => $loc->afip_key_path, 'afip_produccion' => $loc->afip_produccion ?? $this->afip_produccion]);
        $e->sucursalEmisora = $loc;
        return $e;
    }

    public ?BusinessLocation $sucursalEmisora = null;

    // Identidad en los comprobantes (Fase 25.4). Lo fiscal (razón social, CUIT, IIBB, inicio de actividades,
    // condición IVA, letra y código, CAE y QR) sale siempre; esto es lo que cada empresa elige.
    public const MARCA = [
        'color_primario' => '#e4003f', 'color_secundario' => '#4f3089', 'estilo' => 'clasico',
        'datos_extra' => '', 'pie' => '', 'validez_presupuesto' => 7,
        'mostrar' => ['logo' => true, 'codigo' => true, 'vendedor' => true, 'saldo' => false, 'firma_remito' => true, 'bonificacion' => true],
    ];
    public const ESTILOS = ['clasico' => 'Clásico (recuadro con la letra)', 'banda' => 'Banda de color arriba', 'minimo' => 'Mínimo (solo líneas)'];

    public function marca(): array
    {
        $m = array_replace(self::MARCA, (array) ($this->marca ?? []));
        $m['mostrar'] = array_replace(self::MARCA['mostrar'], (array) (($this->marca ?? [])['mostrar'] ?? []));
        return $m;
    }

    // Lo que usan las plantillas: colores con su color de texto legible encima, el logo embebido (dompdf lo dibuja) y los datos extra en líneas.
    public function marcaImpresion(): array
    {
        $m = $this->marca();
        $m['texto_primario'] = self::textoSobre($m['color_primario']);
        $m['texto_secundario'] = self::textoSobre($m['color_secundario']);
        $m['suave'] = self::mezclar($m['color_primario'], 0.08);
        $m['logo_uri'] = $m['mostrar']['logo'] ? $this->logoDataUri() : null;
        $m['lineas_extra'] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $m['datos_extra']))));
        return $m;
    }

    public function logoDataUri(): ?string
    {
        if (! $this->logo || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($this->logo)) return null;
        $mime = match (strtolower(pathinfo($this->logo, PATHINFO_EXTENSION))) { 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', default => 'image/png' };
        return "data:{$mime};base64," . base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($this->logo));
    }

    // Negro o blanco según la luminancia relativa del fondo (WCAG), para que el texto siempre se lea.
    public static function textoSobre(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $l = fn($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $lum = 0.2126 * $l($r) + 0.7152 * $l($g) + 0.0722 * $l($b);
        return (1.05 / ($lum + 0.05)) >= (($lum + 0.05) / 0.05) ? '#ffffff' : '#1c1a18';
    }

    public static function mezclar(string $hex, float $alfa): string
    {
        [$r, $g, $b] = self::rgb($hex);
        return sprintf('#%02x%02x%02x', round(255 - (255 - $r) * $alfa), round(255 - (255 - $g) * $alfa), round(255 - (255 - $b) * $alfa));
    }

    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#'); if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        return strlen($h) === 6 && ctype_xdigit($h) ? [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))] : [228, 0, 63];
    }

    public function tieneModulo(string $modulo): bool
    {
        return in_array($modulo, $this->modulosActivos(), true);
    }
}
