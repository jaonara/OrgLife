<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationReportController extends Controller
{
    public function store(Request $request, Organization $organization)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $report = $organization->reports()->create([
            'reporter_id' => $request->user()->id,
            'reason' => trim($validated['reason']),
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Your organization report has been submitted for review.',
            'data' => ['id' => $report->id, 'status' => $report->status],
        ], 201);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->is_admin, 403, 'Only admins can review organization reports.');

        return response()->json([
            'data' => OrganizationReport::query()
                ->with([
                    'organization:id,name,college',
                    'reporter:id,name,email',
                    'reviewer:id,name',
                ])
                ->latest()
                ->get()
                ->map(fn (OrganizationReport $report) => $this->reportData($report)),
        ]);
    }

    public function update(Request $request, OrganizationReport $report)
    {
        abort_unless($request->user()->is_admin, 403, 'Only admins can review organization reports.');

        $validated = $request->validate([
            'status' => ['required', Rule::in(['resolved', 'dismissed'])],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $report->update([
            ...$validated,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Organization report '.$validated['status'].'.',
            'data' => $this->reportData($report->fresh()->load([
                'organization:id,name,college',
                'reporter:id,name,email',
                'reviewer:id,name',
            ])),
        ]);
    }

    private function reportData(OrganizationReport $report): array
    {
        return [
            'id' => $report->id,
            'reason' => $report->reason,
            'status' => $report->status,
            'resolution_note' => $report->resolution_note,
            'created_at' => $report->created_at,
            'reviewed_at' => $report->reviewed_at,
            'organization' => [
                'id' => $report->organization->id,
                'name' => $report->organization->name,
                'college' => $report->organization->college,
            ],
            'reporter' => [
                'id' => $report->reporter->id,
                'name' => $report->reporter->name,
                'email' => $report->reporter->email,
            ],
            'reviewer' => $report->reviewer ? ['name' => $report->reviewer->name] : null,
        ];
    }
}
