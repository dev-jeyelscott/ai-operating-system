<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\CommandBusServiceProvider;
use App\Providers\DomainEventsServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IdentityServiceProvider;
use App\Providers\IntegrationsServiceProvider;
use App\Providers\ProjectsServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    AuditServiceProvider::class,
    CommandBusServiceProvider::class,
    DomainEventsServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityServiceProvider::class,
    IntegrationsServiceProvider::class,
    ProjectsServiceProvider::class,
    RateLimitServiceProvider::class,
];
