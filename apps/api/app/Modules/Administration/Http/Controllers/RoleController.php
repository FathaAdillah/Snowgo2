<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Models\User;
use App\Modules\Administration\RoleAccess;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Identity\Staff\StaffAccess;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RoleController
{
    private const CARD = 'array{id:int,name:string,display_name:string,description:string,revision:int,members:int,system:bool,permissions:list<string>,editable:bool,deletable:bool}';

    private const EMPTY = 'array{success:bool,message:string,data:null,meta:array{request_id:string}}';

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }

    #[Response(200, 'Staff role cards', type: 'array{success:bool,message:string,data:list<'.self::CARD.'>,meta:array{request_id:string}}')]
    public function index(Request $request): JsonResponse
    {
        $available = RoleAccess::assignable($this->actor($request));
        $roles = Role::query()->where('guard_name', 'web')->with('permissions')->withCount('users')->orderBy('name')->get();

        return ApiResponse::success($roles->map(fn (Role $role) => RoleAccess::card($role, $available)));
    }

    #[Response(200, 'Staff role', type: 'array{success:bool,message:string,data:'.self::CARD.',meta:array{request_id:string}}')]
    public function show(Request $request, int $id): JsonResponse
    {
        $role = Role::query()->where('guard_name', 'web')->with('permissions')->withCount('users')->findOrFail($id);

        return ApiResponse::success(RoleAccess::card($role, RoleAccess::assignable($this->actor($request))));
    }

    #[Response(200, 'Permission catalog', type: 'array{success:bool,message:string,data:list<array{name:string,section:string,resource:string,action:string,assignable:bool}>,meta:array{request_id:string}}')]
    public function catalog(Request $request): JsonResponse
    {
        return ApiResponse::success(RoleAccess::catalog($this->actor($request)));
    }

    #[Response(200, 'Assignable role options', type: 'array{success:bool,message:string,data:list<array{label:string,value:string}>,meta:array{request_id:string}}')]
    public function options(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $available = RoleAccess::assignable($actor);
        $super = StaffAccess::super($actor);

        return ApiResponse::success(Role::query()->where('guard_name', 'web')->with('permissions')->orderBy('name')->get()
            ->filter(fn (Role $role) => $super || RoleAccess::editable($role, $available))
            ->map(fn (Role $role) => ['value' => $role->name, 'label' => $role->getAttribute('display_name') ?? $role->name])->values());
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(201, 'Role created', type: self::EMPTY)]
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_]*\z/', Rule::notIn([StaffAccess::SUPER, ...StaffAccess::DEFAULT_ROLES])], ...$this->fields()]);

        return $this->mutate($request, 'create', $data);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Role updated', type: self::EMPTY)]
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', ...$this->fields()]);

        return $this->mutate($request, 'update', $data, $id);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Role deleted', type: self::EMPTY)]
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->mutate($request, 'delete', $request->validate(['revision' => 'required|integer|min:1']), $id);
    }

    /** @return array<string,string> */
    private function fields(): array
    {
        return ['display_name' => 'required|string|max:100', 'description' => 'nullable|string|max:500',
            'permissions' => 'present|array|list|max:1000', 'permissions.*' => 'required|string|max:125|distinct'];
    }

    /** @param array<string,mixed> $data */
    private function mutate(Request $request, string $action, array $data, ?int $id = null): JsonResponse
    {
        if ($request->hasAny(['bypasses_permissions', 'guard_name']) || ($action === 'update' && $request->has('name'))) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'Identitas role sistem tidak dapat diubah.');
        }
        $actor = $this->actor($request);
        $authorize = function () use ($request, $actor, $action) {
            abort_unless($actor->can('admin.roles.'.$action) && ($action === 'delete' || $actor->can('admin.roles.assign_permissions')), 403);
            if ($request->session()->get('staff.step_up', 0) <= now()->subMinutes(10)->timestamp) {
                throw new ApiProblem(403, 'STEP_UP_REQUIRED', 'Confirm password and a fresh TOTP code');
            }

            return 'user:'.$actor->id;
        };

        return app(IdempotentMutation::class)->run($request, $authorize, function () use ($actor, $action, $data, $id, $authorize) {
            DB::select('select pg_advisory_xact_lock(713007)');
            $actor->refresh();
            $authorize();
            $available = RoleAccess::assignable($actor);
            $query = Role::query()->where('guard_name', 'web');
            if ($action === 'create') {
                if ($query->where('name', $data['name'])->exists()) {
                    throw new ApiProblem(409, 'ROLE_CONFLICT', 'Kode role sudah digunakan.');
                }
                $role = new Role(['name' => $data['name'], 'guard_name' => 'web']);
            } else {
                $role = $query->lockForUpdate()->findOrFail($id);
                abort_unless(RoleAccess::editable($role, $available), 403);
                if ((int) $role->getAttribute('revision') !== (int) $data['revision']) {
                    throw new ApiProblem(409, 'ROLE_CONFLICT', 'Role telah berubah. Muat ulang sebelum menyimpan.');
                }
            }
            $before = $role->exists ? $role->permissions->pluck('name')->all() : [];
            if ($action === 'delete') {
                if (in_array($role->name, StaffAccess::DEFAULT_ROLES, true) || DB::table('model_has_roles')->where('role_id', $role->getKey())->exists()) {
                    throw new ApiProblem(409, 'ROLE_IN_USE', 'Role bawaan atau role yang sedang dipakai tidak dapat dihapus.');
                }
                $role->delete();
            } else {
                abort_if(array_diff($data['permissions'], $available) !== [], 403);
                $role->setAttribute('display_name', $data['display_name'])->setAttribute('description', $data['description'] ?? null)
                    ->setAttribute('revision', $action === 'create' ? 1 : (int) $data['revision'] + 1)->save();
                $role->syncPermissions($data['permissions']);
                if ($action === 'update' && (array_diff($before, $data['permissions']) || array_diff($data['permissions'], $before))) {
                    $members = User::query()->where('account_type', User::STAFF)->role($role);
                    $members->increment('staff_session_version');
                    DB::table('sessions')->whereIn('user_id', $members->select('id'))->delete();
                }
            }
            DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
            app(Journal::class)->audit('role.'.$action, Subject::Role, $role->getKey(), $actor->id,
                $action === 'create' ? null : ['role' => $role->name, 'grants' => implode(',', $before)],
                $action === 'delete' ? null : ['role' => $role->name, 'grants' => implode(',', $data['permissions'])]);
            app(Outbox::class)->record('RoleChanged', 'role', $role->getKey(), ['action' => $action, 'before' => $before, 'after' => $data['permissions'] ?? []]);

            return ApiResponse::success(null, 'OK', $action === 'create' ? 201 : 200);
        });
    }
}
