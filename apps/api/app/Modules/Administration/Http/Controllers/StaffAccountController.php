<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Identity\Staff\StaffAccess;
use App\Modules\Identity\Staff\StaffInvitations;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

final class StaffAccountController
{
    #[Response(200, 'Staff accounts', type: 'array{success:bool,message:string,data:list<array{id:int,email:string,display_name:string|null,active:bool,mfa_confirmed:bool,manageable:bool,roles:list<string>}>,meta:array{request_id:string,page:int,last_page:int}}')]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'search' => 'nullable|string|max:100']);
        $query = User::query()->where('account_type', User::STAFF)->with(['permissions', 'roles.permissions']);
        if (! empty($data['search'])) {
            $term = '%'.addcslashes($data['search'], '\\%_').'%';
            $query->where(fn ($q) => $q->whereLike('email', $term)->orWhereLike('staff_display_name', $term));
        }
        $page = $query->orderBy('id')->paginate(25);
        $actor = $request->user();
        assert($actor instanceof User);
        $super = StaffAccess::super($actor);
        $available = $super ? [] : StaffAccess::permissions($actor);

        return ApiResponse::success($page->getCollection()->map(fn (User $user) => [
            'id' => $user->id, 'email' => $user->email, 'display_name' => $user->getAttribute('staff_display_name'),
            'mfa_confirmed' => $user->two_factor_confirmed_at !== null, 'active' => $user->disabled_at === null, 'roles' => $user->getRoleNames(),
            'manageable' => $actor->id !== $user->id && ($super || (! StaffAccess::super($user) && ! array_diff(StaffAccess::permissions($user), $available))),
        ]), meta: ['page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(201, 'Invitation queued', type: 'array{success:bool,message:string,data:null,meta:array{request_id:string}}')]
    public function create(Request $request, IdempotentMutation $guard, StaffInvitations $invitations): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:254', 'display_name' => 'nullable|string|max:100', 'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')]]);
        $actor = $request->user();
        assert($actor instanceof User);
        $authorize = function () use ($actor, $data, $request) {
            $this->stepUp($request);
            abort_unless($actor->can('admin.staff.create') && $actor->can('admin.staff.assign_roles'), 403);
            $role = Role::findByName($data['role'], 'web');
            abort_unless(StaffAccess::super($actor) || ($role->name !== StaffAccess::SUPER
                && $role->permissions->pluck('name')->diff(StaffAccess::permissions($actor))->isEmpty()), 403);

            return 'user:'.$actor->id;
        };

        return $guard->run($request, $authorize, function () use ($actor, $authorize, $data, $invitations) {
            DB::select('select pg_advisory_xact_lock(713007)');
            $actor->refresh();
            $authorize();
            $invited = $invitations->invite($data['email'], $data['role'], $actor->id);
            $this->delegable($invited, StaffAccess::super($actor), StaffAccess::permissions($actor));
            if (isset($data['display_name'])) {
                $invited->setAttribute('staff_display_name', $data['display_name'])->save();
            }

            return ApiResponse::success(null, 'Invitation queued', 201);
        });
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Staff sessions revoked', type: 'array{success:bool,message:string,data:null,meta:array{request_id:string}}')]
    public function revoke(Request $request, int $id, IdempotentMutation $guard, DeviceSessions $sessions): JsonResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);
        $authorize = function () use ($actor, $request, $id) {
            $this->stepUp($request);
            abort_unless($actor->can('admin.staff.sessions.delete'), 403);
            abort_if($actor->id === $id, 403);
            $this->delegable($this->target($id), StaffAccess::super($actor), StaffAccess::permissions($actor));

            return 'user:'.$actor->id;
        };

        return $guard->run($request, $authorize, function () use ($actor, $authorize, $id, $sessions) {
            DB::select('select pg_advisory_xact_lock(713007)');
            $actor->refresh();
            $authorize();
            $this->target($id)->increment('staff_session_version');
            $sessions->revokeAll($id);
            app(Journal::class)->audit('staff.sessions_revoked', Subject::User, $id, $actor->id);
            app(Outbox::class)->record('StaffSessionsRevoked', 'user', $id, []);

            return ApiResponse::success();
        });
    }

