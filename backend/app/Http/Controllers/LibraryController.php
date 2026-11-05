<?php

namespace App\Http\Controllers;

use App\Models\LibraryBook;
use App\Models\LibraryLoan;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Student;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Library — a title belongs to the organization; a null campus_id means the copies are
 * shared across every campus. Copies go out to a student or a staff member and come back
 * with an overdue fine measured per day against the campus setting.
 */
class LibraryController extends Controller
{
    public function summary(): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $today = now()->toDateString();

        return response()->json(['data' => [
            'titles' => LibraryBook::count(),
            'copies' => (int) LibraryBook::sum('total_copies'),
            'on_loan' => LibraryLoan::where('status', 'borrowed')->count(),
            'overdue' => LibraryLoan::where('status', 'borrowed')->whereDate('due_on', '<', $today)->count(),
            'lost' => LibraryLoan::where('status', 'lost')->count(),
            // Recovery still owed: assessed when a copy came back or was written off, plus
            // what the copies that are still out past their due date have accrued.
            'fines_outstanding' => round((float) LibraryLoan::where('fine_paid', false)->sum('fine_amount'), 2),
            'fine_per_day' => (float) (Setting::getFor($ctx->organizationId, $ctx->campusId(), 'library.fine_per_day', 5)),
        ]]);
    }

    // ---------------------------------------------------------------- catalogue

    public function books(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:80',
            'category' => 'nullable|string|max:48',
            'status' => 'nullable|in:active,inactive',
        ]);

        $books = LibraryBook::withCount('openLoans')
            ->when($data['search'] ?? null, function ($q, $s) {
                $q->where(fn ($w) => $w->where('title', 'like', "%$s%")->orWhere('author', 'like', "%$s%")->orWhere('isbn', 'like', "%$s%"));
            })
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->orderBy('title')
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        $books->getCollection()->transform(function ($b) {
            $b->on_loan = (int) $b->open_loans_count;
            $b->available_copies = max(0, (int) $b->total_copies - $b->on_loan);
            $b->setVisible(['id', 'campus_id', 'title', 'author', 'isbn', 'category', 'language', 'shelf',
                'total_copies', 'on_loan', 'available_copies', 'is_active']);
            return $b;
        });

        return response()->json(['data' => $books]);
    }

    public function storeBook(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'author' => 'nullable|string|max:120',
            'isbn' => 'nullable|string|max:32',
            'category' => 'nullable|string|max:48',
            'language' => 'nullable|string|max:24',
            'shelf' => 'nullable|string|max:24',
            'total_copies' => 'required|integer|min:1|max:2000',
            'is_active' => 'nullable|boolean',
            // Null keeps the title shared across the organization; head office may also
            // catalogue on behalf of one campus.
            'campus_id' => 'nullable|integer',
        ]);
        $data['campus_id'] = $this->resolveCampus($ctx, $data['campus_id'] ?? null);

        if (LibraryBook::whereRaw('lower(title) = ?', [strtolower($data['title'])])->exists()) {
            return response()->json(['message' => 'That title is already in the catalogue.'], 422);
        }

        $book = LibraryBook::create($data + ['organization_id' => $ctx->organizationId]);
        return response()->json(['data' => $book], 201);
    }

    public function updateBook(Request $request, LibraryBook $book): JsonResponse
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:150',
            'author' => 'nullable|string|max:120',
            'isbn' => 'nullable|string|max:32',
            'category' => 'nullable|string|max:48',
            'language' => 'nullable|string|max:24',
            'shelf' => 'nullable|string|max:24',
            'total_copies' => 'sometimes|required|integer|min:1|max:2000',
            'is_active' => 'sometimes|boolean',
        ]);

        // The holding may never drop below the copies already out with readers.
        if (isset($data['total_copies'])) {
            $out = $book->openLoans()->count();
            if ((int) $data['total_copies'] < $out) {
                return response()->json(['message' => "This title has $out copy(s) still out; the holding cannot drop below that."], 422);
            }
        }

        $book->update($data);
        return response()->json(['data' => $book->fresh()]);
    }

    public function destroyBook(LibraryBook $book): JsonResponse
    {
        $out = $book->openLoans()->count();
        if ($out) {
            return response()->json(['message' => "Return the $out loan(s) on \"{$book->title}\" before removing it."], 422);
        }
        $book->delete();
        return response()->json(['message' => 'Removed ' . $book->title . ' from the catalogue.']);
    }

    // ---------------------------------------------------------------- loans

    public function loans(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|in:active,overdue,returned,lost',
            'student_id' => 'nullable|integer',
            'staff_id' => 'nullable|integer',
            'library_book_id' => 'nullable|integer',
        ]);

        $loans = LibraryLoan::with([
            'book:id,title,author,total_copies',
            'student:id,first_name,last_name,roll_no',
            'staff:id,first_name,last_name,employee_no',
            'issuer:id,name',
        ])
            ->when($data['status'] ?? null, function ($q, $s) {
                match ($s) {
                    'active' => $q->where('status', 'borrowed'),
                    'overdue' => $q->overdue(),
                    default => $q->where('status', $s),
                };
            })
            ->when($data['student_id'] ?? null, fn ($q, $i) => $q->where('student_id', $i))
            ->when($data['staff_id'] ?? null, fn ($q, $i) => $q->where('staff_id', $i))
            ->when($data['library_book_id'] ?? null, fn ($q, $i) => $q->where('library_book_id', $i))
            ->orderByRaw('returned_on is null desc')
            ->latest('issued_on')
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        $loans->getCollection()->transform(function ($l) {
            $l->days_late = $l->daysLate();
            $l->append(['borrower_name', 'is_overdue']);
            $l->setVisible(['id', 'library_book_id', 'book', 'copy_no', 'student_id', 'staff_id', 'borrower_name',
                'issued_on', 'due_on', 'returned_on', 'days_late', 'is_overdue',
                'fine_amount', 'fine_paid', 'status', 'notes', 'issued_by', 'student', 'staff']);
            return $l;
        });

        return response()->json(['data' => $loans]);
    }

    public function issue(Request $request): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'library_book_id' => 'required|integer',
            'student_id' => 'required_without:staff_id|nullable|integer',
            'staff_id' => 'required_without:student_id|nullable|integer',
            'copy_no' => 'nullable|integer|min:1',
            'issued_on' => 'nullable|date',
            'due_on' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);
        if (($data['student_id'] ?? null) && ($data['staff_id'] ?? null)) {
            return response()->json(['message' => 'Issue to a student or a staff member, not both.'], 422);
        }

        $book = LibraryBook::find($data['library_book_id']);
        if (!$book) return response()->json(['message' => 'Book not found in your campus.'], 422);
        if (!$book->is_active) return response()->json(['message' => "\"{$book->title}\" is withdrawn from the catalogue."], 422);

        // A shared (null campus) title may still be issued, but the reader must belong here.
        if ($data['student_id'] ?? null) {
            $student = Student::find($data['student_id']);
            if (!$student) return response()->json(['message' => 'Student does not exist in your campus.'], 422);
        } else {
            $staff = Staff::find($data['staff_id']);
            if (!$staff) return response()->json(['message' => 'Staff member does not exist in your campus.'], 422);
        }

        $out = $book->openLoans()->count();
        if ($out >= (int) $book->total_copies) {
            return response()->json([
                'message' => "All {$book->total_copies} copy(s) of \"{$book->title}\" are already out.",
            ], 422);
        }

        $issuedOn = Carbon::parse($data['issued_on'] ?? now()->toDateString())->toDateString();
        $loanDays = (int) (Setting::getFor($ctx->organizationId, $ctx->campusId(), 'library.loan_days', 14));
        $dueOn = isset($data['due_on']) && $data['due_on']
            ? Carbon::parse($data['due_on'])->toDateString()
            : Carbon::parse($issuedOn)->addDays(max(1, $loanDays))->toDateString();
        if (Carbon::parse($dueOn)->lt(Carbon::parse($issuedOn))) {
            return response()->json(['message' => 'The due date cannot be before the issue date.'], 422);
        }

        $copyNo = $this->pickCopy($book, $data['copy_no'] ?? null);
        if (is_string($copyNo)) return response()->json(['message' => $copyNo], 422);

        $loan = DB::transaction(function () use ($book, $data, $ctx, $request, $issuedOn, $dueOn, $copyNo) {
            return LibraryLoan::create([
                'organization_id' => $ctx->organizationId,
                'campus_id' => $ctx->campusId(),
                'library_book_id' => $book->id,
                'copy_no' => $copyNo,
                'student_id' => $data['student_id'] ?? null,
                'staff_id' => $data['staff_id'] ?? null,
                'issued_by' => $request->user()->id,
                'issued_on' => $issuedOn,
                'due_on' => $dueOn,
                'status' => 'borrowed',
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return response()->json([
            'message' => "\"{$book->title}\" issued to " . $loan->borrower_name . ", due " . $dueOn . '.',
            'data' => $this->loanPayload($loan->load(['book:id,title,total_copies', 'student:id,first_name,last_name,roll_no', 'staff:id,first_name,last_name,employee_no'])),
        ], 201);
    }

    public function returnCopy(Request $request, LibraryLoan $loan): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate([
            'returned_on' => 'nullable|date|before_or_equal:today',
            'fine_amount' => 'nullable|numeric|min:0',
            'fine_paid' => 'nullable|boolean',
        ]);
        if ($loan->status === 'returned') {
            return response()->json(['message' => 'This copy is already returned.'], 422);
        }

        $returnedOn = Carbon::parse($data['returned_on'] ?? now()->toDateString())->toDateString();
        $rate = (float) (Setting::getFor($ctx->organizationId, $ctx->campusId(), 'library.fine_per_day', 5));
        $daysLate = $loan->daysLate($returnedOn);
        $fine = array_key_exists('fine_amount', $data) && $data['fine_amount'] !== null
            ? round((float) $data['fine_amount'], 2)
            : round($daysLate * $rate, 2);

        $loan->update([
            'status' => 'returned',
            'returned_on' => $returnedOn,
            'fine_amount' => $fine,
            'fine_paid' => $data['fine_paid'] ?? $loan->fine_paid,
        ]);

        $message = $daysLate > 0 && $fine > 0
            ? "Returned $daysLate day(s) late — fine Rs " . number_format($fine, 2) . '.'
            : 'Copy returned, no fine.';

        return response()->json(['message' => $message, 'data' => $this->loanPayload($loan->fresh())]);
    }

    public function markLost(Request $request, LibraryLoan $loan): JsonResponse
    {
        $ctx = app(TenantContext::class);
        $data = $request->validate(['notes' => 'nullable|string|max:500']);
        if ($loan->status === 'returned') {
            return response()->json(['message' => 'A returned copy cannot be marked lost.'], 422);
        }

        // The copy stays on the books against total_copies; the fine accrued so far stands.
        $rate = (float) (Setting::getFor($ctx->organizationId, $ctx->campusId(), 'library.fine_per_day', 5));
        $accrued = round($loan->daysLate() * $rate, 2);

        $loan->update([
            'status' => 'lost',
            'fine_amount' => max((float) $loan->fine_amount, $accrued),
            'notes' => $data['notes'] ?? $loan->notes,
        ]);

        return response()->json([
            'message' => "\"{$loan->book?->title}\" marked lost; the copy still counts against the holding.",
            'data' => $this->loanPayload($loan->fresh()),
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** Lowest free copy number, or an error message when the requested copy is already out. */
    private function pickCopy(LibraryBook $book, ?int $requested): int|string
    {
        $taken = $book->openLoans()->pluck('copy_no')->map(fn ($c) => (int) $c)->all();
        if ($requested) {
            if ($requested > (int) $book->total_copies) {
                return "Copy $requested does not exist — this title has {$book->total_copies} copy(s).";
            }
            if (in_array($requested, $taken, true)) {
                return "Copy $requested of \"{$book->title}\" is already out.";
            }
            return $requested;
        }
        for ($c = 1; $c <= (int) $book->total_copies; $c++) {
            if (!in_array($c, $taken, true)) return $c;
        }
        return "All {$book->total_copies} copy(s) of \"{$book->title}\" are already out.";
    }

    /** One loan exactly as the loans table and the issue dialog need to see it. */
    private function loanPayload(LibraryLoan $loan): array
    {
        $loan->days_late = $loan->daysLate();
        $loan->append(['borrower_name', 'is_overdue']);
        return $loan->setVisible(['id', 'library_book_id', 'book', 'copy_no', 'student_id', 'staff_id', 'borrower_name',
            'issued_on', 'due_on', 'returned_on', 'days_late', 'is_overdue', 'fine_amount', 'fine_paid',
            'status', 'notes'])->toArray();
    }

    /** Head office may catalogue for a named campus; a campus user is pinned to their own. */
    private function resolveCampus(TenantContext $ctx, ?int $requested): ?int
    {
        if ($ctx->campusId()) return $ctx->campusId();
        if (!$requested) return null;
        $valid = DB::table('campuses')->where('organization_id', $ctx->organizationId)->where('id', $requested)->exists();
        return $valid ? $requested : null;
    }
}
