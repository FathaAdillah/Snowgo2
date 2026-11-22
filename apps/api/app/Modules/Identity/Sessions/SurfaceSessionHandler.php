<?php

namespace App\Modules\Identity\Sessions;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * A session row belongs to a user only after Identity established it on a surface (DB-AUTH-003).
 */
final class SurfaceSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function addUserInformation(&$payload): static
    {
        $surface = $this->container->make('session')->driver()->get(DeviceSessions::SURFACE_KEY);
        $userId = is_string($surface) ? $this->container->make('auth')->guard()->id() : null;
        $payload['user_id'] = $userId;
        $payload['surface'] = $userId === null ? null : $surface;

        return $this;
    }
}
