<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_root_is_not_a_resource(): void
    {
        $response = $this->get('/');

        // LWS-BE-04 : plus de page d'accueil, la racine répond 404.
        $response->assertStatus(404);
    }
}
