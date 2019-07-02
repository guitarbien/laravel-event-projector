<?php

namespace Spatie\EventProjector\Models;

use Exception;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Spatie\EventProjector\ShouldBeStored;
use Spatie\EventProjector\Facades\Projectionist;
use Spatie\SchemalessAttributes\SchemalessAttributes;
use Spatie\EventProjector\Exceptions\InvalidStoredEvent;
use Spatie\EventProjector\EventSerializers\EventSerializer;

class StoredEvent extends Model
{
    public $guarded = [];

    public $timestamps = false;

    public $casts = [
        'event_properties' => 'array',
        'meta_data' => 'array',
    ];

    /** @var int */
    public const DEFAULT_VERSION = 1;

    public static function createForEvent(ShouldBeStored $event, int $version, string $uuid = null): StoredEvent
    {
        $storedEvent = new static();
        $storedEvent->aggregate_uuid = $uuid;
        $storedEvent->version = $version;
        $storedEvent->event_class = get_class($event);
        $storedEvent->attributes['event_properties'] = app(EventSerializer::class)->serialize(clone $event);
        $storedEvent->meta_data = [];
        $storedEvent->created_at = Carbon::now();

        $storedEvent->save();

        return $storedEvent;
    }

    public function getEventAttribute(): ShouldBeStored
    {
        try {
            $event = app(EventSerializer::class)->deserialize(
                $this->event_class,
                $this->getOriginal('event_properties')
            );
        } catch (Exception $exception) {
            throw InvalidStoredEvent::couldNotUnserializeEvent($this, $exception);
        }

        return $event;
    }

    public function scopeStartingFrom(Builder $query, int $storedEventId): void
    {
        $query->where('id', '>=', $storedEventId);
    }

    public function scopeEndingTo(Builder $query, ?int $storedEventId)
    {
        if ($storedEventId === null) {
            return $query;
        }

        return $query->where('id', '<=', $storedEventId);
    }

    public function scopeUuid(Builder $query, string $uuid): void
    {
        $query->where('aggregate_uuid', $uuid);
    }

    public function getMetaDataAttribute(): SchemalessAttributes
    {
        return SchemalessAttributes::createForModel($this, 'meta_data');
    }

    public function scopeWithMetaDataAttributes(): Builder
    {
        return SchemalessAttributes::scopeWithSchemalessAttributes('meta_data');
    }

    public static function storeMany(array $events, int $version, string $uuid = null): void
    {
        collect($events)
            ->map(function (ShouldBeStored $domainEvent) use (&$version, $uuid) {
                $version += 1;
                $storedEvent = static::createForEvent($domainEvent, $version, $uuid);

                return [$domainEvent, $storedEvent];
            })
            ->eachSpread(function (ShouldBeStored $event, StoredEvent $storedEvent) {
                Projectionist::handleWithSyncProjectors($storedEvent);

                if (method_exists($event, 'tags')) {
                    $tags = $event->tags();
                }

                $storedEventJob = call_user_func(
                    [config('event-projector.stored_event_job'), 'createForEvent'],
                    $storedEvent,
                    $tags ?? []
                );

                dispatch($storedEventJob->onQueue($event->queue ?? config('event-projector.queue')));
            });
    }

    public static function store(ShouldBeStored $event, int $version, string $uuid = null): void
    {
        static::storeMany([$event], $version, $uuid);
    }
}
