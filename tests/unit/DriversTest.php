<?php

namespace ErnestDefoe\Millwright\Tests\Unit;

use ErnestDefoe\Millwright\Run\Drivers;
use ErnestDefoe\Millwright\Run\StepJob;
use ErnestDefoe\Millwright\Tests\unit\EnglishTranslator;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\SyncQueue;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * The rule these protect: a queue makes an update carry on in the background,
 * and is never what makes it work.
 *
 * Every failure here has to fall back to polling rather than refuse the update.
 * Refusing work because a background system nobody asked about is unhappy is how
 * one dead job blocks every later update forever.
 *
 * Mocks rather than hand-written stubs: these interfaces gain methods between
 * Laravel releases, and a stub that has to be chased every time tests less than
 * it costs.
 */
class DriversTest extends TestCase
{
    private function queue(mixed $connection): QueueFactory
    {
        $q = $this->createStub(QueueFactory::class);

        if ($connection instanceof \Throwable) {
            $q->method('connection')->willThrowException($connection);
        } else {
            $q->method('connection')->willReturn($connection);
        }

        return $q;
    }

    public function test_flarums_default_sync_queue_is_not_treated_as_a_worker(): void
    {
        /*
         * SyncQueue runs a "queued" job inline in the request that dispatched
         * it. Treating that as a worker would produce exactly the long request
         * this whole design avoids — on the hosts least able to survive one.
         */
        $drivers = new Drivers(EnglishTranslator::make(), $this->queue(new SyncQueue()), new \Psr\Log\NullLogger());

        $this->assertFalse($drivers->hasWorker());
        $this->assertFalse($drivers->nudge('r1'));
    }

    public function test_a_real_queue_gets_a_job(): void
    {
        $worker = $this->createMock(Queue::class);
        $worker->expects($this->once())
            ->method('push')
            ->with($this->isInstanceOf(StepJob::class));

        $drivers = new Drivers(EnglishTranslator::make(), $this->queue($worker), new \Psr\Log\NullLogger());

        $this->assertTrue($drivers->hasWorker());
        $this->assertTrue($drivers->nudge('r1'));
    }

    public function test_a_misconfigured_queue_falls_back_instead_of_failing_the_update(): void
    {
        $drivers = new Drivers(
            EnglishTranslator::make(),
            $this->queue(new RuntimeException('no connection')),
            new \Psr\Log\NullLogger()
        );

        $this->assertFalse($drivers->hasWorker());
        $this->assertFalse($drivers->nudge('r1'), 'a broken queue must not stop an update');
    }

    public function test_a_dispatch_that_throws_is_survivable(): void
    {
        // The update is already running and its state is on disk; a failed
        // dispatch costs a background helper, not the update.
        $worker = $this->createStub(Queue::class);
        $worker->method('push')->willThrowException(new RuntimeException('queue is down'));

        $drivers = new Drivers(EnglishTranslator::make(), $this->queue($worker), new \Psr\Log\NullLogger());

        $this->assertFalse($drivers->nudge('r1'));
    }

    public function test_a_sync_queue_hidden_inside_flarums_wrapper_is_still_recognised(): void
    {
        /*
         * The bug this test exists for, found by running against a real forum
         * rather than by reading the code: Flarum hands back a RoutingQueue that
         * WRAPS the real driver, so `instanceof SyncQueue` on it is always false
         * — including on a stock install where the thing inside is exactly that.
         * The result was "a worker is available" on a forum with none.
         */
        $wrapper = new class(new SyncQueue()) {
            public function __construct(private mixed $driver)
            {
            }

            public function getDriver(): mixed
            {
                return $this->driver;
            }
        };

        $drivers = new Drivers(EnglishTranslator::make(), $this->queue($wrapper), new \Psr\Log\NullLogger());

        $this->assertFalse($drivers->hasWorker(), 'a sync queue behind a wrapper is still a sync queue');
        $this->assertFalse($drivers->nudge('r1'));
    }

    public function test_a_real_driver_behind_the_wrapper_is_a_worker(): void
    {
        $wrapper = new class(new stdClass()) {
            public function __construct(private mixed $driver)
            {
            }

            public function getDriver(): mixed
            {
                return $this->driver;
            }
        };

        $drivers = new Drivers(EnglishTranslator::make(), $this->queue($wrapper), new \Psr\Log\NullLogger());

        $this->assertTrue($drivers->hasWorker());
    }

    public function test_it_tells_the_admin_which_situation_they_are_in(): void
    {
        $withWorker = new Drivers(EnglishTranslator::make(), $this->queue($this->createStub(Queue::class)), new \Psr\Log\NullLogger());
        $without = new Drivers(EnglishTranslator::make(), $this->queue(new SyncQueue()), new \Psr\Log\NullLogger());

        $this->assertStringContainsString('close this page', $withWorker->describe());
        $this->assertStringContainsString('keep this page open', $without->describe());
    }
}
