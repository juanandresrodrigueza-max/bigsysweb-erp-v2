<?php

return [
    'theme'       => 'default',
    'title'       => 'BigSysWeb ERP API',
    'description' => 'API REST para el sistema ERP BigSysWeb.',
    'base_url'    => env('APP_URL', 'http://localhost'),
    'routes' => [[
        'match'   => ['prefixes' => ['api/*'], 'domains' => ['*']],
        'include' => [],
        'exclude' => [],
    ]],
    'auth' => [
        'enabled'     => true,
        'default'     => true,
        'in'          => 'bearer',
        'name'        => 'Authorization',
        'use_value'   => env('SCRIBE_AUTH_KEY'),
        'placeholder' => '{TU_TOKEN}',
        'extra_info'  => 'Obtené tu token con POST /api/auth/login',
    ],
    'type'       => 'static',
    'static'     => ['output_path' => 'public/docs'],
    'laravel'    => ['add_routes' => true, 'docs_url' => '/docs'],
    'try_it_out' => ['enabled' => true, 'base_url' => null, 'use_csrf' => false],
    'logo'         => false,
    'last_updated' => 'Actualizado automáticamente',
    'examples'     => ['faker_seed' => 1234, 'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst']],
    'strategies'   => [
        'metadata'        => [\Knuckles\Scribe\Extracting\Strategies\Metadata\GetFromDocBlocks::class],
        'urlParameters'   => [\Knuckles\Scribe\Extracting\Strategies\UrlParameters\GetFromLaravelAPI::class],
        'queryParameters' => [\Knuckles\Scribe\Extracting\Strategies\QueryParameters\GetFromFormRequest::class],
        'headers'         => [\Knuckles\Scribe\Extracting\Strategies\Headers\GetFromRouteRules::class],
        'bodyParameters'  => [\Knuckles\Scribe\Extracting\Strategies\BodyParameters\GetFromFormRequest::class],
        'responses'       => [
            \Knuckles\Scribe\Extracting\Strategies\Responses\UseResponseAttributes::class,
            \Knuckles\Scribe\Extracting\Strategies\Responses\UseApiResourceTags::class,
            \Knuckles\Scribe\Extracting\Strategies\Responses\ResponseCalls::class,
        ],
        'responseFields'  => [\Knuckles\Scribe\Extracting\Strategies\ResponseFields\GetFromResponseFieldAttribute::class],
    ],
    'routeMatcher' => \Knuckles\Scribe\Matching\RouteMatcher::class,
];
