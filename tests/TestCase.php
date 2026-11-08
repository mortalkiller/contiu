<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Filament builds the /register route from config('contiu.registration_open') as the app
     * boots, too early for a test to switch it: tests in the "registration-open" group boot the
     * app with public sign-up on, every other test with it off (whatever the local .env says).
     */
    public function createApplication()
    {
        $open = in_array('registration-open', $this->groups(), true) ? 'true' : 'false';
        $_ENV['CONTIU_REGISTRATION_OPEN'] = $_SERVER['CONTIU_REGISTRATION_OPEN'] = $open;

        return parent::createApplication();
    }
}
