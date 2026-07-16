<?php
namespace App\Http\Controllers\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
class LoginController extends Controller
{
    public function show() { return Inertia::render('Auth/Login'); }
    public function store(Request $request)
    {
        $credentials = $request->validate(['email'=>'required|email','password'=>'required']);
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended('/');
        }
        return back()->withErrors(['email'=>'Credenciales incorrectas.']);
    }
    public function destroy(Request $request)
    {git pull origin claude/festive-hamilton-z43s08
git add -A && git commit -m "ERP completo" && git push origin claude/festive-hamilton-z43s08

# 2. Instalar dependencias
composer install
npm install && npm run build

# 3. Configurar entorno
cp .env.example .env
php artisan key:generate

# 4. Base de datos (Codespace ya tiene PostgreSQL instalado)
php artisan migrate
php artisan db:seed

# 5. Levantar
php artisan serve        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}