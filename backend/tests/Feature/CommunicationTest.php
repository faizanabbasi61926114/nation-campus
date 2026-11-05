<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Campus;
use App\Models\CommunicationLog;
use App\Models\Message;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use App\Notifications\MessageReceived;
use App\Support\Sms;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CommunicationTest extends TestCase
{
    use RefreshDatabase;

    private string $admin;       // super admin, head office
    private string $principal;   // LHR principal — communication.view + manage
    private string $teacher;     // class teacher — communication.view + manage
    private string $accounts;    // accounts officer — no communication permissions
    private string $isb;         // ISB principal
    private User $guardian;      // guardian portal — read only
    private int $lhrId;
    private int $pshId;
    private int $lhrStudents;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 09:00:00');

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->seed(\Database\Seeders\AcademicsSeeder::class);

        $login = function (string $email): string {
            $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123!'])->json('token');
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            return $token;
        };
        $this->admin = $login('admin@nationcampus.edu.pk');
        $this->principal = $login('principal.lhr@sunrise.edu.pk');
        $this->teacher = $login('teacher.lhr@sunrise.edu.pk');
        $this->accounts = $login('accounts.lhr@sunrise.edu.pk');
        $this->isb = $login('principal.isb@sunrise.edu.pk');

        $this->lhrId = Campus::firstWhere('code', 'LHR')->id;
        $this->pshId = Campus::firstWhere('code', 'PSH')->id;
        $this->lhrStudents = Student::withoutGlobalScopes()->where('campus_id', $this->lhrId)->where('status', 'active')->count();

        // One household account, linked to the first Lahore student.
        $this->guardian = User::create([
            'organization_id' => 1, 'campus_id' => $this->lhrId, 'name' => 'Guardian One',
            'email' => 'guardian1@family.sunrise.edu.pk', 'password' => 'Password123!',
            'phone' => '+92 300 1112222', 'is_active' => true,
        ]);
        $this->guardian->roleAssignments()->sync([Role::firstWhere('slug', 'guardian')->id => ['campus_id' => $this->lhrId]]);
        $ward = Student::withoutGlobalScopes()->where('campus_id', $this->lhrId)->orderBy('id')->first();
        $ward->update(['guardian_user_id' => $this->guardian->id]);
        $this->guardianToken = $login('guardian1@family.sunrise.edu.pk');
    }

    private string $guardianToken;

    private function as(string $token, ?int $campus = null): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token);
        if ($campus) $this->withHeaders(['X-Campus-Id' => $campus]);
        return $this;
    }

    private function draft(array $overrides = []): array
    {
        return $this->as($this->principal)->postJson('/api/v1/announcements', $overrides + [
            'title' => 'Mid-term date sheet is ready',
            'body' => 'Collect the date sheet from the office. Practicals start Monday.',
            'audience' => 'staff',
            'priority' => 'high',
        ])->assertCreated()->json('data');
    }

    public function test_the_composer_previews_the_real_reach_of_each_audience(): void
    {
        $guardians = $this->as($this->principal)->getJson('/api/v1/announcements/audience?audience=guardians')
            ->assertOk()->assertJsonPath('data.seats', 1)->assertJsonPath('data.with_email', 1)
            ->json('data');
        $this->assertSame($this->lhrStudents, $guardians['students']);
        // Every Lahore household carries a guardian phone; the portal account adds its own number.
        $this->assertSame($this->lhrStudents + 1, $guardians['mobile_numbers']);
        $this->assertSame('log', $guardians['channel']['sms_driver']);

        $staff = $this->as($this->admin)->withHeaders(['X-Campus-Id' => $this->lhrId])
            ->getJson('/api/v1/announcements/audience?audience=staff')->assertOk()->json('data');
        $this->assertSame(6, $staff['seats']);           // six LHR logins, household account excluded
        $this->assertGreaterThan($staff['seats'], $staff['students']);
    }

    public function test_publishing_fans_out_over_email_and_sms_and_cannot_be_repeated(): void
    {
        Notification::fake();
        $a = $this->draft(['send_email' => true, 'send_sms' => true]);
        $this->assertSame('draft', $a['status']);

        $json = $this->as($this->principal)->postJson("/api/v1/announcements/{$a['id']}/publish")
            ->assertOk()->assertJsonPath('data.status', 'published')->json();

        $this->assertSame(6, $json['delivery']['recipients']);
        $this->assertSame(6, $json['delivery']['email']['sent']);
        $this->assertGreaterThan(0, $json['delivery']['sms']['sent']);

        // One log row per delivery, and the tallies must match the trail exactly.
        $logs = CommunicationLog::withoutGlobalScopes()->where('announcement_id', $a['id'])->get();
        $this->assertSame($json['delivery']['email']['sent'], $logs->where('channel', 'email')->where('status', 'sent')->count());
        $this->assertSame($json['delivery']['sms']['sent'], $logs->where('channel', 'sms')->where('status', 'sent')->count());
        $this->assertSame((string) config('mail.default'), $logs->firstWhere('channel', 'email')->provider);

        $fresh = Announcement::find($a['id']);
        $this->assertSame(6, $fresh->recipient_count);
        $this->assertNotNull($fresh->published_at);

        Notification::assertSentTo(User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk'), AnnouncementPublished::class);
        // The household account is outside the staff audience, so it gets nothing.
        Notification::assertNothingSentTo($this->guardian);

        $this->as($this->principal)->postJson("/api/v1/announcements/{$a['id']}/publish")
            ->assertStatus(422)->assertJsonFragment(['message' => 'Announcement is already published.']);

        // Published notices are frozen: the audit trail is the record of what was sent.
        $this->as($this->principal)->putJson("/api/v1/announcements/{$a['id']}", ['title' => 'Edited after sending'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Only drafts can be edited. Archive the published notice and issue a new one.']);
    }

    public function test_the_feed_only_shows_notices_addressed_to_the_reader(): void
    {
        Notification::fake();
        // Only head office can leave campus_id empty — that is what makes a notice
        // organisation-wide instead of belonging to one campus.
        $orgWide = $this->as($this->admin)->postJson('/api/v1/announcements', [
            'title' => 'Academic calendar for 2026-27', 'body' => 'Mid-terms in December, finals in March.',
            'audience' => 'all', 'priority' => 'normal', 'campus_id' => null,
        ])->assertCreated()->json('data');
        $staffOnly = $this->draft(['title' => 'Staff meeting Friday', 'audience' => 'staff']);
        $households = $this->draft(['title' => 'Fee due on the 10th', 'audience' => 'guardians', 'send_email' => false]);
        $peshawar = $this->as($this->admin)->postJson('/api/v1/announcements', [
            'title' => 'Peshawar campus closed Friday', 'body' => 'Water tanker work.',
            'audience' => 'all', 'priority' => 'urgent', 'campus_id' => $this->pshId,
        ])->assertCreated()->json('data');

        $this->as($this->admin)->postJson("/api/v1/announcements/{$orgWide['id']}/publish")->assertOk();
        foreach ([$staffOnly, $households] as $n) {
            $this->as($this->principal)->postJson("/api/v1/announcements/{$n['id']}/publish")->assertOk();
        }
        // The Peshawar notice is outside the Lahore principal's campus assignment, so
        // head office publishes it — the tenant scope blocks the cross-campus attempt.
        $this->as($this->principal)->postJson("/api/v1/announcements/{$peshawar['id']}/publish")->assertNotFound();
        $this->as($this->admin)->postJson("/api/v1/announcements/{$peshawar['id']}/publish")->assertOk();

        // Every audience is partitioned by campus as well as by who it is addressed to.
        $titles = fn (string $token, ?int $campus = null) => collect(
            $this->as($token, $campus)->getJson('/api/v1/announcements')->assertOk()->json('data.data')
        )->pluck('title')->all();

        $this->assertSame([
            'Peshawar campus closed Friday', 'Staff meeting Friday', 'Academic calendar for 2026-27',
        ], $titles($this->admin));

        // Switched to Peshawar, the Lahore notices vanish but org-wide notices stay.
        $this->assertSame([
            'Peshawar campus closed Friday', 'Academic calendar for 2026-27',
        ], $titles($this->admin, $this->pshId));

        $this->assertSame([
            'Fee due on the 10th', 'Academic calendar for 2026-27',
        ], $titles($this->guardianToken));

        $this->assertSame([
            'Staff meeting Friday', 'Academic calendar for 2026-27',
        ], $titles($this->teacher));

        // Urgent outranks normal regardless of publication order.
        $this->assertSame('Peshawar campus closed Friday', $titles($this->admin)[0]);

        // Read state is per user.
        $first = $this->as($this->teacher)->getJson('/api/v1/announcements')->assertOk()->json('data.data.0');
        $this->assertFalse($first['is_read']);
        $this->as($this->teacher)->postJson("/api/v1/announcements/{$first['id']}/read")->assertOk();
        $this->as($this->teacher)->getJson('/api/v1/announcements')->assertOk()
            ->assertJsonPath('data.data.0.is_read', true);
        $this->as($this->principal)->getJson('/api/v1/announcements')->assertOk()
            ->assertJsonPath('data.data.0.is_read', false);

        // A notice not addressed to you cannot be marked read.
        $householdId = Announcement::withoutGlobalScopes()->firstWhere('title', 'Fee due on the 10th')->id;
        $this->as($this->teacher)->postJson("/api/v1/announcements/{$householdId}/read")->assertStatus(403);
    }

    public function test_a_scheduled_draft_is_released_by_the_scheduler_not_the_editor(): void
    {
        Notification::fake();
        $a = $this->draft([
            'title' => 'Prize distribution ceremony', 'send_email' => true,
            'publish_at' => now()->addDay()->toDateTimeString(),
        ]);

        $this->as($this->principal)->postJson("/api/v1/announcements/{$a['id']}/publish")
            ->assertStatus(422)->assertJsonFragment(['message' => 'Scheduled for 2026-10-20 09:00:00; the scheduler will publish it. Clear the date to send now.']);
        $this->assertSame('draft', Announcement::find($a['id'])->status);

        // An expired notice is archived by the same tick.
        $stale = $this->draft(['title' => 'Eid holiday notice', 'expires_at' => now()->subDays(2)->toDateString()]);
        $this->as($this->principal)->postJson("/api/v1/announcements/{$stale['id']}/publish")->assertOk();

        $this->travel(2)->days();
        $this->artisan('announcements:publish-due')->assertExitCode(0);
        $this->travelBack();

        $this->assertSame('published', Announcement::find($a['id'])->status);
        $this->assertSame('archived', Announcement::find($stale['id'])->status);
        $this->assertGreaterThan(0, CommunicationLog::withoutGlobalScopes()->where('announcement_id', $a['id'])->count());
    }

    public function test_direct_messages_are_readable_only_by_their_participants(): void
    {
        Notification::fake();
        $principalId = User::firstWhere('email', 'principal.lhr@sunrise.edu.pk')->id;
        $teacherId = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk')->id;

        $resp = $this->as($this->principal)->postJson('/api/v1/messages', [
            'recipient_id' => $teacherId, 'subject' => 'Class 9-B attendance',
            'body' => 'Please send the recovery plan before Thursday.', 'send_email' => true,
        ])->assertCreated();
        $root = $resp->json('data');

        Notification::assertSentTo(User::find($teacherId), MessageReceived::class);
        $this->assertSame('queued via ' . config('mail.default'), $resp->json('mirrored.email'));
        $this->assertNull($resp->json('mirrored.sms'));   // SMS mirroring was not requested

        $this->as($this->teacher)->getJson('/api/v1/messages')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.unread', 1)
            ->assertJsonPath('data.data.0.party.name', 'Dr. Sana Qureshi');

        // A third party cannot read it — not even a super user who is not in the thread.
        $this->as($this->admin)->getJson("/api/v1/messages/{$root['id']}/thread")->assertStatus(403);

        $reply = $this->as($this->teacher)->postJson("/api/v1/messages/{$root['id']}/reply", ['body' => 'Plan is attached.'])
            ->assertCreated()->json('data');
        $this->assertSame($root['id'], $reply['parent_id']);
        $this->assertSame($principalId, $reply['recipient_id']);

        $thread = $this->as($this->principal)->getJson("/api/v1/messages/{$root['id']}/thread")
            ->assertOk()->assertJsonPath('data.thread.0.body', 'Please send the recovery plan before Thursday.')
            ->assertJsonPath('data.thread.1.body', 'Plan is attached.')
            ->assertJsonPath('data.other_party.id', $teacherId)->json('data');
        $this->assertCount(2, $thread['thread']);

        // Opening the thread clears the unread flag for the reader's own messages only.
        $this->assertNull($thread['thread'][0]['read_at']);        // addressed to the teacher
        $this->assertNotNull($thread['thread'][1]['read_at']);     // the reply the principal just read
        $this->as($this->principal)->getJson('/api/v1/messages/unread-count')->assertOk()->assertJsonPath('data.messages', 0);
        $this->as($this->teacher)->getJson('/api/v1/messages/unread-count')->assertOk()->assertJsonPath('data.messages', 1);

        // Cross-campus thread: the message row carries the sender's campus hint, and the
        // recipient in Islamabad must still be able to open it.
        $isbId = User::firstWhere('email', 'principal.isb@sunrise.edu.pk')->id;
        $far = $this->as($this->principal)->postJson('/api/v1/messages', [
            'recipient_id' => $isbId, 'subject' => 'Shared curriculum workshop', 'body' => 'Can you join Friday?',
        ])->assertCreated()->json('data');
        $this->assertSame($this->lhrId, $far['campus_id']);
        $this->as($this->isb)->getJson("/api/v1/messages/{$far['id']}/thread")->assertOk()
            ->assertJsonPath('data.thread.0.subject', 'Shared curriculum workshop');

        // Guards: no self-mail, no cross-organization recipient, no messaging a disabled account.
        $this->as($this->principal)->postJson('/api/v1/messages', [
            'recipient_id' => $principalId, 'subject' => 'Note to self', 'body' => 'Remember the audit.',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'You cannot message yourself.']);
        $this->as($this->principal)->postJson('/api/v1/messages', [
            'recipient_id' => 99999, 'subject' => 'Ghost', 'body' => 'Nobody here.',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Recipient is not an active account in your organization.']);
    }

    public function test_a_gateway_driver_without_credentials_never_dials_out(): void
    {
        Notification::fake();
        Http::fake();
        config(['cms.sms.gateway_url' => null, 'cms.sms.gateway_api_key' => null]);
        Setting::put(1, $this->lhrId, 'communication.sms_driver', 'gateway');

        $a = $this->draft(['title' => 'Emergency shutdown drill', 'send_sms' => true, 'send_email' => false]);
        $json = $this->as($this->principal)->postJson("/api/v1/announcements/{$a['id']}/publish")->assertOk()->json();

        $this->assertSame(0, $json['delivery']['sms']['sent']);
        $this->assertSame(6, $json['delivery']['sms']['skipped']);
        $this->assertCount(0, Http::recorded());

        $row = CommunicationLog::withoutGlobalScopes()->where('announcement_id', $a['id'])->where('channel', 'sms')->first();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('gateway', $row->provider);
        $this->assertStringContainsString('SMS_GATEWAY_URL', $row->error);

        // The composer reports the live driver so an editor is never surprised.
        $this->as($this->principal)->getJson('/api/v1/announcements/audience?audience=staff')
            ->assertOk()->assertJsonPath('data.channel.sms_driver', 'gateway')
            ->assertJsonPath('data.channel.gateway_ready', false);

        // Drafting is still allowed: credentials can be added between draft and publish,
        // and every attempt that cannot be dialled is recorded as skipped rather than lost.
        $this->as($this->principal)->postJson('/api/v1/announcements', [
            'title' => 'Still draftable', 'body' => 'Nothing leaves the server.', 'audience' => 'staff', 'send_sms' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'draft');

        $this->assertSame('923001234567', app(Sms::class)->normalize('+92 300 1234567'));
        $this->assertSame('923001234567', app(Sms::class)->normalize('0300-1234567'));
        $this->assertSame('', app(Sms::class)->normalize('12345'));
    }

    public function test_communication_routes_require_the_seeded_permissions(): void
    {
        $this->as($this->accounts)->getJson('/api/v1/announcements')->assertStatus(403);
        $this->as($this->accounts)->getJson('/api/v1/communication/logs')->assertStatus(403);
        $this->as($this->accounts)->getJson('/api/v1/communication/summary')->assertStatus(403);
        $this->as($this->accounts)->postJson('/api/v1/announcements', [
            'title' => 'Nope', 'body' => 'Cannot post.', 'audience' => 'all',
        ])->assertStatus(403);
        $this->as($this->accounts)->postJson('/api/v1/messages', [
            'recipient_id' => $this->guardian->id, 'subject' => 'Fee overdue', 'body' => 'Please pay.',
        ])->assertStatus(403);
        // Reading one's own mail is authorized by participation, so the mailbox stays open.
        $this->as($this->accounts)->getJson('/api/v1/messages')->assertOk()->assertJsonPath('data.total', 0);

        // Guardians may read notices and their own thread, but never publish or send.
        $this->as($this->guardianToken)->getJson('/api/v1/announcements')->assertOk();
        $this->as($this->guardianToken)->postJson('/api/v1/announcements', [
            'title' => 'Guardian post', 'body' => 'Not allowed.', 'audience' => 'guardians',
        ])->assertStatus(403);

        // Only the author (or a manager) may edit or delete a draft.
        $mine = $this->draft(['title' => 'Teachers only notice', 'body' => 'Written by the principal.']);
        $this->as($this->teacher)->putJson("/api/v1/announcements/{$mine['id']}", ['title' => 'Hijacked'])->assertOk();
        $this->as($this->teacher)->deleteJson("/api/v1/announcements/{$mine['id']}")->assertOk();

        // Campus editors cannot aim a notice at a campus outside their assignment.
        $this->as($this->principal)->postJson('/api/v1/announcements', [
            'title' => 'Wrong campus', 'body' => 'Not ours.', 'audience' => 'all', 'campus_id' => $this->pshId,
        ])->assertForbidden();
    }

    public function test_delivery_trail_and_summary_are_queryable(): void
    {
        Notification::fake();
        $a = $this->draft(['send_email' => true]);
        $this->as($this->principal)->postJson("/api/v1/announcements/{$a['id']}/publish")->assertOk();

        $this->as($this->principal)->getJson('/api/v1/communication/logs?channel=email&status=sent')
            ->assertOk()->assertJsonPath('data.total', 6);
        $this->as($this->principal)->getJson('/api/v1/communication/logs?channel=sms')
            ->assertOk()->assertJsonPath('data.total', 0);

        $summary = $this->as($this->principal)->getJson('/api/v1/communication/summary')->assertOk()->json('data');
        $this->assertSame(1, $summary['announcements']['published']);
        $this->assertSame(0, $summary['announcements']['draft']);
        $this->assertSame(0, $summary['announcements']['archived']);
        $this->assertSame((string) config('mail.default'), $summary['channels']['mailer']);
        $this->assertSame('log', $summary['channels']['sms_driver']);
        $this->assertSame('SUNRISE', $summary['channels']['sms_sender']);
        $this->assertSame([['channel' => 'email', 'status' => 'sent', 'count' => 6]], $summary['deliveries']);
        $this->assertSame(['inbox' => 0, 'sent' => 0, 'unread' => 0], $summary['messages']);
    }
}
