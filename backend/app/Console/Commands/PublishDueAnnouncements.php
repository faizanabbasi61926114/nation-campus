<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Models\User;
use App\Services\AnnouncementDelivery;
use Illuminate\Console\Command;

/**
 * Runs the announcement scheduler: publishes drafts whose publish_at has arrived and
 * archives notices past their expiry date. Global scopes are lifted because a cron
 * tick has no campus context and must serve every organization.
 */
class PublishDueAnnouncements extends Command
{
    protected $signature = 'announcements:publish-due';

    protected $description = 'Publish scheduled announcements that are due and archive expired ones';

    public function handle(AnnouncementDelivery $delivery): int
    {
        $due = Announcement::withoutGlobalScopes()
            ->where('status', 'draft')
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->orderBy('publish_at')
            ->get();

        foreach ($due as $a) {
            $t = $delivery->publish($a, $a->created_by ? User::find($a->created_by) : null);
            $this->info(sprintf(
                '#%d "%s" → %d seats · email %d sent, %d failed · sms %d sent, %d failed, %d skipped',
                $a->id, $a->title, $t['recipients'],
                $t['email']['sent'], $t['email']['failed'],
                $t['sms']['sent'], $t['sms']['failed'], $t['sms']['skipped'],
            ));
        }
        if ($due->isEmpty()) $this->info('No announcements due.');

        $expired = Announcement::withoutGlobalScopes()
            ->where('status', 'published')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', now()->toDateString())
            ->get();
        foreach ($expired as $e) $e->update(['status' => 'archived']);
        if ($expired->isNotEmpty()) $this->info('Archived ' . $expired->count() . ' expired notice(s).');

        return self::SUCCESS;
    }
}
