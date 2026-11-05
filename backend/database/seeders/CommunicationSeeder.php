<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Campus;
use App\Models\CommunicationLog;
use App\Models\Message;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Audience;
use App\Support\Sms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Sample communication data. Delivery is *recorded* here, never dispatched: a seed run
 * on a production box must not mail or SMS every parent. Real fan-out only happens
 * through POST /announcements/{id}/publish.
 */
class CommunicationSeeder extends Seeder
{
    public function run(): void
    {
        $lhr = Campus::firstWhere('code', 'LHR');
        $psh = Campus::firstWhere('code', 'PSH');
        $sms = app(Sms::class);

        $guardians = $this->guardianAccounts($lhr);
        if ($guardians->isEmpty()) return; // students not seeded yet

        $principal = User::firstWhere('email', 'principal.lhr@sunrise.edu.pk');
        $teacher = User::firstWhere('email', 'teacher.lhr@sunrise.edu.pk');
        $headOffice = User::firstWhere('email', 'ho.admin@sunrise.edu.pk');
        $pshPrincipal = User::firstWhere('email', 'principal.psh@sunrise.edu.pk');

        $notices = [
            [
                'campus_id' => null, 'created_by' => $headOffice, 'audience' => 'all', 'priority' => 'normal',
                'title' => 'Mid-term examinations begin 26 October 2026',
                'body' => "The mid-term schedule for Grades 9 and 10 is published on the notices board of every campus.\n"
                    . 'Practicals run 24-25 October. Students must carry their date sheet and ID card.'
                    . "\nReport cards are issued ten working days after the last paper.",
                'send_email' => true, 'send_sms' => false, 'hours_ago' => 216,
                'expires_at' => now()->addDays(35)->toDateString(),
            ],
            [
                'campus_id' => $lhr?->id, 'created_by' => $principal, 'audience' => 'staff', 'priority' => 'high',
                'title' => 'Staff meeting Friday 4pm — attendance compulsory',
                'body' => "Agenda: mid-term invigilation duty, attendance recovery for Class 9-B, and the annual prize-list review.\n"
                    . 'Class teachers must bring their section registers.',
                'send_email' => true, 'send_sms' => true, 'hours_ago' => 72,
                'expires_at' => now()->addDays(4)->toDateString(),
            ],
            [
                'campus_id' => null, 'created_by' => $headOffice, 'audience' => 'guardians', 'priority' => 'normal',
                'title' => 'October fee due on the 10th — 2% late fee thereafter',
                'body' => "Pay at the campus cashier, by bank transfer, or through JazzCash using your receipt number.\n"
                    . 'Sibling concessions already approved do not need a new application.',
                'send_email' => false, 'send_sms' => true, 'hours_ago' => 144,
                'expires_at' => now()->addDays(12)->toDateString(),
            ],
            [
                'campus_id' => $psh?->id, 'created_by' => $pshPrincipal ?: $headOffice, 'audience' => 'all', 'priority' => 'urgent',
                'title' => 'Peshawar campus closed Friday 23 October (water supply work)',
                'body' => 'The tanker replacement runs all day. Online classes continue on the normal timetable.',
                'send_email' => false, 'send_sms' => true, 'hours_ago' => 24,
                'expires_at' => now()->addDays(3)->toDateString(),
            ],
            [
                'campus_id' => $lhr?->id, 'created_by' => $principal, 'audience' => 'students', 'priority' => 'normal',
                'title' => 'Parent-teacher meeting for Grades 9 and 10 — Saturday 1 November',
                'body' => 'Each student gets a fifteen-minute slot. Guardians must bring the progress slip issued with the mid-term date sheet.',
                'send_email' => true, 'send_sms' => true, 'status' => 'draft',
                'publish_at' => now()->addDay()->setTime(9, 0),
                'expires_at' => now()->addDays(20)->toDateString(),
            ],
        ];

        foreach ($notices as $n) {
            $status = $n['status'] ?? 'published';
            $a = Announcement::firstOrCreate(
                ['organization_id' => 1, 'title' => $n['title']],
                $this->noticeAttributes($n, $status, $sms),
            );
            if ($status !== 'published') continue;

            // Recorded exactly as AnnouncementDelivery would have written it.
            $users = User::where('is_active', true)
                ->whereIn('id', Audience::reach(1, $a->campus_id, $a->audience)['user_ids'])->get();
            if ($n['send_email']) {
                foreach ($users->whereNotNull('email') as $u) {
                    $this->log($a, 'email', $u->email, 'sent', (string) config('mail.default'));
                }
            }
            if ($n['send_sms']) {
                $phones = $users->pluck('phone')->concat(Audience::reach(1, $a->campus_id, $a->audience)['guardian_phones'])
                    ->map(fn ($p) => $sms->normalize($p))->filter()->unique();
                foreach ($phones as $msisdn) {
                    $this->log($a, 'sms', $msisdn, 'sent', 'log');
                }
                // One household on a number the gateway cannot dial, so the failures view is not empty.
                $this->log($a, 'sms', '12345', 'skipped', null, 'Missing or invalid mobile number.');
            }
        }

        $this->readBy($principal);

        $threads = [
            [$principal, $teacher, 'Class 9-B attendance has slipped below 75%',
                'Three students are under the warning threshold this month. Please send me the recovery plan you discussed with their guardians before Thursday.',
                ['Noted. Ali Khan and Sara Butt have guardians called today; the third case is a transport issue and I have asked the office to add him to the school van list.',
                 'Good. Add the van case to the register remarks so the audit trail shows why he is still marked absent.']],
            [$headOffice, $principal, 'Head office review of fee waivers on 25 October',
                'Bring the waiver register and the supporting documents for every concession granted since April. Finance will sample ten files.',
                []],
            [$teacher, $guardians->first(), 'Progress slip for ' . $this->wardName($guardians->first()),
                'Please review the slip with your ward at home — Mathematics is strong, but unseen Urdu passages need daily practice. Sign it and return it on Monday.',
                []],
        ];

        foreach ($threads as [$from, $to, $subject, $body, $replies]) {
            if (!$from || !$to) continue;
            $root = $this->say($from, $to, $subject, $body, $lhr?->id, 360);
            foreach ($replies as $i => $replyBody) {
                $this->say($i % 2 === 0 ? $to : $from, $i % 2 === 0 ? $from : $to, $subject, $replyBody, $lhr?->id, 300 - $i * 60, $root->id);
            }
        }
    }

