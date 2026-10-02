<?php

declare(strict_types=1);

use App\Actions\AnnounceEnrichmentProgress;
use App\Enums\LibraryEnrichmentState;
use App\Enums\ResolutionStatus;
use App\Events\LibraryEnrichmentUpdated;
use App\Models\Playlist;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Models\YouTubeMusicAccount;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeEnrichmentTelemetry;

beforeEach(function (): void {
    Event::fake([LibraryEnrichmentUpdated::class]);
    $this->account = YouTubeMusicAccount::factory()->create();
    $this->playlist = Playlist::factory()->for($this->account, 'youtubeMusicAccount')->create();
    libraryTrack($this->playlist, 'video-aaaa1');
    libraryTrack($this->playlist, 'video-aaaa2');
    RecordingResolution::factory()->create(['external_id' => 'video-aaaa1', 'status' => ResolutionStatus::Pending, 'recording_id' => null]);
});

it('tells the owners of the tracks where their library stands', function (): void {
    libraryTrack(Playlist::factory()->create(), 'video-aaaa1');
    libraryTrack(Playlist::factory()->create(), 'video-other');

    resolve(AnnounceEnrichmentProgress::class)->forVideos(['video-aaaa1']);

    Event::assertDispatchedTimes(LibraryEnrichmentUpdated::class, 2);
    Event::assertDispatched(LibraryEnrichmentUpdated::class, fn (LibraryEnrichmentUpdated $event): bool => $event->userId === $this->account->user_id
        && $event->summary->state === LibraryEnrichmentState::Running
        && $event->summary->total === 2);
});

it('finds the owners of a recording through its resolutions', function (): void {
    $recording = Recording::factory()->create();
    RecordingResolution::factory()->create(['external_id' => 'video-aaaa2', 'recording_id' => $recording->id]);

    resolve(AnnounceEnrichmentProgress::class)->forRecording($recording->id);

    Event::assertDispatched(LibraryEnrichmentUpdated::class, fn (LibraryEnrichmentUpdated $event): bool => $event->userId === $this->account->user_id);
});

it('keeps quiet about running work announced moments ago', function (): void {
    $announce = resolve(AnnounceEnrichmentProgress::class);

    $announce->forVideos(['video-aaaa1']);
    $announce->forVideos(['video-aaaa1']);
    Event::assertDispatchedTimes(LibraryEnrichmentUpdated::class, 1);

    $this->travel(AnnounceEnrichmentProgress::QUIET_SECONDS)->seconds();
    $announce->forVideos(['video-aaaa1']);
    Event::assertDispatchedTimes(LibraryEnrichmentUpdated::class, 2);
});

it('always announces the moment the work settles', function (): void {
    $announce = resolve(AnnounceEnrichmentProgress::class);
    $announce->forVideos(['video-aaaa1']);

    RecordingResolution::query()->where('external_id', 'video-aaaa1')->update(['status' => ResolutionStatus::NotFound]);
    RecordingResolution::factory()->create(['external_id' => 'video-aaaa2', 'status' => ResolutionStatus::NotFound, 'recording_id' => null]);
    $announce->forVideos(['video-aaaa1']);

    Event::assertDispatchedTimes(LibraryEnrichmentUpdated::class, 2);
    Event::assertDispatched(LibraryEnrichmentUpdated::class, fn (LibraryEnrichmentUpdated $event): bool => $event->summary->state === LibraryEnrichmentState::Done);
});

it('broadcasts on the owner\'s private channel', function (): void {
    resolve(AnnounceEnrichmentProgress::class)->forVideos(['video-aaaa1']);

    Event::assertDispatched(LibraryEnrichmentUpdated::class, fn (LibraryEnrichmentUpdated $event): bool => $event->broadcastOn()[0]->name === 'private-library-enrichment.'.$this->account->user_id
        && $event->broadcastAs() === 'enrichment.updated'
        && $event->broadcastWith()['total'] === 2);
});

it('tells every library when the enrichment queue pauses or resumes', function (): void {
    Queue::pause(config()->string('queue.default'), 'enrichment');
    Event::assertDispatched(LibraryEnrichmentUpdated::class, fn (LibraryEnrichmentUpdated $event): bool => $event->summary->state === LibraryEnrichmentState::Paused);

    Queue::pause(config()->string('queue.default'), 'default');
    Event::assertDispatchedTimes(LibraryEnrichmentUpdated::class, 1);
});

it('keeps enrichment going when the broadcast fails', function (): void {
    Event::swap(new Dispatcher(app()));
    Broadcast::extend('down', fn (): Broadcaster => new class implements Broadcaster
    {
        public function auth($request): mixed
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            return null;
        }

        /**
         * @param  array<int, mixed>  $channels
         * @param  array<string, mixed>  $payload
         */
        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new RuntimeException('Reverb is down');
        }
    });
    config(['broadcasting.connections.down' => ['driver' => 'down'], 'broadcasting.default' => 'down']);
    Exceptions::fake();

    resolve(AnnounceEnrichmentProgress::class)->forVideos(['video-aaaa1']);

    Exceptions::assertReported(RuntimeException::class);
});

it('measures the coverage along the way, at most once a minute', function (): void {
    $telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $telemetry);
    $announce = resolve(AnnounceEnrichmentProgress::class);

    $announce->forVideos(['video-aaaa1']);
    $telemetry->coverage = [];
    $announce->forVideos(['video-aaaa1']);
    expect($telemetry->coverage)->toBe([]);

    $this->travel(AnnounceEnrichmentProgress::COVERAGE_SECONDS)->seconds();
    $announce->forVideos(['video-aaaa1']);
    expect($telemetry->coverage)->toHaveKey('resolved');
});
