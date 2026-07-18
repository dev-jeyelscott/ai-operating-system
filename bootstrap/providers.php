<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IdentityServiceProvider;
use App\Providers\ProjectsServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityServiceProvider::class,
    ProjectsServiceProvider::class,
];
