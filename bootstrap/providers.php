<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IdentityServiceProvider;
use App\Providers\IntegrationsServiceProvider;
use App\Providers\ProjectsServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    RateLimitServiceProvider::class,
    HorizonServiceProvider::class,
    AuditServiceProvider::class,
    IdentityServiceProvider::class,
    IntegrationsServiceProvider::class,
    ProjectsServiceProvider::class,
];
