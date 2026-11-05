<?php

namespace App\Support;

use App\Models\Campus;
use App\Models\User;

/**
 * Per-request tenancy context: which organization and campus the current
 * request is operating on. Resolved by ResolveCampus middleware.
 */
class TenantContext
{
    public ?User $user = null;

    public ?Campus $campus = null; // active campus (null = cross-campus head-office view)

    public int $organizationId;

    public function __construct()
    {
        $this->organizationId = (int) (config('cms.default_organization_id') ?: 0);
    }

    public function campusId(): ?int
    {
        return $this->campus?->id;
    }

    public function scoped(?int $rowCampusId): bool
    {
        if (!$this->campus) return true; // head office cross-campus view
        return $rowCampusId === null || $rowCampusId === $this->campus->id;
    }
}
