<?php

namespace App\Services;

use App\Models\Message;
use App\Models\StudentEntry;

/**
 * Turns a gate tap into the one thing a family is guaranteed to see: a message in
 * the parent app's inbox. Nothing leaves the system — no email, no SMS — because
 * the alert is an in-app thread the guardian can already reply to.
 *
 * One alert per child per direction per day. A second "In" tap at the same gate is
 * a correction, not news, so it writes the row and stays quiet.
 */
class GuardianNotifier
{
    /** @return bool whether the family was alerted by this call */
    public function entry(StudentEntry $entry): bool
    {
        if ($entry->parent_notified_at) return false;

        $entry->loadMissing(['student.guardianUser', 'student.section', 'student.schoolClass', 'campus']);
        $guardian = $entry->student?->guardianUser;
        if (!$guardian || !$guardian->is_active) return false;

        $date = $entry->at->toDateString();
        if (StudentEntry::alreadyNotifiedToday($entry->student_id, $entry->direction, $date)) return false;

        Message::create([
            'organization_id' => $entry->organization_id,
            'campus_id' => $entry->campus_id,
            'subject' => $this->subject($entry),
            'body' => $this->body($entry),
            'sender_id' => $entry->recorded_by,
            'recipient_id' => $guardian->id,
            'channel' => 'in_app',
            'send_email' => false,
            'send_sms' => false,
        ]);

        $entry->update(['parent_notified_at' => now()]);

        return true;
    }

    private function subject(StudentEntry $entry): string
    {
        return $this->name($entry) . ' ' . StudentEntry::verb($entry->direction) . ' the school';
    }

    private function body(StudentEntry $entry): string
    {
        $verb = StudentEntry::verb($entry->direction);
        $place = $entry->gate ?: ($entry->campus?->name ?? 'campus');

        $lines = [
            $this->name($entry) . ($this->className($entry) ? ' (' . $this->className($entry) . ')' : '')
                . " {$verb} {$place} at " . $entry->at->isoFormat('h:mm A')
                . ' on ' . $entry->at->isoFormat('dddd D MMMM YYYY') . '.',
        ];

        if ($entry->remarks) $lines[] = 'Note from the school: ' . $entry->remarks;
        $lines[] = 'This alert is sent automatically when the gate log or the class register records your child.';

        return implode("\n\n", $lines);
    }

    private function name(StudentEntry $entry): string
    {
        $s = $entry->student;

        return trim("$s->first_name $s->last_name");
    }

    private function className(StudentEntry $entry): string
    {
        return trim(($entry->student?->schoolClass?->name ?? '') . ' ' . ($entry->student?->section?->name ?? ''));
    }
}
