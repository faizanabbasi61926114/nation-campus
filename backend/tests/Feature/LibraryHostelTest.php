<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\HostelAllotment;
use App\Models\HostelBlock;
use App\Models\HostelRoom;
use App\Models\LibraryBook;
use App\Models\LibraryLoan;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibraryHostelTest extends TestCase
{
    use RefreshDatabase;

    private string $principal;  // library.manage + hostel.manage, campus LHR
    private string $admin;      // super admin, head office (no campus)
    private string $accounts;   // library.view + hostel.view only, campus LHR
    private int $lhr;
    private int $isb;
    private LibraryBook $book;  // 2 copies, LHR
    private LibraryBook $shared; // 1 copy, organization-wide
    private Student $ali;       // male, LHR
    private Student $zara;      // female, LHR
    private Student $usama;     // male, LHR
    private Student $hira;      // female, LHR
    private Staff $librarian;
    private HostelBlock $boys;
    private HostelBlock $girls;
    private HostelRoom $h1;     // 2 beds
    private HostelRoom $g1;     // 3 beds
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);

        $login = function (string $email): string {
            $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123!'])->json('token');
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            return $token;
        };
        $this->principal = $login('principal.lhr@sunrise.edu.pk');
        $this->admin = $login('admin@nationcampus.edu.pk');
        $this->accounts = $login('accounts.lhr@sunrise.edu.pk');

        $this->lhr = Campus::firstWhere('code', 'LHR')->id;
        $this->isb = Campus::firstWhere('code', 'ISB')->id;

        $this->book = LibraryBook::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'title' => 'Federal Board Mathematics IX',
            'author' => 'Saeed Ahmed', 'category' => 'Textbook', 'total_copies' => 2,
        ]);
        $this->shared = LibraryBook::create([
            'organization_id' => 1, 'campus_id' => null, 'title' => 'Oxford Student Atlas',
            'author' => 'Oxford', 'category' => 'Reference', 'total_copies' => 1,
        ]);

        $this->ali = $this->makeStudent('Ali', 'Khan', 'male', $this->lhr);
        $this->zara = $this->makeStudent('Zara', 'Sheikh', 'female', $this->lhr);
        $this->usama = $this->makeStudent('Usama', 'Raza', 'male', $this->lhr);
        $this->hira = $this->makeStudent('Hira', 'Noor', 'female', $this->lhr);

        $this->librarian = Staff::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'employee_no' => 'T-LHR-0001',
            'first_name' => 'Zubaida', 'last_name' => 'Ali', 'gender' => 'female',
            'joined_on' => '2018-07-16', 'base_salary' => 51000, 'status' => 'active',
        ]);

        $this->boys = HostelBlock::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'Jinnah House',
            'gender' => 'boys', 'warden_user_id' => null, 'is_active' => true,
        ]);
        $this->h1 = HostelRoom::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'hostel_block_id' => $this->boys->id,
            'number' => 'H-1', 'floor' => 'Ground', 'capacity' => 2, 'monthly_fee' => 22000,
        ]);
        $this->girls = HostelBlock::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'name' => 'Fatima House',
            'gender' => 'girls', 'is_active' => true,
        ]);
        $this->g1 = HostelRoom::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'hostel_block_id' => $this->girls->id,
            'number' => 'G-1', 'capacity' => 3, 'monthly_fee' => 20000,
        ]);
    }

    private function as(string $token, ?int $campus = null): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);
        if ($campus) $this->withHeaders(['X-Campus-Id' => $campus]);
        return $this;
    }

    private function makeStudent(string $first, string $last, string $gender, int $campusId): Student
    {
        return Student::create([
            'organization_id' => 1, 'campus_id' => $campusId,
            'admission_no' => 'T-' . ++$this->seq, 'roll_no' => '26-' . sprintf('%03d', $this->seq),
            'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
            'dob' => '2011-03-14', 'father_name' => 'Muhammad ' . $last,
            'class_id' => null, 'status' => 'active',
        ]);
    }

    // ------------------------------------------------------------------ library

    public function test_issuing_copies_tracks_availability_and_blocks_the_last_copy(): void
    {
        $this->as($this->principal)->getJson('/api/v1/library/books')
            ->assertOk()
            ->assertJsonPath('data.data.0.title', 'Federal Board Mathematics IX')
            ->assertJsonPath('data.data.0.on_loan', 0)
            ->assertJsonPath('data.data.0.available_copies', 2);

        // Default period comes from the library.loan_days setting (14 days).
        $this->as($this->principal)->postJson('/api/v1/library/loans', [
            'library_book_id' => $this->book->id, 'student_id' => $this->ali->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'borrowed')
            ->assertJsonPath('data.copy_no', 1)
            ->assertJsonPath('data.due_on', '2026-11-04')
            ->assertJsonPath('data.borrower_name', 'Ali Khan');

        $this->as($this->principal)->getJson('/api/v1/library/summary')->assertOk()
            ->assertJsonPath('data.copies', 3)          // 2 campus copies + 1 shared atlas
            ->assertJsonPath('data.on_loan', 1)
            ->assertJsonPath('data.overdue', 0);

        $this->as($this->principal)->postJson('/api/v1/library/loans', [
            'library_book_id' => $this->book->id, 'staff_id' => $this->librarian->id,
        ])->assertCreated()->assertJsonPath('data.copy_no', 2);

        // Both copies are out — the third issue is refused.
        $this->as($this->principal)->postJson('/api/v1/library/loans', [
            'library_book_id' => $this->book->id, 'student_id' => $this->zara->id,
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'All 2 copy(s) of "Federal Board Mathematics IX" are already out.']);

        $this->as($this->principal)->getJson('/api/v1/library/books')->assertOk()
            ->assertJsonPath('data.data.0.on_loan', 2)
            ->assertJsonPath('data.data.0.available_copies', 0);

        // A student from another campus cannot borrow here.
        $isbStudent = Student::create([
            'organization_id' => 1, 'campus_id' => $this->isb, 'admission_no' => 'T-ISB-1',
            'first_name' => 'Bilal', 'last_name' => 'Ahmed', 'gender' => 'male',
            'dob' => '2011-08-01', 'father_name' => 'Muhammad Ahmed', 'status' => 'active',
        ]);
        $this->as($this->principal)->postJson('/api/v1/library/loans', [
            'library_book_id' => $this->book->id, 'student_id' => $isbStudent->id,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Student does not exist in your campus.']);
    }

    public function test_the_campus_setting_overrides_the_default_loan_period(): void
    {
        Setting::put(1, $this->lhr, 'library.loan_days', 21);
        $this->as($this->principal)->postJson('/api/v1/library/loans', [
            'library_book_id' => $this->book->id, 'student_id' => $this->ali->id,
        ])->assertCreated()->assertJsonPath('data.due_on', '2026-11-11');
    }

    public function test_returning_computes_the_overdue_fine_at_the_configured_daily_rate(): void
    {
        $loan = LibraryLoan::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'library_book_id' => $this->book->id,
            'copy_no' => 1, 'student_id' => $this->ali->id, 'issued_on' => '2026-10-01',
            'due_on' => '2026-10-11', 'status' => 'borrowed',
        ]);

        Setting::put(1, $this->lhr, 'library.fine_per_day', 8);

        // 2026-10-21 is ten days past the due date: 10 x Rs 8.
        $this->as($this->principal)->postJson("/api/v1/library/loans/{$loan->id}/return")
            ->assertOk()
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.returned_on', '2026-10-21')
            ->assertJsonPath('data.days_late', 10);
        $this->assertSame(80.0, (float) LibraryLoan::find($loan->id)->fine_amount);

        $this->as($this->principal)->getJson('/api/v1/library/summary')->assertOk()
            ->assertJsonPath('data.fines_outstanding', 80)
            ->assertJsonPath('data.on_loan', 0);

        // Returned copies free the shelf, so the same title can go out again.
        $this->as($this->principal)->postJson('/api/v1/library/loans', [
            'library_book_id' => $this->book->id, 'student_id' => $this->zara->id,
        ])->assertCreated();

        // An early return carries no fine.
        $early = LibraryLoan::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'library_book_id' => $this->book->id,
            'copy_no' => 2, 'student_id' => $this->usama->id, 'issued_on' => '2026-10-19',
            'due_on' => '2026-11-02', 'status' => 'borrowed',
        ]);
        $this->as($this->principal)->postJson("/api/v1/library/loans/{$early->id}/return", ['returned_on' => '2026-10-20'])
            ->assertOk()->assertJsonPath('data.days_late', 0);
        $this->assertSame(0.0, (float) LibraryLoan::find($early->id)->fine_amount);

        // The clerk may still override the arithmetic.
        $manual = LibraryLoan::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'library_book_id' => $this->shared->id,
            'student_id' => $this->hira->id, 'issued_on' => '2026-09-01', 'due_on' => '2026-09-15',
            'status' => 'borrowed',
        ]);
        $this->as($this->principal)->postJson("/api/v1/library/loans/{$manual->id}/return", ['fine_amount' => 250])
            ->assertOk();
        $this->assertSame(250.0, (float) LibraryLoan::find($manual->id)->fine_amount);

        $this->as($this->principal)->postJson("/api/v1/library/loans/{$loan->id}/return")
            ->assertStatus(422)->assertJsonFragment(['message' => 'This copy is already returned.']);
    }

    public function test_a_lost_copy_keeps_its_fine_and_still_counts_against_the_holding(): void
    {
        $loan = LibraryLoan::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'library_book_id' => $this->book->id,
            'copy_no' => 1, 'student_id' => $this->ali->id, 'issued_on' => '2026-10-01',
            'due_on' => '2026-10-16', 'status' => 'borrowed',
        ]);

        $this->as($this->principal)->postJson("/api/v1/library/loans/{$loan->id}/lost", ['notes' => 'Lost in a house shifting.'])
            ->assertOk()->assertJsonPath('data.status', 'lost');

        // Five days overdue at the Rs 5 default rate is still owed.
        $this->assertSame(25.0, (float) LibraryLoan::find($loan->id)->fine_amount);

        $this->as($this->principal)->getJson('/api/v1/library/summary')->assertOk()
            ->assertJsonPath('data.lost', 1)
            ->assertJsonPath('data.fines_outstanding', 25);

        $this->as($this->principal)->getJson('/api/v1/library/books')->assertOk()
            ->assertJsonPath('data.data.0.on_loan', 1);

        // Only one copy of the shared atlas exists and it is out; a second issue is refused.
        $this->as($this->principal)->postJson('/api/v1/library/loans', [
            'library_book_id' => $this->shared->id, 'student_id' => $this->zara->id,
        ])->assertCreated();
        $this->as($this->principal)->postJson('/api/v1/library/loans', [
            'library_book_id' => $this->shared->id, 'student_id' => $this->usama->id,
        ])->assertStatus(422)->assertJsonFragment([
            'message' => 'All 1 copy(s) of "Oxford Student Atlas" are already out.',
        ]);

        // A lost copy cannot be "returned" backwards, and the title cannot be deleted.
        $this->as($this->principal)->deleteJson('/api/v1/library/books/' . $this->book->id)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Return the 1 loan(s) on "Federal Board Mathematics IX" before removing it.']);

        $this->as($this->principal)->postJson("/api/v1/library/loans/{$loan->id}/lost")
            ->assertOk();
    }

    public function test_loans_are_filterable_and_show_a_person_not_an_id(): void
    {
        LibraryLoan::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'library_book_id' => $this->book->id,
            'student_id' => $this->ali->id, 'issued_on' => '2026-09-20', 'due_on' => '2026-10-04',
            'status' => 'borrowed',
        ]);
        LibraryLoan::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'library_book_id' => $this->book->id,
            'copy_no' => 2, 'staff_id' => $this->librarian->id, 'issued_on' => '2026-10-18',
            'due_on' => '2026-11-01', 'status' => 'borrowed',
        ]);

        $this->as($this->principal)->getJson('/api/v1/library/loans?status=overdue')->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.borrower_name', 'Ali Khan')
            ->assertJsonPath('data.data.0.book.title', 'Federal Board Mathematics IX')
            ->assertJsonPath('data.data.0.is_overdue', true);

        $this->as($this->principal)->getJson('/api/v1/library/loans?status=active')->assertOk()->assertJsonCount(2, 'data.data');
        $this->as($this->principal)->getJson('/api/v1/library/loans?student_id=' . $this->ali->id)
            ->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.borrower_name', 'Ali Khan');
        $this->as($this->principal)->getJson('/api/v1/library/loans?status=returned')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_library_writes_require_library_manage(): void
    {
        $this->as($this->accounts)->getJson('/api/v1/library/books')->assertOk();
        $this->as($this->accounts)->getJson('/api/v1/library/summary')->assertOk();

        $this->as($this->accounts)->postJson('/api/v1/library/loans', [
            'library_book_id' => $this->book->id, 'student_id' => $this->ali->id,
        ])->assertStatus(403);

        $this->as($this->accounts)->postJson('/api/v1/library/books', [
            'title' => 'Unauthorized', 'total_copies' => 1,
        ])->assertStatus(403);
    }

    public function test_shared_titles_are_visible_from_every_campus_but_campus_stock_is_not(): void
    {
        $this->as($this->principal)->getJson('/api/v1/library/books')->assertOk()->assertJsonCount(2, 'data.data');

        // Head office sees both titles across the organization.
        $this->as($this->admin)->getJson('/api/v1/library/books')
            ->assertOk()->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.campus_id', $this->lhr)
            ->assertJsonPath('data.data.1.campus_id', null);

        // The Lahore copy is invisible (and undeletable) from Islamabad; the atlas is not.
        $this->as($this->admin, $this->isb)->getJson('/api/v1/library/books')
            ->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'Oxford Student Atlas');
        $this->as($this->admin, $this->isb)->deleteJson('/api/v1/library/books/' . $this->book->id)->assertNotFound();

        // Head office may catalogue a shared title for the whole organization.
        $this->as($this->admin)->postJson('/api/v1/library/books', [
            'title' => 'Oxford 3000 Key Words', 'category' => 'Reference', 'total_copies' => 12,
        ])->assertCreated()->assertJsonPath('data.campus_id', null);
    }

    // ------------------------------------------------------------------- hostel

    public function test_bed_allotment_rejects_a_taken_bed_an_over_capacity_room_and_a_gender_mismatch(): void
    {
        $this->as($this->principal)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $this->ali->id, 'hostel_room_id' => $this->h1->id,
        ])->assertCreated()->assertJsonPath('data.bed_no', 1)->assertJsonPath('data.status', 'active');

        // Bed 1 of H-1 is taken.
        $this->as($this->principal)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $this->usama->id, 'hostel_room_id' => $this->h1->id, 'bed_no' => 1,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Bed 1 in room H-1 is already allotted.']);

        // A girl cannot be housed in a boys block.
        $this->as($this->principal)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $this->zara->id, 'hostel_room_id' => $this->h1->id,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Zara is female, but Jinnah House is a boys hostel.']);

        // Fill the second bed, then the room is full.
        $this->as($this->principal)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $this->usama->id, 'hostel_room_id' => $this->h1->id, 'bed_no' => 2,
        ])->assertCreated()->assertJsonPath('data.bed_no', 2);

        $third = $this->makeStudent('Nabeel', 'Qureshi', 'male', $this->lhr);
        $this->as($this->principal)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $third->id, 'hostel_room_id' => $this->h1->id,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Room H-1 is full (2 of 2 beds allotted).']);

        // One student may not hold two beds, even across blocks.
        $this->as($this->principal)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $this->ali->id, 'hostel_room_id' => $this->g1->id,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Ali already holds bed 1 in Jinnah House. Vacate that bed first.']);

        $this->as($this->principal)->getJson('/api/v1/hostel/summary')->assertOk()
            ->assertJsonPath('data.beds_total', 5)
            ->assertJsonPath('data.beds_occupied', 2)
            ->assertJsonPath('data.beds_free', 3)
            ->assertJsonPath('data.occupancy_pct', 40)
            ->assertJsonPath('data.blocks.0.name', 'Fatima House')
            ->assertJsonPath('data.blocks.0.rooms', 1)
            ->assertJsonPath('data.blocks.0.occupied', 0);

        // The room grid needs the occupant's name against the bed number.
        $this->as($this->principal)->getJson('/api/v1/hostel/rooms?block_id=' . $this->boys->id)->assertOk()
            ->assertJsonPath('data.0.number', 'H-1')
            ->assertJsonPath('data.0.occupied', 2)
            ->assertJsonPath('data.0.beds.0.taken', true)
            ->assertJsonPath('data.0.beds.0.student', 'Ali Khan (26-001)')
            ->assertJsonPath('data.0.beds.1.student', 'Usama Raza (26-003)');

        // Vacating frees the bed and closes the record with today's date.
        $allotment = HostelAllotment::firstWhere('student_id', $this->usama->id);
        $this->as($this->principal)->postJson("/api/v1/hostel/allotments/{$allotment->id}/vacate")
            ->assertOk()->assertJsonPath('data.status', 'vacated')->assertJsonPath('data.to_date', '2026-10-21');

        $this->as($this->principal)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $third->id, 'hostel_room_id' => $this->h1->id,
        ])->assertCreated()->assertJsonPath('data.bed_no', 2);

        $this->as($this->principal)->postJson("/api/v1/hostel/allotments/{$allotment->id}/vacate")
            ->assertStatus(422)->assertJsonFragment(['message' => 'This bed is already vacated.']);
    }

    public function test_hostel_setup_requires_a_campus_and_a_managed_permission(): void
    {
        // Head office has no campus context, so a block cannot be created.
        $this->as($this->admin)->postJson('/api/v1/hostel/blocks', [
            'name' => 'No Campus Block', 'gender' => 'boys',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Select a campus before adding a hostel block.']);

        $this->as($this->admin, $this->lhr)->postJson('/api/v1/hostel/blocks', [
            'name' => 'Iqbal House', 'gender' => 'boys',
        ])->assertCreated()->assertJsonPath('data.campus_id', $this->lhr);

        $this->as($this->principal)->postJson('/api/v1/hostel/blocks', [
            'name' => 'Jinnah House', 'gender' => 'boys',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'A block named Jinnah House already exists in this campus.']);

        $this->as($this->principal)->postJson('/api/v1/hostel/rooms', [
            'hostel_block_id' => $this->boys->id, 'number' => 'H-2', 'capacity' => 4, 'monthly_fee' => 18000,
        ])->assertCreated()->assertJsonPath('data.block.name', 'Jinnah House');

        $this->as($this->principal)->postJson('/api/v1/hostel/rooms', [
            'hostel_block_id' => $this->boys->id, 'number' => 'H-1', 'capacity' => 2,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Room H-1 already exists in Jinnah House.']);

        // The accounts officer can read the hostel but not touch it.
        $this->as($this->accounts)->getJson('/api/v1/hostel/allotments')->assertOk();
        $this->as($this->accounts)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $this->zara->id, 'hostel_room_id' => $this->g1->id,
        ])->assertStatus(403);
    }

    public function test_capacity_and_gender_rules_are_enforced_when_editing(): void
    {
        HostelAllotment::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'student_id' => $this->ali->id,
            'hostel_room_id' => $this->h1->id, 'bed_no' => 1, 'from_date' => '2026-10-01', 'status' => 'active',
        ]);
        HostelAllotment::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'student_id' => $this->usama->id,
            'hostel_room_id' => $this->h1->id, 'bed_no' => 2, 'from_date' => '2026-10-01', 'status' => 'active',
        ]);
        $h2 = HostelRoom::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'hostel_block_id' => $this->boys->id,
            'number' => 'H-2', 'capacity' => 3, 'monthly_fee' => 18000,
        ]);

        $this->as($this->principal)->putJson('/api/v1/hostel/rooms/' . $this->h1->id, ['capacity' => 1])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Room H-1 has 2 bed(s) allotted; the capacity cannot drop below that.']);

        $this->as($this->principal)->putJson('/api/v1/hostel/blocks/' . $this->boys->id, ['gender' => 'girls'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Change the gender only after vacating the 2 active allotment(s) in Jinnah House.']);

        // A room cannot be sent for maintenance while students hold its beds.
        $this->as($this->principal)->putJson('/api/v1/hostel/rooms/' . $this->h1->id, ['status' => 'maintenance'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Vacate the beds in this room before sending it for maintenance.']);

        // The edit that respects the occupancy goes through.
        $this->as($this->principal)->putJson('/api/v1/hostel/rooms/' . $this->h1->id, ['capacity' => 2, 'monthly_fee' => 24000])
            ->assertOk()->assertJsonPath('data.capacity', 2);

        // A girl cannot be housed in the boys block, even in an empty room.
        $this->as($this->principal)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $this->hira->id, 'hostel_room_id' => $h2->id,
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Hira is female, but Jinnah House is a boys hostel.']);

        // The boys house can still take its two free beds; the third is refused as full.
        $this->as($this->principal)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $this->makeStudent('Nabeel', 'Qureshi', 'male', $this->lhr)->id, 'hostel_room_id' => $h2->id,
        ])->assertCreated()->assertJsonPath('data.bed_no', 1);

        $third = HostelRoom::create([
            'organization_id' => 1, 'campus_id' => $this->lhr, 'hostel_block_id' => $this->girls->id,
            'number' => 'G-9', 'capacity' => 1, 'monthly_fee' => 20000, 'status' => 'maintenance',
        ]);
        $this->as($this->principal)->postJson('/api/v1/hostel/allotments', [
            'student_id' => $this->zara->id, 'hostel_room_id' => $third->id,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Room G-9 is under maintenance.']);
    }

    public function test_the_demo_seeders_fill_the_library_and_the_hostel_consistently(): void
    {
        $this->seed(\Database\Seeders\AcademicsSeeder::class);
        $this->seed(\Database\Seeders\HrSeeder::class);
        $this->seed(\Database\Seeders\LibrarySeeder::class);
        $this->seed(\Database\Seeders\HostelSeeder::class);

        $library = $this->as($this->principal)->getJson('/api/v1/library/summary')->assertOk()->json('data');
        $this->assertGreaterThan(14, $library['titles']);   // 11 Lahore titles + the shared shelf
        $this->assertGreaterThan(100, $library['copies']);
        $this->assertGreaterThan(5, $library['on_loan']);
        $this->assertGreaterThan(0, $library['overdue']);
        $this->assertGreaterThan(0, $library['fines_outstanding']);
        $this->assertSame(5.0, (float) $library['fine_per_day']);

        // Every loan row reads as a person and a title, never as bare ids.
        $active = $this->as($this->principal)->getJson('/api/v1/library/loans?status=active')
            ->assertOk()->json('data.data');
        $this->assertNotEmpty($active[0]['borrower_name']);
        $this->assertNotEmpty($active[0]['book']['title']);
        foreach (LibraryBook::withCount('openLoans')->get() as $title) {
            $this->assertLessThanOrEqual((int) $title->total_copies, (int) $title->open_loans_count, $title->title);
        }

        $hostel = $this->as($this->principal)->getJson('/api/v1/hostel/summary')->assertOk()->json('data');
        $this->assertGreaterThan(0, $hostel['beds_total']);
        $this->assertSame($hostel['beds_total'], $hostel['beds_occupied'] + $hostel['beds_free']);
        $this->assertGreaterThan(0, $hostel['beds_occupied']);

        // Gender discipline holds in the demo data: no girl sleeps in a boys house.
        $boys = HostelBlock::where('gender', 'boys')->pluck('id');
        $mixed = HostelAllotment::active()->whereIn('hostel_room_id', HostelRoom::whereIn('hostel_block_id', $boys)->pluck('id'))
            ->whereHas('student', fn ($q) => $q->where('gender', 'female'))->count();
        $this->assertSame(0, $mixed);

        // No student holds two beds, and no bed is double-booked.
        $this->assertSame(
            HostelAllotment::active()->count(),
            HostelAllotment::active()->distinct('student_id')->count('student_id')
        );
        $this->assertSame(
            HostelAllotment::active()->count(),
            HostelAllotment::active()->get()->groupBy(fn ($a) => $a->hostel_room_id . '-' . $a->bed_no)->count()
        );

        // Room beds never exceed capacity.
        foreach (HostelRoom::withCount('liveAllotments')->get() as $room) {
            $this->assertLessThanOrEqual((int) $room->capacity, (int) $room->live_allotments_count, $room->number);
        }
    }
}
