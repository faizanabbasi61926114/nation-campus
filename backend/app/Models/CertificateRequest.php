<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateRequest extends Model
{
    use BelongsToTenant, Auditable;

    protected $guarded = [];

    public const TYPES = ['bonafide', 'transfer', 'character', 'marksheet', 'internship', 'other'];
    public const STATUSES = ['pending', 'approved', 'rejected', 'issued'];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'decided_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The trailing serial number of a reference such as CERT/LHR/0007. */
    public static function serialOf(string $reference): int
    {
        $tail = substr($reference, strrpos($reference, '/') === false ? 0 : strrpos($reference, '/') + 1);

        return (int) $tail;
    }

    /** Anything past the pending stage has been decided, and a decision is final. */
    public function isDecided(): bool
    {
        return in_array($this->status, ['approved', 'rejected', 'issued'], true);
    }

    public function studentName(): string
    {
        return trim(($this->student?->first_name ?? '') . ' ' . ($this->student?->last_name ?? '')) ?: 'the student';
    }
}
