<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Every API answer carries the safety headers, including errors and refusals. */
class SecurityHeadersTest extends TestCase
{
    public function test_api_answers_carry_the_safety_headers(): void
    {
        foreach ([$this->getJson('/api/books'), $this->postJson('/api/login', [])] as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'no-referrer');

            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_strict_transport_security_is_sent_only_over_https(): void
    {
        $this->getJson('http://localhost/api/books')->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('https://localhost/api/books')->assertHeader('Strict-Transport-Security');
    }
}
