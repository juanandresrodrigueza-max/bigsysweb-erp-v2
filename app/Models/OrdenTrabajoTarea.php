<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenTrabajoTarea extends Model
{
    protected $table = 'orden_trabajo_tareas';
    protected $fillable = ['orden_trabajo_id', 'user_id', 'descripcion', 'hecha', 'hecha_en'];
    protected $casts = ['hecha' => 'boolean', 'hecha_en' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
}
