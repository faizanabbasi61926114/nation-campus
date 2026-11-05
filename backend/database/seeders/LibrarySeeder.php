<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\LibraryBook;
use App\Models\LibraryLoan;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Library demo data: a shared organization catalogue plus campus stock, and a loan
 * history in every state the board can hold — out on loan, overdue, returned with a
 * fine, and written off as lost.
 */
class LibrarySeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        $isb = Campus::firstWhere('code', 'ISB');

        // A Pakistani library lends for a fortnight and charges five rupees a day, per copy.
        Setting::put(1, null, 'library.loan_days', 14);
        Setting::put(1, null, 'library.fine_per_day', 5);
        Setting::put(1, $lhr?->id, 'library.loan_days', 21);
        Setting::put(1, $lhr?->id, 'library.max_loans_per_student', 2);

        $clerk = User::firstWhere('email', 'principal.lhr@sunrise.edu.pk');
        $librarian = Staff::firstWhere('employee_no', 'S-LHR-0007');

        // [campus (null = shared across the whole organization), title, author, isbn, category, language, shelf, copies]
        $catalogue = [
            [$lhr, 'Federal Board Mathematics IX', 'Saeed Ahmed', '978-9992-71002', 'Textbook', 'English', 'TXB-09', 30],
            [$lhr, 'Federal Board Mathematics X', 'Saeed Ahmed', '978-9992-71003', 'Textbook', 'English', 'TXB-10', 28],
            [$lhr, 'Physics (Part I) — Resnick and Halliday', 'David Halliday', '978-8120-34567', 'Reference', 'English', 'REF-02', 6],
            [$lhr, 'Ikhiyal-e-Nau (Urdu Reader)', 'Maulvi Hafiz', '978-9992-88110', 'Textbook', 'Urdu', 'URD-07', 25],
            [$lhr, 'The Diary of a Young Girl', 'Anne Frank', '978-0141-32455', 'Story', 'English', 'STY-12', 3],
            [$lhr, 'Selected Poems of Amjad Islam Amjad', 'Amjad Islam Amjad', '978-3534-10120', 'Story', 'Urdu', 'STY-13', 5],
            [$lhr, 'New Oxford American Dictionary', 'Oxford', '978-0199-88243', 'Reference', 'English', 'REF-04', 2],
            [$lhr, 'Information Technology IX', 'NCB', '978-9663-40011', 'Textbook', 'English', 'TXB-11', 20],
            [$lhr, 'Science Focus', 'Irfan Sheikh', '978-3668-77120', 'Reference', 'English', 'REF-06', 8],
            [$lhr, 'Monthly Momin', 'Haji Muhammad Shafiq', null, 'Magazine', 'Urdu', 'MAG-01', 2],
            [$isb, 'Federal Board Biology IX', 'Saeed Ahmed', '978-9992-71010', 'Textbook', 'English', 'TXB-01', 18],
            [$isb, 'Federal Board Chemistry X', 'Muhammad Yousaf', '978-9992-71020', 'Textbook', 'English', 'TXB-02', 16],
            [$isb, 'The Story of Civilization', 'Will Durant', '978-0375-42210', 'Story', 'English', 'STY-03', 3],
            [$isb, 'Pakistan Studies', 'Ikram', '978-9663-40022', 'Textbook', 'English', 'TXB-03', 22],
            [$isb, 'The Good Earth', 'Pearl Buck', '978-0345-45101', 'Story', 'English', 'STY-05', 4],
            [$isb, 'Oxford Advanced Learners Dictionary', 'Oxford', '978-0194-52410', 'Reference', 'English', 'REF-03', 6],
            [$isb, 'Cambridge Checkpoint Mathematics 8', 'Derek Harris', '978-1107-66080', 'Textbook', 'English', 'TXB-04', 12],
            [$isb, 'Hamlet', 'William Shakespeare', '978-1904-38120', 'Story', 'English', 'STY-10', 3],
            // Shared holdings: no campus row, so every campus can issue them.
            [null, 'Oxford Student Atlas', 'Oxford', '978-0198-32501', 'Reference', 'English', 'GEN-01', 8],
            [null, 'Oxford 3000 Key Words', 'Oxford', null, 'Reference', 'English', 'GEN-03', 12],
            [null, 'Barrons SAT 1600', 'Brain Barron', '978-1438-07201', 'Reference', 'English', 'GEN-05', 6],
            [null, 'The Joy of Chemistry', 'Trevor Milne', null, 'Story', 'English', 'GEN-06', 5],
        ];

        $books = [];
        foreach ($catalogue as $row) {
            [$campus, $title, $author, $isbn, $category, $language, $shelf, $copies] = $row;
            $books[$title] = LibraryBook::firstOrCreate(
                ['organization_id' => 1, 'campus_id' => optional($campus)->id, 'title' => $title],
                ['author' => $author, 'isbn' => $isbn, 'category' => $category, 'language' => $language,
                    'shelf' => $shelf, 'total_copies' => $copies, 'is_active' => true],
            );
        }

        // One withdrawn title and a paper-round magazine to show the shelf filters.
        LibraryBook::firstOrCreate(
            ['organization_id' => 1, 'campus_id' => $lhr?->id, 'title' => 'Old Curriculum Notes 2015'],
            ['author' => 'Internal', 'category' => 'Reference', 'language' => 'English', 'shelf' => 'ARCH-01',
                'total_copies' => 1, 'is_active' => false],
        );

        $this->seedLoans($books, $lhr, $isb, $librarian, $clerk);
    }

    /**
     * [title, days ago issued, loan period, days ago returned (null = still out), fine paid, borrower]
     * borrower is a student index, or the literal "staff" for the librarian.
     */
    private function seedLoans(array $books, $lhr, $isb, ?Staff $librarian, ?User $clerk): void
    {
        $lhrStudents = Student::where('campus_id', $lhr?->id)->where('status', 'active')->orderBy('id')->get();
        $isbStudents = Student::where('campus_id', $isb?->id)->where('status', 'active')->orderBy('id')->get();
        if ($lhrStudents->isEmpty()) return;

        $rows = [
            ['Federal Board Mathematics IX', 92, 14, 70, true, 'student'],
            ['The Diary of a Young Girl', 88, 14, 74, true, 'student'],
            ['Ikhiyal-e-Nau (Urdu Reader)', 84, 21, 60, false, 'student'],
            ['Physics (Part I) — Resnick and Halliday', 78, 30, null, false, 'staff'],
            ['Monthly Momin', 70, 14, null, false, 'student'],
            ['Federal Board Biology IX', 65, 14, null, false, 'student'],
            ['Federal Board Mathematics X', 60, 14, 40, false, 'student'],
            ['The Story of Civilization', 58, 21, null, false, 'student'],
            ['Hamlet', 54, 21, 33, false, 'student'],
            ['New Oxford American Dictionary', 50, 14, 36, false, 'student'],
            ['Selected Poems of Amjad Islam Amjad', 48, 21, null, false, 'staff'],
            ['Science Focus', 46, 21, null, false, 'student'],
            ['Oxford Advanced Learners Dictionary', 44, 21, 23, false, 'student'],
            ['Cambridge Checkpoint Mathematics 8', 40, 30, null, false, 'student'],
            ['The Joy of Chemistry', 38, 30, 8, false, 'student'],
            ['Federal Board Biology IX', 34, 14, 15, true, 'student'],
            ['Oxford Student Atlas', 32, 30, null, false, 'student'],
            ['Pakistan Studies', 30, 14, null, false, 'student'],
            ['Information Technology IX', 28, 21, null, false, 'student'],
            ['The Good Earth', 26, 21, 7, true, 'student'],
            ['Hamlet', 24, 14, 6, true, 'student'],
            ['Federal Board Chemistry X', 22, 21, 1, false, 'student'],
            ['The Diary of a Young Girl', 20, 30, null, false, 'student'],   // lost copy
            ['Barrons SAT 1600', 18, 21, null, false, 'student'],
            ['Federal Board Mathematics IX', 16, 14, null, false, 'student'],
            ['Oxford 3000 Key Words', 14, 14, null, false, 'student'],
            ['Cambridge Checkpoint Mathematics 8', 12, 14, null, false, 'student'],
            ['Monthly Momin', 10, 14, null, false, 'student'],
            ['Selected Poems of Amjad Islam Amjad', 8, 30, null, false, 'student'],
            ['Oxford Advanced Learners Dictionary', 6, 14, null, false, 'student'],
            ['Cambridge Checkpoint Mathematics 8', 4, 21, null, false, 'student'], // lost copy
        ];

        $rate = (float) (Setting::getFor(1, $lhr?->id, 'library.fine_per_day') ?? 5);
        $today = now()->toDateString();
        $cursor = 0;

        foreach ($rows as $k => [$title, $issuedAgo, $period, $returnedAgo, $finePaid, $who]) {
            $book = $books[$title] ?? null;
            if (!$book) continue;
            // Never write a history that breaks the holding the controller enforces.
            if ($book->openLoans()->count() >= (int) $book->total_copies) continue;

            // A reader is always housed on the campus that owns the copy, so the loan and
            // the title stay visible to each other under the campus scope.
            $shared = $book->campus_id === null;
            $pool = $shared || $book->campus_id === $lhr?->id ? $lhrStudents : $isbStudents;
            if ($pool->isEmpty()) continue;

            if ($who === 'staff') {
                $student = null;
                $staff = $librarian;
                if (!$staff || $staff->campus_id !== $pool->first()->campus_id) continue;
                $borrowerCampus = Campus::find($staff->campus_id);
            } else {
                $student = $pool->values()->get(($cursor++) % $pool->count());
                if (!$student) continue;
                $staff = null;
                $borrowerCampus = Campus::find($student->campus_id);
            }

            $issuedOn = date('Y-m-d', strtotime("-$issuedAgo days", strtotime($today)));
            $dueOn = date('Y-m-d', strtotime("+$period days", strtotime($issuedOn)));
            $returnedOn = $returnedAgo === null ? null : date('Y-m-d', strtotime("-$returnedAgo days", strtotime($today)));
            $lost = in_array($k, [22, 30], true);

            // Fines accrue only past the due date: to the return date, or to today if still out.
            $lateUntil = $returnedOn ?? $today;
            $lateDays = $lateUntil > $dueOn ? (int) round((strtotime($lateUntil) - strtotime($dueOn)) / 86400) : 0;
            $fine = round($lateDays * $rate, 2);

            LibraryLoan::firstOrCreate(
                [
                    'library_book_id' => $book->id,
                    'issued_on' => $issuedOn,
                    'student_id' => optional($student)->id,
                    'staff_id' => optional($staff)->id,
                ],
                [
                    'organization_id' => 1,
                    'campus_id' => optional($borrowerCampus)->id ?? $lhr?->id,
                    'copy_no' => 1 + ($k % max(1, min(4, (int) $book->total_copies))),
                    'issued_by' => $clerk?->id,
                    'due_on' => $dueOn,
                    'returned_on' => $returnedOn,
                    'fine_amount' => $fine,
                    'fine_paid' => $returnedOn ? (bool) $finePaid : false,
                    'status' => $returnedOn ? 'returned' : ($lost ? 'lost' : 'borrowed'),
                    'notes' => $lost ? 'Not returned after the session; written off and replaced.' : null,
                ],
            );
        }
    }
}