    private function target(int $id): User
    {
        return User::query()->where('account_type', User::STAFF)->findOrFail($id);
    }

    private function stepUp(Request $request): void
    {
        if ($request->session()->get('staff.step_up', 0) <= now()->subMinutes(10)->timestamp) {
            throw new ApiProblem(403, 'STEP_UP_REQUIRED', 'Confirm password and a fresh TOTP code');
        }
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[Response(200, 'Staff updated', type: 'array{success:bool,message:string,data:array{id:int,email:string,active:bool,mfa_confirmed:bool,roles:list<string>},meta:array{request_id:string}}')]
    public function update(Request $request, int $id, IdempotentMutation $guard, DeviceSessions $sessions): JsonResponse
    {
        $data = $request->validate(['active' => 'required_without_all:roles,display_name|boolean', 'roles' => 'required_without_all:active,display_name|array|min:1',
            'display_name' => 'required_without_all:active,roles|string|max:100',
            'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')->where('guard_name', 'web')]]);
        $actor = $request->user();
        assert($actor instanceof User);
        $authorize = function () use ($request, $actor, $data, $id) {
            abort_unless($actor->can('admin.staff.update') && (! isset($data['roles']) || $actor->can('admin.staff.assign_roles'))
                && (! isset($data['active']) || $actor->can('admin.staff.change_status')), 403);
            $this->stepUp($request);
            if ($actor->id === $id) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Tidak dapat mengubah akun sendiri di manajemen staf.');
            }
            $this->delegable($this->target($id), StaffAccess::super($actor), StaffAccess::permissions($actor));

            return 'user:'.$actor->id;
        };

        return $guard->run($request, $authorize, function () use ($data, $actor, $id, $authorize, $sessions) {
            DB::select('select pg_advisory_xact_lock(713007)');
            $actor->refresh();
            $authorize();
            $target = User::query()->where('account_type', User::STAFF)->lockForUpdate()->findOrFail($id);
            $super = StaffAccess::super($actor);
            $available = $super ? [] : StaffAccess::permissions($actor);
            $this->delegable($target, $super, $available);
            $wasManager = $this->manager($target);
            if (isset($data['roles'])) {
                if (! $super) {
                    $requested = Role::query()->where('guard_name', 'web')->whereIn('name', $data['roles'])->with('permissions')->get();
                    abort_unless($requested->count() === count($data['roles']) && $requested->every(fn (Role $role) => $role->name !== StaffAccess::SUPER && $role->permissions->pluck('name')->diff($available)->isEmpty()), 403);
                }
                $target->syncRoles($data['roles']);
                $this->delegable($target, $super, $available);
            }
            if (array_key_exists('active', $data)) {
                $target->disabled_at = $data['active'] ? null : now();
                $target->save();
            }
            if (isset($data['display_name'])) {
                $target->setAttribute('staff_display_name', $data['display_name'])->save();
            }
            if ($wasManager && ! $this->manager($target)
                && ! User::query()->whereKeyNot($id)->where('account_type', User::STAFF)->whereNull('disabled_at')
                    ->whereNotNull('two_factor_confirmed_at')->whereHas('roles', fn ($query) => $query->where('bypasses_permissions', true))->exists()) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Cannot remove the last active staff administrator');
            }
            $target->increment('staff_session_version');
            $sessions->revokeAll($id);
            app(Journal::class)->audit('staff.changed', Subject::User, $id, $actor->id, after: ['active' => $target->disabled_at === null,
                'role' => $target->getRoleNames()->sort()->implode(',')]);
            app(Outbox::class)->record('StaffChanged', 'user', $id, []);

            return ApiResponse::success(['id' => $id, 'email' => $target->email, 'active' => $target->disabled_at === null,
                'mfa_confirmed' => $target->two_factor_confirmed_at !== null, 'roles' => $target->getRoleNames()]);
        });
    }

    /** @param list<string> $available */
    private function delegable(User $target, bool $super, array $available): void
    {
        abort_unless($super || (! StaffAccess::super($target) && ! array_diff(StaffAccess::permissions($target), $available)), 403);
    }

    private function manager(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->disabled_at === null
            && StaffAccess::super($user);
    }
}
