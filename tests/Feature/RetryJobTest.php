<?php

namespace Laravel\Horizon\Tests\Feature;

use Laravel\Horizon\Jobs\MonitorTag;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Jobs\RetryFailedJob;
use Laravel\Horizon\Tests\IntegrationTest;

class RetryJobTest extends IntegrationTest
{
    public function setUp()
    {
        parent::setUp();

        unset($_SERVER['horizon.fail']);
    }


    public function tearDown()
    {
        parent::tearDown();

        unset($_SERVER['horizon.fail']);
    }


    public function test_nothing_happens_for_failed_job_that_doesnt_exist()
    {
        dispatch(new RetryFailedJob('12345'));
    }



    public function test_failed_job_can_be_retried_successfully_with_a_fresh_id()
    {
        $_SERVER['horizon.fail'] = true;
        $id = Queue::push(new Jobs\ConditionallyFailingJob);
        $this->work();
        $this->assertEquals(1, $this->failedJobs());

        // Monitor the tag so the job is stored in the completed table...
        dispatch(new MonitorTag('first'));

        unset($_SERVER['horizon.fail']);
        dispatch(new RetryFailedJob($id));

        // Test status is set to pending...
        $retried = Redis::connection('horizon-jobs')->hget($id, 'retried_by');
        $retried = json_decode($retried, true);
        $this->assertEquals('pending', $retried[0]['status']);

        // Work the now-passing job...
        $this->work();

        $this->assertEquals(1, $this->failedJobs());
        $this->assertEquals(1, $this->monitoredJobs('first'));

        // Test that retry job ID reference is stored on original failed job...
        $retried = Redis::connection('horizon-jobs')->hget($id, 'retried_by');
        $retried = json_decode($retried, true);
        $this->assertEquals(1, count($retried));
        $this->assertNotNull($retried[0]['id']);
        $this->assertNotNull($retried[0]['retried_at']);

        // Test status is now completed on the retry...
        $this->assertEquals('completed', $retried[0]['status']);
    }


    public function test_status_is_updated_for_double_failing_jobs()
    {
        $_SERVER['horizon.fail'] = true;
        $id = Queue::push(new Jobs\ConditionallyFailingJob);
        $this->work();
        dispatch(new RetryFailedJob($id));
        $this->work();

        // Test that retry job ID reference is stored on original failed job...
        $retried = Redis::connection('horizon-jobs')->hget($id, 'retried_by');
        $retried = json_decode($retried, true);

        // Test status is now failed on the retry...
        $this->assertEquals('failed', $retried[0]['status']);
    }
}
