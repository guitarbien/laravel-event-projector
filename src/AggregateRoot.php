<?php

namespace Spatie\EventProjector;

use Illuminate\Support\Str;
use Spatie\EventProjector\Models\StoredEvent;

abstract class AggregateRoot
{
    /** @var string */
    private $aggregateUuid;

    /** @var array */
    private $recordedEvents = [];

    /** @var int */
    private $version = 0;

    public static function retrieve(string $uuid, int $storedEventId = null): AggregateRoot
    {
        $aggregateRoot = (new static());

        $aggregateRoot->aggregateUuid = $uuid;

        return $aggregateRoot->reconstituteFromEvents($storedEventId);
    }

    public function recordThat(ShouldBeStored $domainEvent): AggregateRoot
    {
        $this->recordedEvents[] = $domainEvent;

        $this->apply($domainEvent);

        return $this;
    }

    public function persist(): AggregateRoot
    {
        call_user_func(
            [$this->getStoredEventModel(), 'storeMany'],
            $this->getAndClearRecordedEvents(),
            $this->version,
            $this->aggregateUuid
        );

        return $this;
    }

    protected function getStoredEventModel(): string
    {
        return $this->storedEventModel ?? config('event-projector.stored_event_model');
    }

    private function getAndClearRecordedEvents(): array
    {
        $recordedEvents = $this->recordedEvents;

        $this->recordedEvents = [];

        return $recordedEvents;
    }

    private function reconstituteFromEvents(int $storedEventId = null): AggregateRoot
    {
        $this->getStoredEventModel()::uuid($this->aggregateUuid)->each(function (StoredEvent $storedEvent) {
            $this->apply($storedEvent->event);

            $this->version = $storedEvent->version;
        });

        return $this;
    }

    private function apply(ShouldBeStored $event): void
    {
        $classBaseName = class_basename($event);

        $camelCasedBaseName = ucfirst(Str::camel($classBaseName));

        $applyingMethodName = "apply{$camelCasedBaseName}";

        if (method_exists($this, $applyingMethodName)) {
            $this->$applyingMethodName($event);
        }
    }
}
