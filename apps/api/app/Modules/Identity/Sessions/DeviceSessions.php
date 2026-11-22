<?php

namespace App\Modules\Identity\Sessions;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Identity\Surface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class DeviceSessions
{
    public const SURFACE_KEY = 'identity.surface';

    public function __construct(private readonly Journal $journal, private readonly Outbox $outbox) {}

    public static function surface(Request $request): ?Surface
    {
        return $request->hasSession() ? Surface::tryFrom((string) $request->session()->get(self::SURFACE_KEY)) : null;
    }

    public function start(Request $request, Surface $surface): void
    {
        $request->session()->put(self::SURFACE_KEY, $surface->value);
    }

    /** Sign out and rotate both the session ID and the CSRF token. */
    public function end(Request $request): void
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /**
     * @return array{items: list<array{session_ref: string, device_label: string, last_active_at: string, current: bool}>, total: int}
     */
    public function page(User $user, Surface $surface, string $currentId, int $page, int $perPage): array
    {
        $query = $this->active($user, $surface);
        $rows = (clone $query)->orderByDesc('last_activity')->orderBy('id')->forPage($page, $perPage)
            ->get(['id', 'user_agent', 'last_activity']);

        return ['total' => $query->count(), 'items' => $rows->map(fn (object $row) => [
            'session_ref' => $this->reference($row->id),
            'device_label' => self::label($row->user_agent),
            'last_active_at' => Carbon::createFromTimestamp($row->last_activity)->toISOString(),
            'current' => hash_equals($row->id, $currentId),
        ])->values()->all()];
    }

    /** Delete one of the user's sessions on the same surface; returns the raw ID or null. */
    public function revoke(User $user, Surface $surface, string $reference): ?string
    {
        return DB::transaction(function () use ($user, $surface, $reference) {
            $ids = $this->active($user, $surface)->lockForUpdate()->pluck('id');
            $id = $ids->first(fn (string $id) => hash_equals($this->reference($id), $reference));
            if (! is_string($id)) {
                return null;
            }
            DB::table('sessions')->where('id', $id)->delete();
            $this->journal->audit('identity.session_revoked', Subject::User, $user->id, $user->id);
            $this->outbox->record('SessionRevoked', 'user', $user->id, ['surface' => $surface->value]);

            return $id;
        });
    }

    public function revokeAll(int $userId): void
    {
        DB::table('sessions')->where('user_id', $userId)->delete();
    }

    /** Rows past the idle lifetime are dead even before session garbage collection deletes them. */
    private function active(User $user, Surface $surface): Builder
    {
        return DB::table('sessions')->where('user_id', $user->id)->where('surface', $surface->value)
            ->where('last_activity', '>', now()->subMinutes((int) config('session.lifetime'))->getTimestamp());
    }

    /** Opaque, stable per session; raw session IDs never leave the server. */
    private function reference(string $id): string
    {
        return substr(hash_hmac('sha256', 'session-ref:'.$id, (string) config('app.key')), 0, 32);
    }

    private static function label(?string $agent): string
    {
        $agent ??= '';
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $browser || $system ? trim(($browser ?? 'Browser').' · '.($system ?? 'Unknown OS')) : 'Unknown device';
    }
}
