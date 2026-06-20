<?php
use Knuckles\Scribe\Config\{AuthIn, Defaults, Strategies};
use Knuckles\Scribe\Extracting\Strategies;

return [
    'theme' => 'default',
    'title' => 'BigSysWeb ERP API',
    'description' => 'API REST para el sistema ERP BigSysWeb. Gestión de clientes, productos, ventas e inventario.',
    'base_url' => env('APP_URL', 'http://localhost'),
    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/*'],
                'domains'  => ['*'],
            ],
            'include' => [],
            'exclude' => [],
        ],
    ],
    'auth' => [
        'enabled'     => true,
        'default'     => true,
        'in'          => 'bearer',
        'name'        => 'Authorization',
        'use_value'   => env('SCRIBE_AUTH_KEY'),
        'placeholder' => '{TU_TOKEN}',
        'extra_info'  => 'Obtené tu token con POST /api/auth/login',
    ],
    'intro_text' => <<<INTRO
Esta documentación cubre todos los endpoints del ERP BigSysWeb.

## Autenticación
Todos los endpoints (excepto registro y login) requieren un Bearer Token.

```bash
curl -X POST /api/auth/login \\
  -H "Content-Type: application/json" \\
  -d '{"email":"user@example.com","password":"password"}'
INTRO,
'type' => 'static',
'static' => ['output_path' => 'public/docs'],
'laravel' => ['add_routes' => true, 'docs_url' => '/docs'],
'try_it_out' => ['enabled' => true, 'base_url' => null, 'use_csrf' => false],
'logo' => false,
'last_updated' => 'Actualizado automáticamente',
'examples' => ['faker_seed' => 1234, 'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst']],
'strategies' => [
'metadata' => [Strategies\Metadata\GetFromDocBlocks::class, Strategies\Metadata\GetFromMetadataAttributes::class],
'urlParameters' => [Strategies\UrlParameters\GetFromLaravelAPI::class, Strategies\UrlParameters\GetFromUrlParamAttribute::class],
'queryParameters' => [Strategies\QueryParameters\GetFromFormRequest::class, Strategies\QueryParameters\GetFromQueryParamAttribute::class],
'headers' => [Strategies\Headers\GetFromRouteRules::class, Strategies\Headers\GetFromHeaderAttribute::class],
'bodyParameters' => [Strategies\BodyParameters\GetFromFormRequest::class, Strategies\BodyParameters\GetFromBodyParamAttribute::class],
'responses' => [Strategies\Responses\UseResponseAttributes::class, Strategies\Responses\UseTransformerTags::class, Strategies\Responses\UseApiResourceTags::class, Strategies\Responses\UseResponseTag::class, Strategies\Responses\ResponseCalls::class],
'responseFields' => [Strategies\ResponseFields\GetFromResponseFieldAttribute::class, Strategies\ResponseFields\GetFromResponseFieldTag::class],
],
'fractal' => ['serializer' => null],
'routeMatcher' => \Knuckles\Scribe\Matching\RouteMatcher::class,
];
