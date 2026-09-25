<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('audit.view');

        return view('admin.activity.index', [
            'logs' => ActivityLog::query()
                ->with('user')
                ->when($request->filled('module'), fn ($q) => $q->where('module', $request->string('module')))
                ->when($request->filled('q'), function ($q) use ($request) {
                    $term = '%' . trim($request->string('q')->toString()) . '%';

                    $q->where(fn ($inner) => $inner
                        ->where('action', 'like', $term)
                        ->orWhere('description', 'like', $term));
                })
                ->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->integer('user')))
                ->latest()
                ->paginate(40)
                ->withQueryString(),
            'modules' => ActivityLog::query()->whereNotNull('module')->distinct()->orderBy('module')->pluck('module'),
            'users' => \App\Models\User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
