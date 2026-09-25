<?php

namespace App\Services\Ventas;

use App\Models\AuditLog;
use App\Models\Contact;

// Cliente cargado desde la factura: se busca por CUIT o documento y después por nombre; si existe se actualizan sus datos,
// si no, se crea. Si no se pide guardarlo, los datos quedan solo en el comprobante (consumidor final identificado).
class ClienteDesdeFacturaService
{
    public const CAMPOS = ['nombre', 'documento', 'condicion_iva', 'address', 'city', 'email', 'phone'];

    public static function digitos(?string $s): string { return preg_replace('/\D/', '', (string) $s); }

    public function buscar(array $r): ?Contact
    {
        $doc = self::digitos($r['documento'] ?? null);
        if ($doc !== '') {
            $c = Contact::customers()->get(['id', 'cuit', 'document'])->first(fn($x) => self::digitos($x->cuit) === $doc || self::digitos($x->document) === $doc);
            if ($c) return Contact::find($c->id);
        }
        $nom = mb_strtolower(trim((string) ($r['nombre'] ?? '')));
        return $nom !== '' ? Contact::customers()->whereRaw('LOWER(name) = ?', [$nom])->first() : null;
    }

    // Arma un contacto (guardado o no) con los datos de la factura.
    public function contacto(array $r, int $businessId, bool $guardar): Contact
    {
        $doc = self::digitos($r['documento'] ?? null);
        $esCuit = strlen($doc) === 11;
        $datos = array_filter([
            'name' => trim((string) ($r['nombre'] ?? '')) ?: null,
            'cuit' => $esCuit ? substr($doc, 0, 2) . '-' . substr($doc, 2, 8) . '-' . substr($doc, 10) : null,
            'document_type' => $doc !== '' ? ($esCuit ? 'CUIT' : 'DNI') : null, 'document' => $doc !== '' && ! $esCuit ? $doc : null,
            'condicion_iva' => $r['condicion_iva'] ?? null, 'address' => $r['address'] ?? null, 'city' => $r['city'] ?? null,
            'email' => $r['email'] ?? null, 'phone' => $r['phone'] ?? null,
        ], fn($v) => $v !== null && $v !== '');
        $datos['condicion_iva'] ??= 'Consumidor Final';
        if (! $guardar) return new Contact($datos);
        $c = $this->buscar($r);
        if ($c) {
            $c->fill($datos)->save();
            AuditLog::registrar('editar', $c, "Datos del cliente {$c->name} actualizados desde la factura");
            return $c;
        }
        $c = Contact::create($datos + ['business_id' => $businessId, 'type' => 'customer', 'is_active' => true, 'lista_precios' => 1, 'dias_pago' => 0]);
        AuditLog::registrar('crear', $c, "Cliente {$c->name} creado desde la factura");
        return $c;
    }
}