    private function noticeAttributes(array $n, string $status, Sms $sms): array
    {
        $seats = Audience::reach(1, $n['campus_id'], $n['audience']);
        $users = User::where('is_active', true)->whereIn('id', $seats['user_ids'])->get();
        $published = $status === 'published';

        return [
            'campus_id' => $n['campus_id'],
            'body' => $n['body'],
            'audience' => $n['audience'],
            'priority' => $n['priority'],
            'status' => $status,
            'publish_at' => $n['publish_at'] ?? null,
            'expires_at' => $n['expires_at'],
            'send_email' => $n['send_email'],
            'send_sms' => $n['send_sms'],
            'created_by' => optional($n['created_by'])->id,
            'published_by' => $published ? optional($n['created_by'])->id : null,
            'published_at' => $published ? now()->subHours($n['hours_ago']) : null,
            'recipient_count' => $published ? $users->count() : 0,
        ];
    }

    /**
     * Household logins for the first enrolled students at Lahore, so the guardians and
     * students audiences resolve to real seats instead of zero.
     *
     * @return Collection<int, User>
     */
    private function guardianAccounts(?Campus $lhr): Collection
    {
        if (!$lhr) return collect();
        $role = Role::firstWhere(['organization_id' => 1, 'slug' => 'guardian']);
        $students = Student::withoutGlobalScopes()->where('campus_id', $lhr->id)->where('status', 'active')
            ->orderBy('id')->limit(3)->get();
        if ($students->isEmpty()) return collect();

        $password = env('CMS_SEED_PASSWORD', 'Password123!');
        $out = [];
        foreach ($students as $i => $student) {
            if (!$student->guardian_name) {
                $student->update(['guardian_name' => 'Muhammad ' . $student->last_name]);
                $student->refresh();
            }
            $user = User::firstOrCreate(
                ['email' => sprintf('guardian%d@family.sunrise.edu.pk', $i + 1)],
                [
                    'organization_id' => 1, 'campus_id' => $lhr->id,
                    'name' => $student->guardian_name,
                    'password' => $password, 'is_active' => true,
                    'phone' => $student->guardian_phone,
                ],
            );
            if ($role) $user->roleAssignments()->syncWithoutDetaching([$role->id => ['campus_id' => $lhr->id]]);
            if (!$student->guardian_user_id) $student->update(['guardian_user_id' => $user->id]);
            $out[] = $user;
        }
        return collect($out);
    }

