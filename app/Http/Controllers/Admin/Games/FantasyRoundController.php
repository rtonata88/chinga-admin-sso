<?php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FantasyRoundController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('admin/games/fantasy/rounds', [
            'rounds' => [],
        ]);
    }
}
