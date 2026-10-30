<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_switching_to_english_uses_ltr_and_translated_labels(): void
    {
        $this->post('/locale', ['locale' => 'en'])->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('lang="en"', false)->assertSee('dir="ltr"', false)->assertSee('Sign in');
    }

    public function test_switching_to_arabic_uses_rtl_and_translated_labels(): void
    {
        $this->post('/locale', ['locale' => 'ar'])->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false)->assertSee('تسجيل الدخول');
    }

    public function test_unsupported_locales_are_rejected(): void
    {
        $this->post('/locale', ['locale' => 'invalid'])->assertSessionHasErrors('locale');
    }
}
