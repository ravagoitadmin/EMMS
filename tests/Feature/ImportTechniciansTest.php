<?php

namespace Tests\Feature;

use App\Models\AppUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ImportTechniciansTest extends TestCase
{
    private const HEADER = "first_name,middle_name,last_name,email,contact_no\n";

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        config(['activitylog.enabled' => false]);

        (require base_path('database/migrations/2026_04_24_054941_create_permission_tables.php'))->up();
        (require base_path('database/migrations/2026_06_18_144211_add_display_name_to_roles_table.php'))->up();

        Schema::create('app_users', function (Blueprint $table): void {
            $table->id('user_id');
            $table->string('user_fname')->nullable();
            $table->string('user_mname')->nullable();
            $table->string('user_lname')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_contact_no')->nullable();
            $table->unsignedBigInteger('user_dep_id')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('departments', function (Blueprint $table): void {
            $table->id('dep_id');
            $table->string('dep_code')->nullable();
            $table->string('dep_name')->nullable();
        });
        DB::table('departments')->insert(['dep_id' => 3, 'dep_code' => 'ELEC', 'dep_name' => 'Electrical']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->directory = sys_get_temp_dir().'/ems-technicians-test-'.uniqid();
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function csv(string $rows, string $header = self::HEADER): string
    {
        $path = $this->directory.'/technicians_'.uniqid().'.csv';
        File::put($path, $header.$rows);

        return $path;
    }

    public function test_the_preview_shows_the_tidied_names_and_adds_nothing(): void
    {
        $file = $this->csv("MARK DAVE,CANDILAZA,ALGARME ,Markdave@Gmail.com ,0991 124 8561\n");

        $this->artisan('technicians:import', ['source' => $file, '--department' => 'elec'])
            ->expectsOutputToContain('Department: Electrical (ELEC)')
            // One table row can satisfy only one expectation, so the whole row is checked at once.
            ->expectsOutputToContain('| Mark Dave  | Candilaza   | Algarme   | markdave@gmail.com | 09911248561 |')
            ->expectsOutputToContain('Preview only')
            ->assertSuccessful();

        $this->assertSame(0, AppUser::query()->count());
    }

    public function test_execute_adds_active_technicians_to_the_department(): void
    {
        $file = $this->csv(
            "JELBERT,DEL ROSARIO,BELACSI,j.belacsi86@gmail.com,09451098493\n".
            "ROBERT,CASTILLO,MEDALLE JR,robertmedalle8@gmail.com,+639929957156\n".
            "JACK,,GUERRERO,jackguerrero25@gmail.com,\n"
        );

        $this->artisan('technicians:import', ['source' => $file, '--department' => '3', '--execute' => true, '--force' => true])
            ->expectsOutputToContain('Added 3 technicians')
            ->assertSuccessful();

        $jelbert = AppUser::query()->where('user_email', 'j.belacsi86@gmail.com')->firstOrFail();
        $this->assertSame(['Jelbert', 'Del Rosario', 'Belacsi', '09451098493', 3, true], [
            $jelbert->user_fname, $jelbert->user_mname, $jelbert->user_lname, $jelbert->user_contact_no, (int) $jelbert->user_dep_id, $jelbert->is_active,
        ]);
        $this->assertTrue($jelbert->hasRole('technician'));

        $robert = AppUser::query()->where('user_email', 'robertmedalle8@gmail.com')->firstOrFail();
        $this->assertSame(['Medalle Jr.', '09929957156'], [$robert->user_lname, $robert->user_contact_no]);

        $jack = AppUser::query()->where('user_email', 'jackguerrero25@gmail.com')->firstOrFail();
        $this->assertNull($jack->user_mname);
        $this->assertNull($jack->user_contact_no);
    }

    public function test_invalid_emails_numbers_and_duplicates_are_skipped_and_the_rest_is_added(): void
    {
        AppUser::query()->create(['user_fname' => 'Lordwin', 'user_lname' => 'Cuca', 'user_email' => 'lordwincuca28@gmail.com', 'is_active' => true]);

        $file = $this->csv(
            "CHARLIE,LESTINO,CUYAG,charliecuyag116@gmail,09531962824\n".
            "MICHAEL,FRIAS,MANONGSONG,manongsong10@gmail.com,0939052099\n".
            "LORDWIN,SOBIOR,CUCA,Lordwincuca28@gmail.com,09276230348\n".
            "JERICHO,MANONGSONG,CID,jerichocid075@gmail.com,09665495595\n".
            "JERICHO,MANONGSONG,CID,JerichoCid075@gmail.com,09665495595\n".
            ",,,,\n".
            "KARL GABRIEL,BORNASAL,,karlgab05@gmail.com,09956505012\n"
        );

        $this->artisan('technicians:import', ['source' => $file, '--department' => 'ELEC', '--execute' => true, '--force' => true])
            ->expectsOutputToContain('Skipped line 2 (Charlie Cuyag): email "charliecuyag116@gmail" is not a valid address')
            ->expectsOutputToContain('Skipped line 3 (Michael Manongsong): contact number "0939052099" is not 11 digits starting with 09')
            ->expectsOutputToContain('Skipped line 4 (Lordwin Cuca): already in EMS as Lordwin Cuca')
            ->expectsOutputToContain('Skipped line 6 (Jericho Cid): email listed twice in the file')
            ->expectsOutputToContain('Skipped line 8 (Karl Gabriel): no last name')
            ->expectsOutputToContain('Added 1 technicians')
            ->assertSuccessful();

        $this->assertSame(['lordwincuca28@gmail.com', 'jerichocid075@gmail.com'], AppUser::query()->orderBy('user_id')->pluck('user_email')->all());
    }

    public function test_a_missing_department_or_column_stops_before_anything_is_read_or_added(): void
    {
        $file = $this->csv("JACK,AYES,GUERRERO,jackguerrero25@gmail.com,09954713952\n");

        $this->artisan('technicians:import', ['source' => $file, '--execute' => true, '--force' => true])
            ->expectsOutputToContain('Give the department with --department')
            ->assertFailed();

        $this->artisan('technicians:import', ['source' => $file, '--department' => 'NOPE', '--execute' => true, '--force' => true])
            ->assertFailed();

        $this->artisan('technicians:import', ['source' => $this->csv("Jack,jack@gmail.com\n", "name,email\n"), '--department' => 'ELEC'])
            ->expectsOutputToContain('The file has no column named first_name, last_name')
            ->assertFailed();

        $this->artisan('technicians:import', ['source' => $this->directory.'/missing.csv', '--department' => 'ELEC'])
            ->expectsOutputToContain('File not found')
            ->assertFailed();

        $this->assertSame(0, AppUser::query()->count());
    }

    public function test_answering_no_to_the_question_adds_nothing(): void
    {
        $file = $this->csv("JACK,AYES,GUERRERO,jackguerrero25@gmail.com,09954713952\n");

        $this->artisan('technicians:import', ['source' => $file, '--department' => 'ELEC', '--execute' => true])
            ->expectsConfirmation('Add 1 technicians to Electrical?', 'no')
            ->expectsOutputToContain('Cancelled')
            ->assertFailed();

        $this->assertSame(0, AppUser::query()->count());
    }
}
