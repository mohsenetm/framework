<?php

namespace Illuminate\Tests\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;

class QueueDelayTest extends TestCase
{
    public function testQueueDelay()
    {
        Queue::fake();

        $job = new TestJob;

        dispatch($job);

        $this->assertEquals(60, $job->delay);
    }

    public function testQueueWithoutDelay()
    {
        Queue::fake();

        $job = new TestJob;

        dispatch($job->withoutDelay());

        $this->assertEquals(0, $job->delay);
    }

    public function testPendingDispatchWithoutDelay()
    {
        Queue::fake();

        $job = new TestJob;

        dispatch($job)->withoutDelay();

        $this->assertEquals(0, $job->delay);
    }
}

class TestJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->delay(60);
    }
}
