<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class TeamDashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('team/index');
    }
}
