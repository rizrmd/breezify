<?php

namespace App\Models;

use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OpenApi\Attributes as OA;
use Visus\Cuid2\Cuid2;

#[OA\Schema(
    description: 'Project model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'uuid' => ['type' => 'string'],
        'name' => ['type' => 'string'],
        'description' => ['type' => 'string'],
    ]
)]
class Project extends BaseModel
{
    use ClearsGlobalSearchCache;
    use HasFactory;
    use HasSafeStringAttribute;

    protected $guarded = [];

    /**
     * Get query builder for projects owned by current team.
     * If you need all projects without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return Project::whereTeamId(currentTeam()->id)->orderByRaw('LOWER(name)');
    }

    /**
     * Get all projects owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return Project::ownedByCurrentTeam()->get();
        });
    }

    protected static function booted()
    {
        static::created(function ($project) {
            ProjectSetting::create([
                'project_id' => $project->id,
            ]);
            Environment::create([
                'name' => 'production',
                'project_id' => $project->id,
                'uuid' => (string) new Cuid2,
            ]);
        });
        static::deleting(function ($project) {
            $project->environments()->delete();
            $project->settings()->delete();
            $shared_variables = $project->environment_variables();
            foreach ($shared_variables as $shared_variable) {
                $shared_variable->delete();
            }
            // Remove this project from all permissions
            removeProjectFromPermissions($project->id);
        });
    }

    public function environment_variables()
    {
        return $this->hasMany(SharedEnvironmentVariable::class);
    }

    public function environments()
    {
        return $this->hasMany(Environment::class);
    }

    public function settings()
    {
        return $this->hasOne(ProjectSetting::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function services()
    {
        return $this->hasManyThrough(Service::class, Environment::class);
    }

    public function applications()
    {
        return $this->hasManyThrough(Application::class, Environment::class);
    }

    public function postgresqls()
    {
        return $this->hasManyThrough(StandalonePostgresql::class, Environment::class);
    }

    public function redis()
    {
        return $this->hasManyThrough(StandaloneRedis::class, Environment::class);
    }

    public function keydbs()
    {
        return $this->hasManyThrough(StandaloneKeydb::class, Environment::class);
    }

    public function dragonflies()
    {
        return $this->hasManyThrough(StandaloneDragonfly::class, Environment::class);
    }

    public function clickhouses()
    {
        return $this->hasManyThrough(StandaloneClickhouse::class, Environment::class);
    }

    public function mongodbs()
    {
        return $this->hasManyThrough(StandaloneMongodb::class, Environment::class);
    }

    public function mysqls()
    {
        return $this->hasManyThrough(StandaloneMysql::class, Environment::class);
    }

    public function mariadbs()
    {
        return $this->hasManyThrough(StandaloneMariadb::class, Environment::class);
    }

    public function isEmpty()
    {
        return $this->applications()->count() == 0 &&
            $this->redis()->count() == 0 &&
            $this->postgresqls()->count() == 0 &&
            $this->mysqls()->count() == 0 &&
            $this->keydbs()->count() == 0 &&
            $this->dragonflies()->count() == 0 &&
            $this->clickhouses()->count() == 0 &&
            $this->mariadbs()->count() == 0 &&
            $this->mongodbs()->count() == 0 &&
            $this->services()->count() == 0;
    }

    public function databases()
    {
        return $this->postgresqls()->get()->merge($this->redis()->get())->merge($this->mongodbs()->get())->merge($this->mysqls()->get())->merge($this->mariadbs()->get())->merge($this->keydbs()->get())->merge($this->dragonflies()->get())->merge($this->clickhouses()->get());
    }

    public function navigateTo()
    {
        if ($this->environments->count() === 1) {
            return route('project.resource.index', [
                'project_uuid' => $this->uuid,
                'environment_uuid' => $this->environments->first()->uuid,
            ]);
        }

        return route('project.show', ['project_uuid' => $this->uuid]);
    }

    /**
     * Check if project is accessible by user
     * Respects configuration-based project permissions
     */
    public function isAccessibleBy(User $user): bool
    {
        // 1. User must be member of project's team
        if ($user->teams()->where('teams.id', $this->team_id)->doesntExist()) {
            return false;
        }

        // 2. Team admins/owners always have access
        if ($user->isAdminOfTeam($this->team_id)) {
            return true;
        }

        // 3. Check configuration-based permissions
        return canUserAccessProject($this->team_id, $user->id, $this->id);
    }

    /**
     * Get users with access to this project
     */
    public function accessibleUsers(): Collection
    {
        $team = $this->team;
        $allMembers = $team->members;

        return $allMembers->filter(function ($user) {
            return $this->isAccessibleBy($user);
        });
    }

    /**
     * Scope: Get projects accessible by current user
     * Respects configuration-based project permissions
     */
    public function scopeAccessibleBy(Builder $query, User $user, ?int $teamId = null): Builder
    {
        $teamIds = collect();

        if ($teamId) {
            $teamIds->push($teamId);
        }

        if ($currentTeamId = currentTeam()?->id) {
            $teamIds->push($currentTeamId);
        }

        if ($teamIds->isEmpty()) {
            $teamIds = $user->teams->pluck('id');
        }

        $teamIds = $teamIds->filter()->unique();

        if ($teamIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $teamScopedQuery) use ($teamIds, $user) {
            foreach ($teamIds as $candidateTeamId) {
                $teamScopedQuery->orWhere(function (Builder $teamQuery) use ($user, $candidateTeamId) {
                    if ($user->isAdminOfTeam($candidateTeamId)) {
                        $teamQuery->where('team_id', $candidateTeamId);

                        return;
                    }

                    if (! hasRestrictedProjectAccess($candidateTeamId, $user->id)) {
                        $teamQuery->where('team_id', $candidateTeamId);

                        return;
                    }

                    $allowedIds = getAllowedProjectIds($candidateTeamId, $user->id);

                    $teamQuery->where('team_id', $candidateTeamId)
                        ->whereIn('id', $allowedIds);
                });
            }
        });
    }
}
