<?php

namespace App\Http\Controllers;

use App\Actions\ComputeIncomingDividends;
use Inertia\Inertia;
use Inertia\Response;

class DividendController extends Controller
{
    public function index(ComputeIncomingDividends $compute): Response
    {
        $user = auth()->user();

        ['confirmed' => $confirmed, 'events' => $events, 'monthly' => $monthly, 'summary' => $summary]
            = $compute->forUser($user);

        return Inertia::render('Dividends/Index', compact('confirmed', 'events', 'monthly', 'summary'));
    }
}
