<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\CommunicationLog;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use App\Support\Audience;
use App\Support\Sms;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Fans an announcement out to its resolved audience. In-app delivery always
 * happens (the row becomes visible to seats); email and SMS are optional,
 * individually logged, and never block the publisher when one recipient fails.
 */
class AnnouncementDelivery
{
    public function __construct(private Sms $sms) {}

    /**
     * @return array{recipients:int, email:array{sent:int,skipped:int,failed:int}, sms:array{sent:int,skipped:int,failed:int}}
     */
    public function publish(Announcement $announcement, ?User $publisher): array
    {
        $seats = Audience::reach(
            $announcement->organization_id,
            $announcement->campus_id,
            $announcement->audience,
        );

        $announcement->update([
            'status' => 'published',
            'published_at' => now(),
            'published_by' => $publisher?->id,
            'recipient_count' => count($seats['user_ids']),
        ]);

        return [
            'recipients' => count($seats['user_ids']),
            'email' => $announcement->send_email ? $this->email($announcement, $seats['user_ids']) : $this->blank(),
            'sms' => $announcement->send_sms ? $this->sms($announcement, $seats) : $this->blank(),
        ];
    }

    /** @param int[] $userIds */
    private function email(Announcement $announcement, array $userIds): array
    {
        $out = $this->blank();
        $users = User::where('is_active', true)->whereIn('id', $userIds)->get();

        // One send per recipient: a broken SMTP session for one mailbox must not
        // silently drop the rest of the campus.
        foreach ($users as $u) {
            if (blank($u->email)) { $out['skipped']++; continue; }
            try {
                $u->notify(new AnnouncementPublished($announcement));
                $out['sent']++;
                $this->log($announcement, 'email', (string) $u->email, 'sent', (string) config('mail.default'), null);
            } catch (\Throwable $e) {
                $out['failed']++;
                $this->log($announcement, 'email', (string) $u->email, 'failed', (string) config('mail.default'), $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * SMS goes to staff mobile numbers plus the guardian numbers captured on student
     * records — households without a portal login still receive the notice.
     *
     * @param array{user_ids:int[], guardian_phones:string[]} $seats
     */
    private function sms(Announcement $announcement, array $seats): array
    {
        $out = $this->blank();
        foreach ($this->numbers($announcement, $seats) as $number) {
            $result = $this->sms->send($number, $this->text($announcement), [
                'subject' => $announcement->title,
                'announcement_id' => $announcement->id,
                'campus_id' => $announcement->campus_id,
            ]);
            if ($result['status'] === 'sent') $out['sent']++;
            elseif ($result['status'] === 'failed') $out['failed']++;
            else $out['skipped']++;
        }
        return $out;
    }

    /** @return Collection<int, string> deduplicated normalized MSISDNs */
    private function numbers(Announcement $announcement, array $seats): Collection
    {
        return User::where('is_active', true)->whereIn('id', $seats['user_ids'])->pluck('phone')
            ->concat($seats['guardian_phones'])
            ->map(fn ($p) => $this->sms->normalize($p))
            ->filter()
            ->unique()
            ->values();
    }

    private function text(Announcement $announcement): string
    {
        $body = trim(preg_replace('/\s+/', ' ', strip_tags($announcement->body)) ?? '');
        return $announcement->title . ($body !== '' ? ': ' . $body : '');
    }

    private function log(Announcement $a, string $channel, string $recipient, string $status, ?string $provider, ?string $error): void
    {
        CommunicationLog::create([
            'organization_id' => $a->organization_id,
            'campus_id' => $a->campus_id,
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $a->title,
            'status' => $status,
            'provider' => $provider,
            'error' => $error ? mb_substr($error, 0, 255) : null,
            'announcement_id' => $a->id,
        ]);
    }

    private function blank(): array
    {
        return ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    }
}
