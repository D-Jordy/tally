<?php

namespace App\Http\Controllers;

use App\Actions\ComputeProjections;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectionController extends Controller
{
    public function index(Request $request, ComputeProjections $compute): Response
    {
        $user    = auth()->user();
        $horizon = (int) $request->query('horizon', 5);

        if (!in_array($horizon, [1, 3, 5, 10])) {
            $horizon = 5;
        }

        $data = $compute->forUser($user, $horizon);

        return Inertia::render('Projections/Index', array_merge($data, [
            'annual_contribution_eur' => $data['annual_contribution_eur'],
        ]));
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'contribution' => ['required', 'numeric', 'min:0'],
            'cadence'      => ['required', 'in:month,year'],
        ]);

        $annual = (float) $request->input('contribution');

        if ($request->input('cadence') === 'month') {
            $annual *= 12;
        }

        $user = auth()->user();
        $user->settings = array_merge($user->settings ?? [], [
            'annual_contribution_eur' => $annual,
        ]);
        $user->save();

        return redirect()->back();
    }
}
