<?php

namespace Tests\Feature;

use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Tests\TestCase;

class LoginLogoSizeTest extends TestCase
{
    /**
     * Makes the current request either a normal page request or a Livewire update request sent from $pagePath.
     */
    private function requestFor(string $pagePath, bool $livewireUpdate = false): void
    {
        $request = $livewireUpdate
            ? Request::create('/livewire/update', 'POST', ['components' => [['snapshot' => json_encode(['memo' => ['path' => $pagePath, 'method' => 'GET']])]]])
            : Request::create('/'.$pagePath);

        if ($livewireUpdate) {
            $request->headers->set('X-Livewire', 'true');
        }

        $this->app->instance('request', $request);
    }

    private function logoHeight(): ?string
    {
        return Filament::getPanel('admin')->getBrandLogoHeight();
    }

    public function test_the_sign_in_page_shows_the_big_logo(): void
    {
        $this->requestFor('login');

        $this->assertTrue(AdminPanelProvider::isSignInPage());
        $this->assertSame('8rem', $this->logoHeight());
    }

    public function test_the_sign_in_page_keeps_the_big_logo_after_a_failed_sign_in(): void
    {
        // A failed sign-in redraws the page through a Livewire update request, whose own URL is not /login.
        $this->requestFor('login', livewireUpdate: true);

        $this->assertTrue(AdminPanelProvider::isSignInPage());
        $this->assertSame('8rem', $this->logoHeight());
    }

    public function test_the_forgot_and_reset_password_pages_show_the_big_logo(): void
    {
        foreach (['password-reset/request', 'password-reset/reset'] as $path) {
            $this->requestFor($path);
            $this->assertSame('8rem', $this->logoHeight(), $path);

            $this->requestFor($path, livewireUpdate: true);
            $this->assertSame('8rem', $this->logoHeight(), "{$path} after a Livewire update");
        }
    }

    public function test_every_other_page_shows_the_small_logo(): void
    {
        $this->requestFor('equipment');
        $this->assertSame('3rem', $this->logoHeight());

        $this->requestFor('equipment', livewireUpdate: true);
        $this->assertSame('3rem', $this->logoHeight());
    }
}
