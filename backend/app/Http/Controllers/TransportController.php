<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentTransport;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\TransportVehicle;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransportController extends Controller
{
    // ---- Vehicles -------------------------------------------------------

    public function vehicles(Request $request): JsonResponse
    {
        $vehicles = TransportVehicle::withCount(['routes'])
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $vehicles]);
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus before adding a vehicle.'], 422);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', Rule::unique('transport_vehicles')->where('campus_id', $ctx->campusId())],
            'plate_no' => 'nullable|string|max:24',
            'type' => ['required', Rule::in(TransportVehicle::TYPES)],
            'capacity' => 'required|integer|min:1|max:120',
            'driver_name' => 'nullable|string|max:120',
            'driver_phone' => 'nullable|string|max:24',
            'driver_cnic' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        $vehicle = TransportVehicle::create($data + [
            'organization_id' => $ctx->organizationId,
            'campus_id' => $ctx->campusId(),
        ]);

        return response()->json(['data' => $vehicle], 201);
    }

    public function updateVehicle(Request $request, TransportVehicle $vehicle): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:16', Rule::unique('transport_vehicles')->where('campus_id', $vehicle->campus_id)->ignore($vehicle->id)],
            'plate_no' => 'sometimes|nullable|string|max:24',
            'type' => ['sometimes', 'required', Rule::in(TransportVehicle::TYPES)],
            'capacity' => 'sometimes|required|integer|min:1|max:120',
            'driver_name' => 'sometimes|nullable|string|max:120',
            'driver_phone' => 'sometimes|nullable|string|max:24',
            'driver_cnic' => 'sometimes|nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        // Never shrink a vehicle below the students already riding it.
        if (array_key_exists('capacity', $data)) {
            $carried = $vehicle->routes()->whereHas('studentTransports', fn ($q) => $q->where('status', 'active'))->get()
                ->sum(fn ($r) => $r->studentTransports()->where('status', 'active')->count());
            if ((int) $data['capacity'] < $carried) {
                return response()->json(['message' => "Vehicle {$vehicle->code} seats {$data['capacity']} but {$carried} students ride it today."], 422);
            }
        }

        $vehicle->update($data);
        return response()->json(['data' => $vehicle->fresh()]);
    }

    // ---- Routes ---------------------------------------------------------

    public function routes(Request $request): JsonResponse
    {
        $rows = TransportRoute::query()
            ->with(['vehicle:id,code,type,capacity,driver_name,driver_phone', 'stops'])
            ->withCount(['studentTransports as active_students_count' => fn ($q) => $q->where('status', 'active')])
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function storeRoute(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus before adding a route.'], 422);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => ['required', 'string', 'max:16', Rule::unique('transport_routes')->where('campus_id', $ctx->campusId())],
            'transport_vehicle_id' => 'nullable|integer',
            'pickup_time' => 'nullable|date_format:H:i',
            'drop_time' => 'nullable|date_format:H:i',
            'monthly_fee' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        if (!empty($data['transport_vehicle_id'])) {
            $vehicle = TransportVehicle::find($data['transport_vehicle_id']);
            if (!$vehicle || (int) $vehicle->campus_id !== (int) $ctx->campusId()) {
                return response()->json(['message' => 'The selected vehicle does not belong to this campus.'], 422);
            }
        }

        $route = TransportRoute::create($data + [
            'organization_id' => $ctx->organizationId,
            'campus_id' => $ctx->campusId(),
        ]);

        return response()->json(['data' => $route->load(['vehicle:id,code,type,capacity', 'stops'])], 201);
    }

    public function updateRoute(Request $request, TransportRoute $route): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'code' => ['sometimes', 'required', 'string', 'max:16', Rule::unique('transport_routes')->where('campus_id', $route->campus_id)->ignore($route->id)],
            'transport_vehicle_id' => 'sometimes|nullable|integer',
            'pickup_time' => 'sometimes|nullable|date_format:H:i',
            'drop_time' => 'sometimes|nullable|date_format:H:i',
            'monthly_fee' => 'sometimes|nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string|max:1000',
        ]);

        if (array_key_exists('transport_vehicle_id', $data) && $data['transport_vehicle_id']) {
            $vehicle = TransportVehicle::find($data['transport_vehicle_id']);
            if (!$vehicle || (int) $vehicle->campus_id !== (int) $route->campus_id) {
                return response()->json(['message' => 'The selected vehicle does not belong to this campus.'], 422);
            }
            $carried = $route->studentTransports()->where('status', 'active')->count();
            if ($carried > (int) $vehicle->capacity) {
                return response()->json([
                    'message' => "Route {$route->code} carries {$carried} students but {$vehicle->code} seats {$vehicle->capacity}.",
                ], 422);
            }
        }

        $route->update($data);
        return response()->json(['data' => $route->fresh(['vehicle:id,code,type,capacity', 'stops'])]);
    }

    public function destroyRoute(TransportRoute $route): JsonResponse
    {
        $active = $route->studentTransports()->where('status', 'active')->count();
        if ($active > 0) {
            return response()->json([
                'message' => "Route {$route->code} still has {$active} active students. Discontinue their assignments before deleting it.",
            ], 422);
        }
        $route->delete();
        return response()->json(['message' => 'Route removed']);
    }

    // ---- Stops ----------------------------------------------------------

    public function storeStop(Request $request, TransportRoute $route): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'landmark' => 'nullable|string|max:120',
            'order_no' => 'required|integer|min:1|max:255',
            'pickup_time' => 'nullable|date_format:H:i',
            'drop_time' => 'nullable|date_format:H:i',
        ]);

        $dupe = TransportStop::where('transport_route_id', $route->id)->where('order_no', $data['order_no'])->exists();
        if ($dupe) {
            return response()->json(['message' => "Stop number {$data['order_no']} already exists on route {$route->code}."], 422);
        }

        $stop = TransportStop::create($data + ['transport_route_id' => $route->id]);
        return response()->json(['data' => $stop], 201);
    }

    public function updateStop(Request $request, TransportStop $stop): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'landmark' => 'sometimes|nullable|string|max:120',
            'order_no' => 'sometimes|required|integer|min:1|max:255',
            'pickup_time' => 'sometimes|nullable|date_format:H:i',
            'drop_time' => 'sometimes|nullable|date_format:H:i',
        ]);

        $order = $data['order_no'] ?? $stop->order_no;
        $dupe = TransportStop::where('transport_route_id', $stop->transport_route_id)
            ->where('order_no', $order)->where('id', '!=', $stop->id)->exists();
        if ($dupe) {
            return response()->json(['message' => "Stop number {$order} already exists on this route."], 422);
        }

        $stop->update($data);
        return response()->json(['data' => $stop->fresh()]);
    }

    public function destroyStop(TransportStop $stop): JsonResponse
    {
        // Deleting a stop would silently orphan the riders who expect pickup there,
        // so refuse until they are reassigned or discontinued.
        $riders = StudentTransport::where('transport_stop_id', $stop->id)->where('status', 'active')->count();
        if ($riders > 0) {
            return response()->json([
                'message' => "{$riders} students are assigned to stop \"{$stop->name}\". Reassign or discontinue them before removing it.",
            ], 422);
        }
        $stop->delete();
        return response()->json(['message' => 'Stop removed']);
    }

    // ---- Assignments ----------------------------------------------------

    public function assignments(Request $request): JsonResponse
    {
        $q = StudentTransport::with([
            'student:id,first_name,last_name,admission_no,class_id,section_id',
            'student.schoolClass:id,name',
            'student.section:id,name',
            'route:id,name,code',
            'stop:id,name',
        ]);

        if ($request->filled('transport_route_id')) $q->where('transport_route_id', $request->integer('transport_route_id'));
        if ($request->filled('student_id')) $q->where('student_id', $request->integer('student_id'));
        if ($request->filled('status')) $q->where('status', $request->input('status'));

        return response()->json(['data' => $q->latest('id')->limit(300)->get()]);
    }

    public function assign(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        if (!$ctx->campusId()) return response()->json(['message' => 'Select a campus before assigning a student to transport.'], 422);

        $data = $request->validate([
            'student_id' => 'required|integer',
            'transport_route_id' => 'required|integer|exists:transport_routes,id',
            'transport_stop_id' => 'nullable|integer',
            'kind' => ['required', Rule::in(StudentTransport::KINDS)],
            'from_date' => 'nullable|date',
        ]);

        $student = Student::find($data['student_id']);
        if (!$student) return response()->json(['message' => 'Student not found in your campus.'], 422);
        if ((int) $student->campus_id !== (int) $ctx->campusId()) {
            return response()->json(['message' => 'That student belongs to another campus.'], 422);
        }
        if ($student->status !== 'active') {
            return response()->json(['message' => 'Only active students can be assigned to transport.'], 422);
        }

        $route = TransportRoute::find($data['transport_route_id']);
        if (!$route || (int) $route->campus_id !== (int) $ctx->campusId()) {
            return response()->json(['message' => 'Route not found in your campus.'], 422);
        }

        if (!empty($data['transport_stop_id'])) {
            $stop = TransportStop::find($data['transport_stop_id']);
            if (!$stop || (int) $stop->transport_route_id !== (int) $route->id) {
                return response()->json(['message' => 'The selected stop does not belong to this route.'], 422);
            }
        }

        // One active route per student.
        $existing = StudentTransport::where('student_id', $student->id)->where('status', 'active')->with('route:id,name,code')->get();
        $onThisRoute = $existing->firstWhere('transport_route_id', $route->id);
        if ($onThisRoute) {
            return response()->json(['message' => "This student is already on route {$route->code}."], 422);
        }
        $other = $existing->first(fn ($a) => (int) $a->transport_route_id !== (int) $route->id);
        if ($other) {
            return response()->json([
                'message' => "This student is already on route {$other->route->name} ({$other->route->code}). Discontinue that assignment first.",
            ], 422);
        }

        // Capacity of the assigned vehicle.
        if ($route->vehicle) {
            $carried = $route->studentTransports()->where('status', 'active')->count();
            if ($carried + 1 > (int) $route->vehicle->capacity) {
                return response()->json([
                    'message' => "Route {$route->code} carries " . ($carried + 1) . " students but {$route->vehicle->code} seats {$route->vehicle->capacity}.",
                ], 422);
            }
        }

        $assignment = StudentTransport::create($data + [
            'organization_id' => $ctx->organizationId,
            'campus_id' => $ctx->campusId(),
            'status' => 'active',
        ]);

        return response()->json(['data' => $assignment->load([
            'student:id,first_name,last_name,admission_no',
            'route:id,name,code',
            'stop:id,name',
        ])], 201);
    }

    public function discontinue(StudentTransport $assignment): JsonResponse
    {
        if ($assignment->status !== 'active') {
            return response()->json(['message' => 'This assignment is already discontinued.'], 422);
        }
        $assignment->update(['status' => 'discontinued', 'to_date' => now()->toDateString()]);
        return response()->json(['data' => $assignment->fresh([
            'student:id,first_name,last_name,admission_no',
            'route:id,name,code',
            'stop:id,name',
        ])]);
    }

    // ---- Summary --------------------------------------------------------

    public function summary(): JsonResponse
    {
        $routes = TransportRoute::with('vehicle:id,code,capacity')->get();
        $carried = StudentTransport::where('status', 'active')
            ->selectRaw('transport_route_id, count(*) c')->groupBy('transport_route_id')->pluck('c', 'transport_route_id');

        $loads = $routes->map(function ($r) use ($carried) {
            $students = (int) $carried->get($r->id, 0);
            $capacity = (int) ($r->vehicle?->capacity ?? 0);
            return [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'students' => $students,
                'capacity' => $capacity,
                'vehicle' => $r->vehicle?->code,
                'overloaded' => $capacity > 0 && $students > $capacity,
            ];
        })->values();

        $activeVehicles = TransportVehicle::where('is_active', true)->get();

        return response()->json(['data' => [
            'routes' => $routes->count(),
            'active_students' => (int) $carried->sum(),
            'vehicles' => $activeVehicles->count(),
            'seats' => (int) $activeVehicles->sum('capacity'),
            'monthly_fee_total' => round((float) TransportRoute::where('is_active', true)->sum('monthly_fee'), 2),
            'route_loads' => $loads,
        ]]);
    }
}
