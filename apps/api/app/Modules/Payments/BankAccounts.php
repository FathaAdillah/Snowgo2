<?php

namespace App\Modules\Payments;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class BankAccounts
{
    public const FIELDS = ['id', 'code', 'bank_code', 'masked_number', 'holder_name', 'active'];

    /** @param array<string,mixed> $input */
    public static function save(?int $id, array $input, int $actor): object
    {
        $old = $id === null ? null : (DB::table('bank_accounts')->where('id', $id)->lockForUpdate()->first() ?? abort(404));
        if (isset($input['account_number'])) {
            $number = $input['account_number'];
            unset($input['account_number']);
            $input['masked_number'] = '****'.substr($number, -4);
            $input['account_ref_ciphertext'] = Crypt::encryptString($number);
            $input['account_ref_hash'] = hash_hmac('sha256', $number, config('app.key'));
        }
        if (isset($input['code']) && $old && $old->code !== $input['code']) {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Bank account codes are immutable.');
        }
        DB::statement('SELECT pg_advisory_xact_lock(713022)');
        $others = DB::table('bank_accounts')->when($id, fn ($q) => $q->where('id', '!=', $id));
        if ((clone $others)->where('code', $input['code'] ?? $old?->code)->exists()
            || $others->where(['bank_code' => $input['bank_code'] ?? $old->bank_code, 'account_ref_hash' => $input['account_ref_hash'] ?? $old->account_ref_hash])->exists()) {
            throw new ApiProblem(409, 'DUPLICATE_RESOURCE', 'Bank account already exists.');
        }
        if ($id === null) {
            $id = DB::table('bank_accounts')->insertGetId($input);
        } else {
            DB::table('bank_accounts')->where('id', $id)->update($input);
        }
        $bank = DB::table('bank_accounts')->select(self::FIELDS)->where('id', $id)->firstOrFail();
        app(Journal::class)->audit('BankAccountChanged', Subject::BankAccount, $id, $actor,  $old ? self::auditFields($old) : null, self::auditFields($bank));
        app(Outbox::class)->record('BankAccountChanged', 'bank_account', $id, ['code' => $bank->code]);

        return $bank;
    }

    /** @return array<string,mixed> */
    private static function auditFields(object $bank): array
    {
        return ['active' => $bank->active, 'bank_code' => $bank->bank_code, 'masked_number' => $bank->masked_number];
    }
}
