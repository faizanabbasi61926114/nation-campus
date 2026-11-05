<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Support\Auditable;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, Auditable;

    protected $fillable = [
        'organization_id', 'campus_id', 'name', 'email', 'password',
        'phone', 'cnic', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token', 'cnic'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function roleAssignments(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('campus_id');
    }

    /**
     * Students this user is the registered guardian of — the family-scoped
     * counterpart to the campus-scoped queries everywhere else. Callers that
     * read across campuses must drop the tenant scope themselves.
     */
    public function wards(): HasMany
    {
        return $this->hasMany(Student::class, 'guardian_user_id');
    }

    /** This user's children in their own organization, regardless of active campus. */
    public function wardQuery(): Builder
    {
        return Student::withoutGlobalScopes()
            ->where('organization_id', $this->organization_id)
            ->where('guardian_user_id', $this->id);
    }

    /** Flat list of this user's role slugs. */
    public function roleSlugs(): array
    {
        return $this->roleAssignments->pluck('slug')->all();
    }

    /** All permission names granted to the user across roles. */
    public function permissionsList(): array
    {
        $roleIds = $this->roleAssignments->pluck('pivot.role_id');
        if ($roleIds->isEmpty()) return [];
        return \DB::table('permission_role')->whereIn('role_id', $roleIds)->distinct()->pluck('permission_id')->all();
    }

    public function hasPermission(string $perm): bool
    {
        $perms = $this->permissionsList();
        return in_array('*', $perms, true) || in_array($perm, $perms, true);
    }

    /** Campuses this user may act on. Empty campus_id pivot = all org campuses. */
    public function accessibleCampusIds(): array
    {
        if (!$this->organization_id) return [];
        $scoped = $this->roleAssignments->pluck('pivot.campus_id')->filter()->unique()->values()->all();
        $unscoped = $this->roleAssignments->pluck('pivot.campus_id')->contains(null);
        if ($unscoped || !$this->campus_id) {
            return Campus::where('organization_id', $this->organization_id)->pluck('id')->all();
        }
        return $scoped ?: [$this->campus_id];
    }

    public function isHeadOffice(): bool
    {
        return $this->roleAssignments->contains(fn ($r) => in_array($r->slug, ['super_admin', 'head_office_admin', 'finance_director'], true));
    }
}
