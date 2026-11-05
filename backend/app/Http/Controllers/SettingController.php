<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $campusId = $request->input('campus_id') ? (int) $request->input('campus_id') : $ctx->campusId();
        $rows = Setting::where('organization_id', $ctx->organizationId)
            ->where(function ($q) use ($campusId) {
                if ($campusId) $q->where('campus_id', $campusId);
                $q->orWhereNull('campus_id');
            })
            ->get()
            ->keyBy('key');
        // campus value overrides org value
        $out = [];
        foreach ($rows as $key => $row) {
            if (!isset($out[$key]) || $row->campus_id === $campusId) {
                $out[$key] = ['value' => $row->value, 'scope' => $row->campus_id ? 'campus' : 'organization'];
            }
        }
        return response()->json(['data' => (object) $out]);
    }

    public function update(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', 'max:128'],
            'settings.*.value' => ['present'],
        ]);
        $campusId = $data['campus_id'] ?? $ctx->campusId();
        if ($campusId) {
            abort_unless(in_array($campusId, $request->user()->accessibleCampusIds(), true), 403);
        } elseif (!$request->user()->hasPermission('settings.manage')) {
            // org-wide defaults require head-office settings.manage
            abort(403, 'Use campus_id to change campus-level overrides, or obtain settings.manage.');
        }
        DB::transaction(function () use ($ctx, $campusId, $data) {
            foreach ($data['settings'] as $s) {
                Setting::put($ctx->organizationId, $campusId, $s['key'], $s['value']);
            }
        });
        return response()->json(['message' => 'Settings saved']);
    }
}
