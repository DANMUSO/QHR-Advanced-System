<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Request-lifetime caches survive between tests in one PHP process; each test starts with a fresh database.
        \App\Modules\Organization\Support\ApprovalChain::flushCache();
    }
}
