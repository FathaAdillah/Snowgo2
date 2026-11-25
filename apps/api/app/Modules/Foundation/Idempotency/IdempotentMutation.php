<?php

namespace App\Modules\Foundation\Idempotency;

use App\Support\Http\ApiResponse;
use App\Support\Http\RequestId;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class IdempotentMutation
{
    /**
     * @param  Closure(): string  $authorize  Checks current ownership/permission and returns a server-derived actor scope.
     * @param  Closure(): JsonResponse  $mutate  PostgreSQL changes only; return a terminal API envelope.
     */
    public function run(Request $request, Closure $authorize, Closure $mutate): JsonResponse
    {
        return $this->runPrepared($request, $authorize, fn () => $mutate);
    }

    /**
     * @param  Closure():string  $authorize
     * @param  Closure():(Closure():JsonResponse)  $prepare  Stage external input only after a new claim, then return the DB mutation.
     */
    public function runPrepared(Request $request, Closure $authorize, Closure $prepare): JsonResponse
    {
        $actor = $authorize();
        if ($actor === '' || ! $request->route() || DB::transactionLevel() !== 0) {
            throw new LogicException('An authorized route outside a transaction is required.');
        }
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/\A[!-~]{1,128}\z/', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Use 1–128 visible ASCII characters.']);
        }
        $scope = ['actor_scope' => $actor, 'operation' => $request->method().' '.$request->route()->uri(), 'key' => $key];
        $hash = RequestFingerprint::hash($request);
        $token = (string) Str::uuid();
        $row = DB::table('idempotency_keys')->where($scope);
        try {
            $response = DB::transaction(fn () => $this->claim($row, $scope, $hash, $token));
        } catch (QueryException $e) {
            if ($e->getCode() !== '55P03') {
                throw $e;
            }

            // A running mutation holds the row lock, but its committed fingerprint is still readable.
            $different = (clone $row)->where('expires_at', '>', now())->where('request_hash', '!=', $hash)->exists();

            return $different ? $this->reused() : $this->busy();
        }
        if ($response !== null) {
            return $response;
        }

        $owned = (clone $row)->where('lease_token', $token);
        try {
            $mutate = $prepare();

            return DB::transaction(function () use ($owned, $mutate) {
                if (! (clone $owned)->lockForUpdate()->first()) {
                    return $this->busy();
                }

                // Roll back even partial domain writes on an error response; cache only the terminal outcome.
                try {
                    $response = DB::transaction(function () use ($mutate) {
                        $result = $mutate();
                        if (! $result->isSuccessful()) {
                            throw new HttpResponseException($result);
                        }

                        return $result;
                    });
                } catch (HttpResponseException $e) {
                    $response = $e->getResponse();
                    if (! $response instanceof JsonResponse) {
                        throw $e;
                    }
                }
                $status = $response->getStatusCode();
                if ($status >= 500 || in_array($status, [408, 425, 429], true)) {
                    $owned->delete();

                    return $response;
                }
                $owned->update([
                    'status' => 'COMPLETED', 'lease_until' => null, 'lease_token' => null,
                    'http_status' => $status, 'response_ciphertext' => Crypt::encryptString((string) $response->getContent()),
                    'expires_at' => now()->addHours(48), 'updated_at' => now(),
                ]);

                return $response;
            });
        } catch (Throwable $e) {
            $owned->delete();
            throw $e;
        }
    }

    /** @param array<string,string> $scope */
    private function claim(Builder $row, array $scope, string $hash, string $token): ?JsonResponse
    {
        // Serialize first insert without waiting for a conflicting, uncommitted unique key.
        if (! DB::scalar('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))', [json_encode($scope, JSON_THROW_ON_ERROR)])) {
            return $this->busy();
        }
        $existing = (clone $row)->lock('FOR UPDATE NOWAIT')->first();
        if ($existing && now()->lt($existing->expires_at)) {
            if ($existing->request_hash !== $hash) {
                return $this->reused();
            }
            if ($existing->status === 'COMPLETED') {
                $body = json_decode(Crypt::decryptString($existing->response_ciphertext), flags: JSON_THROW_ON_ERROR);
                $body->meta->request_id = RequestId::current();

                return new JsonResponse($body, $existing->http_status);
            }
            if (now()->lt($existing->lease_until)) {
                return $this->busy();
            }
        }
        $values = [
            'request_hash' => $hash, 'status' => 'PROCESSING', 'lease_token' => $token,
            'lease_until' => now()->addMinute(), 'expires_at' => now()->addHours(48),
            'http_status' => null, 'response_ciphertext' => null, 'updated_at' => now(),
        ];
        if ($existing) {
            $row->update($values);
        } else {
            DB::table('idempotency_keys')->insert($scope + $values);
        }

        return null;
    }

    private function busy(): JsonResponse
    {
        return ApiResponse::error('REQUEST_IN_PROGRESS', 'Request in progress', 409);
    }

    private function reused(): JsonResponse
    {
        return ApiResponse::error('IDEMPOTENCY_KEY_REUSED', 'Key already used for a different request', 422);
    }
}