    private function say(?User $from, ?User $to, string $subject, string $body, ?int $campusId, int $minutesAgo, ?int $parentId = null): Message
    {
        // Replies share the root subject, so they are matched on body instead.
        $existing = $parentId
            ? Message::withoutGlobalScopes()->where(['parent_id' => $parentId, 'body' => $body])->first()
            : Message::withoutGlobalScopes()->where(['sender_id' => $from?->id, 'recipient_id' => $to?->id, 'subject' => $subject])->first();
        if ($existing) return $existing;

        $at = now()->subMinutes($minutesAgo);
        $m = Message::create([
            'organization_id' => 1, 'campus_id' => $campusId, 'subject' => $subject, 'body' => $body,
            'sender_id' => $from?->id, 'recipient_id' => $to?->id, 'parent_id' => $parentId,
            'channel' => 'in_app', 'created_at' => $at, 'updated_at' => $at,
        ]);
        if (!$parentId) {
            CommunicationLog::create([
                'organization_id' => 1, 'campus_id' => $campusId, 'channel' => 'in_app',
                'recipient' => (string) $to?->email, 'subject' => $subject, 'status' => 'sent',
                'provider' => 'in_app', 'message_id' => $m->id, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
        return $m;
    }

    private function wardName(User $guardian): string
    {
        $ward = Student::withoutGlobalScopes()->where('guardian_user_id', $guardian->id)->first();
        return $ward ? trim($ward->first_name . ' ' . $ward->last_name) : $guardian->name;
    }

    /** Marks the counters down for one reader so the demo is not all-red. */
    private function readBy(User $reader): void
    {
        foreach (Announcement::withoutGlobalScopes()->where('status', 'published')->get() as $a) {
            if ($a->campus_id && (int) $a->campus_id !== (int) $reader->campus_id) continue;
            if (in_array($a->audience, ['guardians', 'students'], true)) continue;
            AnnouncementRead::firstOrCreate(['announcement_id' => $a->id, 'user_id' => $reader->id], ['read_at' => now()]);
        }
    }

    private function log(Announcement $a, string $channel, string $recipient, string $status, ?string $provider, ?string $error = null): void
    {
        if (CommunicationLog::withoutGlobalScopes()
            ->where(['announcement_id' => $a->id, 'channel' => $channel, 'recipient' => $recipient])->exists()) return;
        CommunicationLog::create([
            'organization_id' => $a->organization_id, 'campus_id' => $a->campus_id, 'channel' => $channel,
            'recipient' => $recipient, 'subject' => $a->title, 'status' => $status,
            'provider' => $provider, 'error' => $error ? mb_substr($error, 0, 255) : null,
            'announcement_id' => $a->id,
        ]);
    }
}
