<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\CommunicationLog;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MessageReceived;
use App\Services\AnnouncementDelivery;
use App\Support\Audience;
use App\Support\Sms;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationController extends Controller
{
    public function __construct(
        private TenantContext $ctx,
        private AnnouncementDelivery $delivery,
        private Sms $sms,
    ) {}

    // ---------------------------------------------------------------- announcements

    /**
     * scope=feed shows what the current user is allowed to read; scope=manage shows
     * the editorial view (drafts included) for communication.view holders.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', 'in:feed,manage'],
            'audience' => ['nullable', 'in:' . implode(',', Announcement::AUDIENCES)],
            'status' => ['nullable', 'in:' . implode(',', Announcement::STATUSES)],
            'search' => ['nullable', 'string', 'max:80'],
        ]);
        $me = $request->user();
        $scope = $data['scope'] ?? 'feed';

        $q = Announcement::with(['campus:id,name,code', 'creator:id,name', 'publisher:id,name'])
            ->withCount(['reads' => fn ($rel) => $rel->where('user_id', $me->id)])
            ->when($data['audience'] ?? null, fn ($w, $a) => $w->where('audience', $a))
            ->when($data['search'] ?? null, fn ($w, $s) => $w->where(fn ($x) => $x->where('title', 'like', "%$s%")->orWhere('body', 'like', "%$s%")));

        if ($scope === 'feed') {
            $q->live()->whereIn('audience', $this->audiencesFor($me));
        } else {
            $q->when($data['status'] ?? null, fn ($w, $s) => $w->where('status', $s))
                ->when(!$request->user()->hasPermission('communication.manage'), fn ($w) => $w->where('status', 'published'));
        }

        $rows = $q
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 else 2 end")
            ->orderByDesc('published_at')->orderByDesc('created_at')
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        $rows->getCollection()->transform(function (Announcement $a) use ($me) {
            $a->is_read = $a->reads_count > 0;
            $a->is_mine = (int) $a->created_by === (int) $me->id;
            $a->visible_to_me = $this->targetsMe($a, $me);
            return $a;
        });

        return response()->json(['data' => $rows]);
    }

    /** Reach estimate for the composer — nothing is sent here. */
    public function audience(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'in:' . implode(',', Announcement::AUDIENCES)],
            'campus_id' => ['nullable', 'integer'],
        ]);
        $campusId = isset($data['campus_id']) ? $this->assertCampus((int) $data['campus_id']) : $this->ctx->campusId();
        return response()->json(['data' => Audience::summary($this->ctx->organizationId, $campusId, $data['audience']) + [
            'channel' => [
                'mailer' => (string) config('mail.default'),
                'sms_driver' => $this->sms->driver($this->ctx->organizationId, $campusId),
                'gateway_ready' => (bool) (config('cms.sms.gateway_url') && config('cms.sms.gateway_api_key')),
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:8000'],
            'audience' => ['required', 'in:' . implode(',', Announcement::AUDIENCES)],
            'priority' => ['nullable', 'in:' . implode(',', Announcement::PRIORITIES)],
            'campus_id' => ['nullable', 'integer'],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'send_email' => ['nullable', 'boolean'],
            'send_sms' => ['nullable', 'boolean'],
            'publish' => ['nullable', 'boolean'],
        ]);

        if (isset($data['publish_at'], $data['expires_at'])
            && \Carbon\Carbon::parse($data['expires_at'])->lt(\Carbon\Carbon::parse($data['publish_at']))) {
            return response()->json(['message' => 'The expiry date cannot fall before the publish date.'], 422);
        }

        if (array_key_exists('campus_id', $data) && $data['campus_id'] !== null) {
            $campusId = $this->assertCampus((int) $data['campus_id']);
        } else {
            $campusId = $this->ctx->campusId();
        }

        $a = Announcement::create([
            'organization_id' => $this->ctx->organizationId,
            'campus_id' => $campusId,
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'priority' => $data['priority'] ?? 'normal',
            'publish_at' => $data['publish_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'send_email' => (bool) ($data['send_email'] ?? false),
            'send_sms' => (bool) ($data['send_sms'] ?? false),
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        if (!empty($data['publish'])) {
            if ($a->publish_at && $a->publish_at->gt(now())) {
                return response()->json([
                    'message' => 'This draft is scheduled for ' . $a->publish_at->toDateTimeString()
                        . '. Clear the date to publish immediately, or let the scheduler run it.',
                ], 422);
            }
            $tally = $this->delivery->publish($a, $request->user());
            return response()->json([
                'data' => $a->fresh(['campus:id,name,code', 'creator:id,name', 'publisher:id,name']),
                'delivery' => $tally,
            ], 201);
        }

        return response()->json(['data' => $a->load(['campus:id,name,code', 'creator:id,name'])], 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        if ($announcement->status !== 'draft') {
            return response()->json(['message' => 'Only drafts can be edited. Archive the published notice and issue a new one.'], 422);
        }
        $this->assertEditor($request, $announcement);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'body' => ['sometimes', 'required', 'string', 'max:8000'],
            'audience' => ['sometimes', 'required', 'in:' . implode(',', Announcement::AUDIENCES)],
            'priority' => ['sometimes', 'required', 'in:' . implode(',', Announcement::PRIORITIES)],
            'campus_id' => ['sometimes', 'nullable', 'integer'],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'send_email' => ['sometimes', 'boolean'],
            'send_sms' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('campus_id', $data) && $data['campus_id'] !== null) {
            $data['campus_id'] = $this->assertCampus((int) $data['campus_id']);
        }
        $announcement->update($data);

        return response()->json(['data' => $announcement->fresh()]);
    }

    public function publish(Request $request, Announcement $announcement): JsonResponse
    {
        $this->assertEditor($request, $announcement);
        if ($announcement->status !== 'draft') {
            return response()->json(['message' => 'Announcement is already ' . $announcement->status . '.'], 422);
        }
        if ($announcement->publish_at && $announcement->publish_at->gt(now())) {
            return response()->json([
                'message' => 'Scheduled for ' . $announcement->publish_at->toDateTimeString() . '; the scheduler will publish it. Clear the date to send now.',
            ], 422);
        }
        $tally = $this->delivery->publish($announcement, $request->user());
        return response()->json([
            'data' => $announcement->fresh(['campus:id,name,code', 'publisher:id,name']),
            'delivery' => $tally,
            'message' => 'Announcement published.',
        ]);
    }

    public function archive(Request $request, Announcement $announcement): JsonResponse
    {
        $this->assertEditor($request, $announcement);
        $announcement->update(['status' => 'archived']);
        return response()->json(['data' => $announcement->fresh(), 'message' => 'Archived — it no longer appears in the feed.']);
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $this->assertEditor($request, $announcement);
        if ($announcement->status === 'published') {
            return response()->json(['message' => 'Published announcements cannot be deleted. Archive them to keep the audit trail.'], 422);
        }
        $announcement->delete();
        return response()->json(['message' => 'Announcement deleted.']);
    }

    public function readAnnouncement(Request $request, Announcement $announcement): JsonResponse
    {
        if (!$this->targetsMe($announcement, $request->user())) {
            return response()->json(['message' => 'This announcement is not addressed to you.'], 403);
        }
        AnnouncementRead::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $request->user()->id],
            ['read_at' => now()],
        );
        return response()->json(['message' => 'Marked as read.']);
    }

    // --------------------------------------------------------------------- messages

    /** Inbox / sent list. Threads are rooted on the first message; replies hang off it. */
    public function messages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'box' => ['nullable', 'in:inbox,sent,unread'],
            'search' => ['nullable', 'string', 'max:80'],
        ]);
        $me = $request->user()->id;
        $box = $data['box'] ?? 'inbox';

        $q = Message::with(['sender:id,name,phone,email', 'recipient:id,name'])
            ->whereNull('parent_id')
            ->when($data['search'] ?? null, fn ($w, $s) => $w->where(fn ($x) => $x->where('subject', 'like', "%$s%")->orWhere('body', 'like', "%$s%")));

        if ($box === 'sent') {
            $q->where('sender_id', $me);
        } elseif ($box === 'unread') {
            $q->where('recipient_id', $me)->whereNull('read_at');
        } else {
            $q->involving($me);
        }

        $page = $q->orderByDesc('created_at')->paginate(min(100, (int) $request->input('per_page', 25)));
        $ids = $page->pluck('id')->all();

        $stats = $ids ? Message::whereIn('parent_id', $ids)
            ->selectRaw('parent_id, count(*) as replies, max(created_at) as last_at, '
                . 'sum(case when recipient_id = ? and read_at is null then 1 else 0 end) as unread', [$me])
            ->groupBy('parent_id')->get()->keyBy('parent_id') : collect();

        $page->getCollection()->transform(function (Message $m) use ($me, $stats) {
            $s = $stats->get($m->id);
            $m->replies_count = (int) ($s->replies ?? 0);
            $m->unread = (int) ($s->unread ?? 0) + ($m->recipient_id === $me && !$m->read_at ? 1 : 0);
            $m->last_activity = $s->last_at ?? $m->created_at?->toDateTimeString();
            $m->party = $m->sender_id === $me
                ? ['id' => $m->recipient_id, 'name' => optional($m->recipient)->name ?? 'Former user']
                : ['id' => $m->sender_id, 'name' => optional($m->sender)->name ?? 'Former user'];
            return $m;
        });

        return response()->json(['data' => $page]);
    }

    /**
     * Participation, not permissions, authorizes reading: every query below is
     * filtered to the authenticated user, so a role without communication.view
     * still receives replies addressed to it.
     */
    public function thread(Request $request, Message $message): JsonResponse
    {
        $me = $request->user();
        abort_unless($message->sender_id === $me->id || $message->recipient_id === $me->id, 403, 'This thread is not yours.');

        $rows = $message->threadRows()->load(['sender:id,name', 'recipient:id,name']);
        foreach ($rows as $row) {
            if ($row->recipient_id === $me->id && !$row->read_at) $row->update(['read_at' => now()]);
        }

        return response()->json(['data' => [
            'thread' => $rows->values(),
            'root_id' => $message->parent_id ?: $message->id,
            'other_party' => $message->sender_id === $me->id ? $message->recipient : $message->sender,
        ]]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:8000'],
            'send_email' => ['nullable', 'boolean'],
            'send_sms' => ['nullable', 'boolean'],
        ]);

        $recipient = User::find($data['recipient_id']);
        if (!$recipient || (int) $recipient->organization_id !== $this->ctx->organizationId) {
            return response()->json(['message' => 'Recipient is not an active account in your organization.'], 422);
        }
        if (!$recipient->is_active) return response()->json(['message' => 'Recipient account is disabled.'], 422);
        if ((int) $recipient->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot message yourself.'], 422);
        }

        $message = Message::create([
            'organization_id' => $this->ctx->organizationId,
            'campus_id' => $this->ctx->campusId(),
            'subject' => $data['subject'],
            'body' => $data['body'],
            'sender_id' => $request->user()->id,
            'recipient_id' => $recipient->id,
            'send_email' => (bool) ($data['send_email'] ?? false),
            'send_sms' => (bool) ($data['send_sms'] ?? false),
            'channel' => 'in_app',
        ]);

        // Mirror before narrowing the loaded relations: the mailer needs the recipient's
        // address, which the response's id,name column selection would hide.
        $mirrored = $this->mirror($message, $data);

        return response()->json([
            'data' => $message->load(['sender:id,name', 'recipient:id,name']),
            'mirrored' => $mirrored,
        ], 201);
    }

    public function reply(Request $request, Message $message): JsonResponse
    {
        $me = $request->user();
        abort_unless($message->sender_id === $me->id || $message->recipient_id === $me->id, 403, 'This thread is not yours.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
            'send_email' => ['nullable', 'boolean'],
            'send_sms' => ['nullable', 'boolean'],
        ]);

        $to = $message->sender_id === $me->id ? $message->recipient_id : $message->sender_id;
        if (!$to) return response()->json(['message' => 'The other participant no longer has an account.'], 422);

        $reply = Message::create([
            'organization_id' => $message->organization_id,
            'campus_id' => $this->ctx->campusId(),
            'subject' => $message->subject,
            'body' => $data['body'],
            'sender_id' => $me->id,
            'recipient_id' => $to,
            'parent_id' => $message->parent_id ?: $message->id,
            'send_email' => (bool) ($data['send_email'] ?? false),
            'send_sms' => (bool) ($data['send_sms'] ?? false),
            'channel' => 'in_app',
        ]);

        $mirrored = $this->mirror($reply, $data);

        return response()->json([
            'data' => $reply->load(['sender:id,name', 'recipient:id,name']),
            'mirrored' => $mirrored,
        ], 201);
    }

    public function markRead(Request $request, Message $message): JsonResponse
    {
        $me = $request->user();
        abort_unless($message->recipient_id === $me->id, 403, 'Not your message.');
        $message->update(['read_at' => $message->read_at ?? now()]);
        return response()->json(['message' => 'Marked as read.']);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $me = $request->user()->id;
        $messages = Message::unread($me)->count();
        $announcements = Announcement::live()
            ->whereIn('audience', $this->audiencesFor($request->user()))
            ->whereDoesntHave('reads', fn ($rel) => $rel->where('user_id', $me))
            ->count();
        return response()->json(['data' => ['messages' => $messages, 'announcements' => $announcements]]);
    }

    /** Recipient picker for the composer. */
    public function contacts(Request $request): JsonResponse
    {
        $me = $request->user();
        if (!$me->hasPermission('communication.manage')) {
            return response()->json(['data' => $this->householdContacts($me)]);
        }

        $q = User::with('roleAssignments:id,slug')->where('organization_id', $this->ctx->organizationId)
            ->where('is_active', true)
            ->when($request->filled('search'), function ($w) use ($request) {
                $s = $request->input('search');
                $w->where(fn ($x) => $x->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
            })
            ->orderBy('name')->limit(200)->get();

        return response()->json(['data' => $q->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'campus_id' => $u->campus_id,
            'roles' => $u->roleAssignments->pluck('slug')->all(),
        ])->values()]);
    }

    /**
     * A household account is not staff, so it does not get the staff list or the other
     * parents' addresses — only the teachers of its own children and whoever already
     * wrote to it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function householdContacts(User $me): array
    {
        $teacherIds = DB::table('sections')
            ->whereIn('id', $me->wardQuery()->pluck('section_id')->filter()->all() ?: [0])
            ->whereNotNull('class_teacher_id')
            ->pluck('class_teacher_id');

        $threadIds = DB::table('messages')->where('organization_id', $me->organization_id)
            ->where(fn ($q) => $q->where('sender_id', $me->id)->orWhere('recipient_id', $me->id))
            ->get(['sender_id', 'recipient_id'])
            ->flatMap(fn ($m) => [$m->sender_id, $m->recipient_id])
            ->reject(fn ($id) => (int) $id === (int) $me->id);

        $ids = $teacherIds->concat($threadIds)->unique()->filter()->values();
        if ($ids->isEmpty()) return [];

        return User::with('roleAssignments:id,slug')
            ->whereIn('id', $ids)->where('organization_id', $me->organization_id)->where('is_active', true)
            ->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'campus_id' => $u->campus_id, 'roles' => $u->roleAssignments->pluck('slug')->all(),
            ])->all();
    }

    // ------------------------------------------------------------------------ audit

    public function summary(Request $request): JsonResponse
    {
        $byStatus = Announcement::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $deliveries = CommunicationLog::query()
            ->selectRaw('channel, status, count(*) c')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('channel', 'status')->get();

        return response()->json(['data' => [
            'announcements' => [
                'draft' => (int) ($byStatus['draft'] ?? 0),
                'published' => (int) ($byStatus['published'] ?? 0),
                'archived' => (int) ($byStatus['archived'] ?? 0),
            ],
            'deliveries' => $deliveries->map(fn ($d) => [
                'channel' => $d->channel, 'status' => $d->status, 'count' => (int) $d->c,
            ])->values(),
            'messages' => [
                'inbox' => Message::involving($request->user()->id)->count(),
                'sent' => Message::where('sender_id', $request->user()->id)->count(),
                'unread' => Message::unread($request->user()->id)->count(),
            ],
            'channels' => [
                'mailer' => (string) config('mail.default'),
                'sms_driver' => $this->sms->driver(),
                'sms_sender' => (string) (Setting::getFor($this->ctx->organizationId, $this->ctx->campusId(), 'communication.sms_sender') ?? config('cms.sms.sender_id')),
            ],
        ]]);
    }

    public function logs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['nullable', 'in:' . implode(',', CommunicationLog::CHANNELS)],
            'status' => ['nullable', 'in:' . implode(',', CommunicationLog::STATUSES)],
            'announcement_id' => ['nullable', 'integer'],
        ]);
        $rows = CommunicationLog::with(['campus:id,name,code'])
            ->when($data['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['announcement_id'] ?? null, fn ($q, $a) => $q->where('announcement_id', $a))
            ->latest('id')
            ->paginate(min(200, (int) $request->input('per_page', 50)));
        return response()->json(['data' => $rows]);
    }

    // ---------------------------------------------------------------------- helpers

    private array $guardianCache = [];

    private function isGuardian(User $user): bool
    {
        if (!isset($this->guardianCache[$user->id])) {
            $this->guardianCache[$user->id] = DB::table('students')->where('guardian_user_id', $user->id)->exists();
        }
        return $this->guardianCache[$user->id];
    }

    /** Audiences this reader belongs to, mirroring how Audience::reach assigns seats. */
    private function audiencesFor(User $me): array
    {
        $allowed = ['all'];
        $staffSide = $me->roleAssignments->pluck('slug')->diff(['guardian'])->isNotEmpty();
        if ($staffSide || $me->hasPermission('communication.manage')) $allowed[] = 'staff';
        if ($this->isGuardian($me)) $allowed = array_merge($allowed, ['guardians', 'students']);
        return $allowed;
    }

    private function targetsMe(Announcement $a, User $me): bool
    {
        if ($a->status !== 'published') {
            return (int) $a->created_by === (int) $me->id || $me->hasPermission('communication.manage');
        }
        $campusOk = !$a->campus_id || (int) $a->campus_id === (int) $me->campus_id || $me->isHeadOffice();
        return $campusOk && in_array($a->audience, $this->audiencesFor($me), true);
    }

    private function assertEditor(Request $request, Announcement $announcement): void
    {
        $me = $request->user();
        if ((int) $announcement->created_by === (int) $me->id) return;
        abort_unless($me->hasPermission('communication.manage'), 403, 'Only the author or a communication manager may edit this announcement.');
    }

    private function assertCampus(int $campusId): int
    {
        abort_unless(in_array($campusId, $this->ctx->user?->accessibleCampusIds() ?? [], true), 403, 'That campus is outside your assignment.');
        return $campusId;
    }

    /**
     * Optional email/SMS mirror of a private message. Delivery is logged, and a
     * failure never rolls back the in-app copy that the recipient can always read.
     */
    private function mirror(Message $message, array $data): array
    {
        $recipient = $message->recipient;
        $out = ['email' => null, 'sms' => null];

        if (!empty($data['send_email'])) {
            if (!$recipient || blank($recipient->email)) {
                $out['email'] = 'skipped: recipient has no email address on file';
            } else {
                try {
                    $recipient->notify(new MessageReceived($message));
                    $out['email'] = 'queued via ' . config('mail.default');
                    CommunicationLog::create([
                        'organization_id' => $message->organization_id, 'campus_id' => $message->campus_id,
                        'channel' => 'email', 'recipient' => $recipient->email, 'subject' => $message->subject,
                        'status' => 'sent', 'provider' => (string) config('mail.default'), 'message_id' => $message->id,
                    ]);
                } catch (\Throwable $e) {
                    $out['email'] = 'failed: ' . $e->getMessage();
                    CommunicationLog::create([
                        'organization_id' => $message->organization_id, 'campus_id' => $message->campus_id,
                        'channel' => 'email', 'recipient' => $recipient->email, 'subject' => $message->subject,
                        'status' => 'failed', 'provider' => (string) config('mail.default'),
                        'error' => mb_substr($e->getMessage(), 0, 255), 'message_id' => $message->id,
                    ]);
                }
            }
        }

        if (!empty($data['send_sms'])) {
            $result = $this->sms->send($recipient?->phone, $message->subject . ': ' . str($message->body)->strip()->limit(160)->value(), [
                'subject' => $message->subject, 'message_id' => $message->id,
            ]);
            $out['sms'] = $result['status'] . ($result['error'] ? ': ' . $result['error'] : '');
        }

        return $out;
    }
}
