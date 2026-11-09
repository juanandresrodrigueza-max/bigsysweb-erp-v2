<?php

return [

    'sentry' => ['dsn' => env('SENTRY_DSN')],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model'   => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    'afip' => [
        'ambiente' => env('AFIP_AMBIENTE', 'homologacion'),
    ],

    // Fase 46: captcha opcional del registro (Cloudflare Turnstile). Sin claves, el registro usa solo la trampa y el límite por IP.
    'turnstile' => ['site_key' => env('TURNSTILE_SITE_KEY'), 'secret' => env('TURNSTILE_SECRET')],

    // Fase 78: disco (de config/filesystems.php) donde se sube cada copia de seguridad fuera del servidor, p. ej. "s3" apuntando a un
    // bucket de Backblaze, Wasabi o Cloudflare R2. Sin esto las copias viven en el mismo disco del servidor y la salud lo avisa.
    'backups' => ['disk' => env('BACKUP_DISK', env('OFFSITE_S3_BUCKET') ? 'backblaze' : null), 'prefijo' => trim((string) env('OFFSITE_S3_PREFIX', 'bigsysweb/erp/'), '/') . '/'],

    // Fase 76: a quién le llegan los avisos del sistema (error nuevo, salud crítica, resumen semanal). Varios mails separados por coma.
    // Lo que se carga en Superadmin → Salud manda; esto es el valor inicial desde el .env.
    'monitoreo' => ['email' => env('MONITOREO_EMAIL')],

];
