<?php

namespace App\Http\Controllers;

use App\Audit\AuditFilters;
use App\Audit\AuditTrail;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request, AuditTrail $trail): View
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = AuditFilters::fromRequest($request);

        return view('audit.index', [
            'filters' => $filters,
            'events' => $trail->paginate($filters),
            'eventOptions' => $trail->eventOptions(),
            'actorOptions' => $trail->actorOptions(),
            'subjectTypes' => array_keys(AuditFilters::SUBJECT_TYPES),
        ]);
    }

    public function show(AuditLog $audit): View
    {
        Gate::authorize('view', $audit);

        return view('audit.show', ['event' => $audit]);
    }
}
