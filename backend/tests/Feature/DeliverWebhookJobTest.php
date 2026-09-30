<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesDns;
use Tests\TestCase;

class DeliverWebhookJobTest extends TestCase
{
    use FakesDns, RefreshDatabase;

    private function webhook(bool $active = true): Webhook
    {
        return Workspace::factory()->create()->webhooks()->create([
            'url' => 'https://hooks.example.com/in', 'events' => ['link.clicked'], 'is_active' => $active,
        ]);
    }

    private function envelope(): array
    {
        return ['id' => 'evt_1', 'type' => 'link.clicked', 'created_at' => 'x', 'workspace_id' => 1, 'data' => []];
    }

    /** Runs the job as the worker would, on the given attempt, and returns the queue job to inspect. */
    private function work(DeliverWebhook $job, int $attempt): Job
    {
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $queueJob->shouldReceive('release')->byDefault();
        $queueJob->shouldReceive('isReleased')->andReturn(false)->byDefault();
        $job->setJob($queueJob);

        app()->call([$job, 'handle']);

        return $queueJob;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePublicReceivers();
    }

    public function test_a_successful_delivery_is_not_released(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $webhook = $this->webhook();

        $queueJob = $this->work(new DeliverWebhook($webhook->id, $this->envelope()), 1);

        $queueJob->shouldNotHaveReceived('release');
        $this->assertSame(1, $webhook->deliveries()->count());
    }

    public function test_a_temporary_failure_is_released_with_a_growing_delay(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $webhook = $this->webhook();

        foreach ([1 => 10, 2 => 60, 3 => 300, 4 => 1800] as $attempt => $delay) {
            $queueJob = $this->work(new DeliverWebhook($webhook->id, $this->envelope()), $attempt);

            $queueJob->shouldHaveReceived('release')->with($delay)->once();
        }

        $this->assertSame(4, $webhook->deliveries()->count());
        $this->assertSame([1, 2, 3, 4], $webhook->deliveries()->orderBy('id')->pluck('attempt')->all());
    }

    public function test_the_last_attempt_is_logged_but_not_released_again(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $webhook = $this->webhook();

        $queueJob = $this->work(new DeliverWebhook($webhook->id, $this->envelope()), 5);

        $queueJob->shouldNotHaveReceived('release');
        $this->assertFalse($webhook->deliveries()->first()->success);
    }

    public function test_a_permanent_failure_is_not_retried(): void
    {
        Http::fake(['*' => Http::response('gone', 410)]);
        $webhook = $this->webhook();

        $queueJob = $this->work(new DeliverWebhook($webhook->id, $this->envelope()), 1);

        $queueJob->shouldNotHaveReceived('release');
        $this->assertSame(1, $webhook->deliveries()->count());
    }

    public function test_a_webhook_deleted_while_queued_is_dropped_quietly(): void
    {
        Http::fake();
        $webhook = $this->webhook();
        $job = new DeliverWebhook($webhook->id, $this->envelope());
        $webhook->delete();

        $this->work($job, 1);

        Http::assertNothingSent();
    }

    public function test_a_webhook_switched_off_while_queued_is_dropped_quietly(): void
    {
        Http::fake();
        $webhook = $this->webhook(active: false);

        $this->work(new DeliverWebhook($webhook->id, $this->envelope()), 1);

        Http::assertNothingSent();
        $this->assertSame(0, $webhook->deliveries()->count());
    }

    public function test_run_through_the_sync_queue_a_bad_receiver_never_raises(): void
    {
        // The default queue used to be sync; if someone still runs that, a
        // dead receiver must cost nobody their request.
        config(['queue.default' => 'sync']);
        Http::fake(['*' => Http::response('down', 500)]);
        $webhook = $this->webhook();

        DeliverWebhook::dispatch($webhook->id, $this->envelope());

        $this->assertSame(1, $webhook->deliveries()->count());
    }
}
