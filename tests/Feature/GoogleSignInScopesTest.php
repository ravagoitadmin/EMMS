<?php

namespace Tests\Feature;

use App\Models\AppUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GoogleSignInScopesTest extends TestCase
{
    private const MAILBOX = 'it.admin@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'activitylog.enabled' => false,
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => 'https://ems.test/auth/google/callback',
            'work_orders.gmail_account_email' => self::MAILBOX,
            // The company users directory lives on its own connection; here it is a second in-memory database.
            'database.connections.auth_db' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);

        Schema::connection('auth_db')->create('usr', function (Blueprint $table): void {
            $table->id('userId');
            $table->string('email');
            $table->tinyInteger('isActive')->default(1);
        });

        (require base_path('database/migrations/2026_04_24_054941_create_permission_tables.php'))->up();

        Schema::create('app_users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->string('user_fname')->nullable();
            $table->string('user_lname')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_avatar')->nullable();
            $table->unsignedBigInteger('user_dep_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('google_access_token')->nullable();
            $table->text('google_refresh_token')->nullable();
            $table->dateTime('google_token_expires_at')->nullable();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function user(string $email, ?string $refreshToken = null): AppUser
    {
        DB::connection('auth_db')->table('usr')->insert(['email' => $email, 'isActive' => 1]);

        return AppUser::create(['user_fname' => 'Test', 'user_lname' => 'User', 'user_email' => $email, 'is_active' => true, 'google_refresh_token' => $refreshToken]);
    }

    private function googleReturns(string $email): void
    {
        $googleUser = (new SocialiteUser)
            ->setToken('new-access-token')
            ->setRefreshToken('new-refresh-token')
            ->setExpiresIn(3600)
            ->map(['email' => $email, 'avatar' => null]);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /**
     * @return array<string, string>
     */
    private function redirectQuery(string $uri): array
    {
        parse_str((string) parse_url((string) $this->get($uri)->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_a_normal_sign_in_asks_only_for_name_and_email_and_never_forces_the_consent_screen(): void
    {
        $query = $this->redirectQuery('/auth/google');

        $this->assertSame(['openid', 'profile', 'email'], explode(' ', $query['scope']));
        $this->assertArrayNotHasKey('prompt', $query);
        $this->assertArrayNotHasKey('access_type', $query);
    }

    public function test_the_mailbox_link_asks_for_gmail_offline_access_for_the_mailbox_account(): void
    {
        $query = $this->redirectQuery('/auth/google/mailbox');

        $this->assertContains('https://www.googleapis.com/auth/gmail.modify', explode(' ', $query['scope']));
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame(self::MAILBOX, $query['login_hint']);
    }

    public function test_a_normal_sign_in_never_touches_the_mailbox_gmail_token(): void
    {
        $mailbox = $this->user(self::MAILBOX, refreshToken: 'working-refresh-token');
        $this->googleReturns(self::MAILBOX);

        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertAuthenticatedAs($mailbox);
        $mailbox->refresh();
        $this->assertSame('working-refresh-token', $mailbox->google_refresh_token);
        $this->assertNull($mailbox->google_access_token);
    }

    public function test_the_mailbox_link_saves_the_gmail_tokens_of_the_mailbox_account(): void
    {
        $mailbox = $this->user(self::MAILBOX);
        $this->googleReturns(self::MAILBOX);

        $this->get('/auth/google/mailbox');
        $this->get('/auth/google/callback')->assertRedirect();

        $mailbox->refresh();
        $this->assertSame('new-refresh-token', $mailbox->google_refresh_token);
        $this->assertSame('new-access-token', $mailbox->google_access_token);
    }

    public function test_another_account_using_the_mailbox_link_signs_in_but_keeps_no_gmail_token(): void
    {
        $other = $this->user('someone@example.test');
        $this->googleReturns('someone@example.test');

        $this->get('/auth/google/mailbox');
        $this->get('/auth/google/callback')->assertRedirect();

        $this->assertAuthenticatedAs($other);
        $this->assertNull($other->refresh()->google_refresh_token);
    }
}
