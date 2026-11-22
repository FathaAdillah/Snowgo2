<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-AUTH-001/003/004 and the email verification challenge (docs/04-DATABASE-SCHEMA.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS citext;

            ALTER TABLE users DROP COLUMN name;
            ALTER TABLE users RENAME COLUMN password TO password_hash;
            ALTER TABLE users
                ALTER COLUMN password_hash TYPE text, ALTER COLUMN password_hash DROP NOT NULL,
                ALTER COLUMN email TYPE citext,
                ALTER COLUMN email_verified_at TYPE timestamptz USING email_verified_at AT TIME ZONE 'UTC',
                ADD COLUMN account_type varchar(16) NOT NULL DEFAULT 'CUSTOMER' CHECK (account_type IN ('CUSTOMER','STAFF')),
                ADD COLUMN disabled_at timestamptz;
            ALTER TABLE users ALTER COLUMN account_type DROP DEFAULT;
            -- Pre-Argon2id (bcrypt) starter hashes cannot be verified by the hasher; those accounts reset their password.
            UPDATE users SET password_hash = NULL WHERE password_hash NOT LIKE '$argon2id$%';
            CREATE INDEX users_account_type_disabled_at_index ON users (account_type, disabled_at);

            -- Sessions written before surfaces existed cannot be attributed safely.
            DELETE FROM sessions;
            DROP INDEX sessions_user_id_index;
            ALTER TABLE sessions
                ALTER COLUMN ip_address TYPE inet USING NULLIF(ip_address, '')::inet,
                ADD COLUMN surface varchar(16) CHECK (surface IN ('CUSTOMER','STAFF')),
                ADD CONSTRAINT sessions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users ON DELETE CASCADE,
                ADD CONSTRAINT sessions_surface_when_authenticated CHECK (user_id IS NULL OR surface IS NOT NULL);
            CREATE INDEX sessions_user_id_surface_index ON sessions (user_id, surface);

            DELETE FROM password_reset_tokens;
            ALTER TABLE password_reset_tokens
                ALTER COLUMN email TYPE citext,
                ALTER COLUMN created_at TYPE timestamptz USING created_at AT TIME ZONE 'UTC',
                ALTER COLUMN created_at SET DEFAULT now(), ALTER COLUMN created_at SET NOT NULL,
                ADD COLUMN delivery_ciphertext text;
            CREATE INDEX password_reset_tokens_created_at_index ON password_reset_tokens (created_at);

            CREATE TABLE email_verification_tokens (
                user_id bigint PRIMARY KEY REFERENCES users ON DELETE CASCADE,
                token_hash char(64) NOT NULL UNIQUE, delivery_ciphertext text,
                expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX email_verification_tokens_expires_at_index ON email_verification_tokens (expires_at);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE email_verification_tokens;

            DROP INDEX password_reset_tokens_created_at_index;
            DELETE FROM password_reset_tokens;
            ALTER TABLE password_reset_tokens DROP COLUMN delivery_ciphertext,
                ALTER COLUMN email TYPE varchar(255),
                ALTER COLUMN created_at DROP NOT NULL, ALTER COLUMN created_at DROP DEFAULT,
                ALTER COLUMN created_at TYPE timestamp(0) without time zone;

            DELETE FROM sessions;
            DROP INDEX sessions_user_id_surface_index;
            ALTER TABLE sessions DROP CONSTRAINT sessions_surface_when_authenticated,
                DROP CONSTRAINT sessions_user_id_foreign, DROP COLUMN surface,
                ALTER COLUMN ip_address TYPE varchar(45);
            CREATE INDEX sessions_user_id_index ON sessions (user_id);

            DROP INDEX users_account_type_disabled_at_index;
            ALTER TABLE users DROP COLUMN disabled_at, DROP COLUMN account_type,
                ALTER COLUMN email_verified_at TYPE timestamp(0) without time zone,
                ALTER COLUMN email TYPE varchar(255);
            UPDATE users SET password_hash = '' WHERE password_hash IS NULL;
            ALTER TABLE users ALTER COLUMN password_hash TYPE varchar(255), ALTER COLUMN password_hash SET NOT NULL;
            ALTER TABLE users RENAME COLUMN password_hash TO password;
            ALTER TABLE users ADD COLUMN name varchar(255) NOT NULL DEFAULT '';
            ALTER TABLE users ALTER COLUMN name DROP DEFAULT;
            SQL);
    }
};
