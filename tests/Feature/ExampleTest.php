<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_front_page_goes_to_sign_in()
    {
        $this->get(route('home'))->assertRedirect('/login');
    }
}
