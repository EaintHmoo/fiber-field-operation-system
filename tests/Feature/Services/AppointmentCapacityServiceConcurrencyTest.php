<?php

namespace Tests\Feature\Services;

use App\Exceptions\CapacityExceededException;
use App\Models\InstallationRequest;
use App\Models\LspTeam;
use App\Models\LspTeamDailyCapacity;
use App\Models\LspUser;
use App\Models\OnCallRequest;
use App\Services\AppointmentCapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;
use Throwable;

/**
 * Forks real processes, each with its own MySQL connection, that try to book the last slot at
 * the same instant. Needs committed data (no wrapping test transaction) and InnoDB row locks:
 *
 *     DB_CONNECTION=mysql DB_DATABASE=fiber_field_ops_test php artisan test --group=concurrency
 */
#[Group('concurrency')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
class AppointmentCapacityServiceConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const int CONTENDERS = 6;

    private const string APPOINTMENT = '2026-04-02 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row locking needs MySQL; run with DB_CONNECTION=mysql.');
        }
    }

    protected function tearDown(): void
    {
        // Rows here are committed; clear them so later transaction-wrapped tests start empty.
        if (DB::getDriverName() === 'mysql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_exactly_one_concurrent_booking_gets_the_last_configured_slot(): void
    {
        $team = LspTeam::factory()->create();
        LspTeamDailyCapacity::factory()->for($team, 'team')->create(['day_of_week' => 4, 'max_slots' => 2]);
        InstallationRequest::factory()->forTeam($team)->appointmentAt($this->appointment())->create();

        $outcomes = $this->raceForSlot($team);

        $this->assertSame(['booked' => 1, 'full' => self::CONTENDERS - 1], $this->tally($outcomes), implode(PHP_EOL, $outcomes));
        $this->assertSame(2, $this->usedSlots($team));
        $this->assertDatabaseCount('appointment_booking_logs', 1);
    }

    public function test_exactly_one_concurrent_booking_gets_the_last_default_slot_when_capacity_row_is_missing(): void
    {
        $team = LspTeam::factory()->create();
        InstallationRequest::factory()->count(7)->forTeam($team)->appointmentAt($this->appointment())->create();

        $outcomes = $this->raceForSlot($team);

        $this->assertSame(['booked' => 1, 'full' => self::CONTENDERS - 1], $this->tally($outcomes), implode(PHP_EOL, $outcomes));
        $this->assertSame(8, $this->usedSlots($team));
        $this->assertDatabaseCount('lsp_team_daily_capacities', 1);
        $this->assertDatabaseCount('appointment_booking_logs', 1);
    }

    /**
     * @return list<string> One outcome per contender: "booked", "full" or "error: ...".
     */
    private function raceForSlot(LspTeam $team): array
    {
        $actor = LspUser::factory()->forTeam($team)->create();
        $jobs = collect(range(1, self::CONTENDERS))->map(
            fn (int $i) => ($i % 2 === 0 ? OnCallRequest::class : InstallationRequest::class)::factory()->forTeam($team)->create(),
        );

        $startAt = microtime(true) + 1.0;
        $resultFiles = [];

        // Children must not share the parent's socket; each opens its own connection.
        DB::disconnect();

        foreach ($jobs as $job) {
            $resultFile = tempnam(sys_get_temp_dir(), 'booking-race-');
            $pid = pcntl_fork();

            if ($pid === 0) {
                $this->runContender($job, $actor, $startAt, $resultFile);
            }

            $resultFiles[$pid] = $resultFile;
        }

        $outcomes = [];

        foreach ($resultFiles as $pid => $resultFile) {
            pcntl_waitpid($pid, $status);
            $outcomes[] = (string) file_get_contents($resultFile);
            unlink($resultFile);
        }

        DB::reconnect();

        return $outcomes;
    }

    private function runContender(InstallationRequest|OnCallRequest $job, LspUser $actor, float $startAt, string $resultFile): never
    {
        try {
            DB::reconnect();
            DB::select('select 1');

            usleep(max(0, (int) (($startAt - microtime(true)) * 1_000_000)));

            $this->app->make(AppointmentCapacityService::class)->book($job, $this->appointment(), $actor);
            $outcome = 'booked';
        } catch (CapacityExceededException) {
            $outcome = 'full';
        } catch (Throwable $exception) {
            $outcome = 'error: '.$exception::class.': '.$exception->getMessage();
        }

        file_put_contents($resultFile, $outcome);

        // Skip PHPUnit/Laravel shutdown handlers in the forked child.
        posix_kill(posix_getpid(), SIGKILL);
        exit(1);
    }

    /**
     * @param  list<string>  $outcomes
     * @return array<string, int>
     */
    private function tally(array $outcomes): array
    {
        $counts = array_count_values($outcomes);
        ksort($counts);

        return $counts;
    }

    private function usedSlots(LspTeam $team): int
    {
        return $this->app->make(AppointmentCapacityService::class)
            ->capacityFor($team, $this->appointment()->toDateString())
            ->usedSlots;
    }

    private function appointment(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::APPOINTMENT, 'Asia/Yangon');
    }
}
