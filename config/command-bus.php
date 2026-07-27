<?php

declare(strict_types=1);

use App\Application\Approvals\Commands\DecideApproval;
use App\Application\Approvals\Commands\RequestApproval;
use App\Application\Approvals\Handlers\DecideApprovalHandler;
use App\Application\Approvals\Handlers\RequestApprovalHandler;
use App\Application\Planning\Commands\DecideRoadmapCommand;
use App\Application\Planning\Commands\EditRoadmapCommand;
use App\Application\Planning\Commands\RegenerateRoadmapCommand;
use App\Application\Planning\Handlers\DecideRoadmapHandler;
use App\Application\Planning\Handlers\EditRoadmapHandler;
use App\Application\Planning\Handlers\RegenerateRoadmapHandler;
use App\Application\Projects\Commands\StartProject;
use App\Application\Projects\Handlers\StartProjectHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Command Handlers
    |--------------------------------------------------------------------------
    |
    | Register each synchronous application command with exactly one handler.
    | The command bus resolves handler constructor dependencies through the
    | Laravel service container.
    |
    */

    'handlers' => [
        RequestApproval::class => RequestApprovalHandler::class,

        DecideApproval::class => DecideApprovalHandler::class,

        StartProject::class => StartProjectHandler::class,

        EditRoadmapCommand::class => EditRoadmapHandler::class,

        DecideRoadmapCommand::class => DecideRoadmapHandler::class,

        RegenerateRoadmapCommand::class => RegenerateRoadmapHandler::class,
    ],

];
