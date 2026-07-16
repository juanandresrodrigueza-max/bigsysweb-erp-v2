<?php
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
Route::get("/login", [LoginController::class, "show"])->name("login");
Route::post("/login", [LoginController::class, "store"])->name("login.store");
Route::post("/logout", [LoginController::class, "destroy"])->middleware("auth")->name("logout");
Route::middleware("auth")->group(function () {
    Route::get("/", [DashboardController::class, "index"])->name("dashboard");
    Route::get("/{any}", [DashboardController::class, "index"])->where("any", "(?!api).*");
});