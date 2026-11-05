<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $q = AuditLog::with('user:id,name,email')->where('organization_id', $ctx->organizationId);
        if ($ctx->campusId()) $q->where('campus_id', $ctx->campusId());
        if ($request->filled('action')) $q->where('action', $request->input('action'));
        if ($request->filled('user_id')) $q->where('user_id', $request->integer('user_id'));
        if ($request->filled('from')) $q->whereDate('created_at', '>=', $request->input('from'));
        if ($request->filled('to')) $q->whereDate('created_at', '<=', $request->input('to'));
        return response()->json($q->latest('id')->paginate(min(200, (int) $request->input('per_page', 50))));
    }
}
