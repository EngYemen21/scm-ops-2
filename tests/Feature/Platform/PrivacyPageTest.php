<?php

namespace Tests\Feature\Platform;

use Tests\ApiTestCase;

/** The store listings link to /privacy: it must be public, bilingual and name a contact. */
class PrivacyPageTest extends ApiTestCase
{
    public function test_privacy_policy_is_public_and_complete(): void
    {
        $res = $this->get('/privacy');
        $res->assertOk();
        $res->assertSee('سياسة الخصوصية', false);
        $res->assertSee('Privacy Policy', false);
        $res->assertSee('mailto:'.config('app.privacy_contact'), false);
        $res->assertDontSee('id="app"', false); // not the SPA shell
    }
}
