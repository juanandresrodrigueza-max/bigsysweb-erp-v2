<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

// Primera instalación en producción: carga los planes y crea el superadmin de BigSys con una contraseña al azar.
// No carga datos demo. Si el usuario ya existe, no lo toca (salvo --resetear, que le da una contraseña nueva).
class PrimerAdmin extends Command
{
    protected $signature = 'erp:primer-admin {email} {--nombre=Administrador BigSys} {--resetear}';
    protected $description = 'Carga los planes y crea el superadmin para una instalación nueva';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => PlanSeeder::class, '--force' => true]);
        $u = User::where('email', $this->argument('email'))->first();
        if ($u && ! $this->option('resetear')) { $this->info("Ya existe {$u->email}; no se cambió nada (usá --resetear para darle otra contraseña)."); return self::SUCCESS; }
        $clave = Str::password(16, symbols: false);
        $u ??= new User(['email' => $this->argument('email')]);
        $u->forceFill(['name' => $u->name ?: $this->option('nombre'), 'password' => $clave, 'status' => 'active', 'is_superadmin' => true])->save();
        $this->newLine();
        $this->info('Usuario: ' . $u->email);
        $this->info('Contraseña: ' . $clave);
        $this->warn('Guardala y cambiala al entrar. No se vuelve a mostrar.');
        return self::SUCCESS;
    }
}
