<?php

namespace Tests;

use Database\Seeders\OperationalDefaultsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Restore the reference rows created by migrations after DatabaseTruncation. */
    protected string $seeder = OperationalDefaultsSeeder::class;
}
