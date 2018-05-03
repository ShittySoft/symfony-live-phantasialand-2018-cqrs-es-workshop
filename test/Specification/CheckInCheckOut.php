<?php

namespace Specification;

use Assert\Assertion;
use Behat\Behat\Context\Context;
use Building\Domain\Aggregate\Building;
use Building\Domain\DomainEvent\NewBuildingWasRegistered;
use Building\Domain\DomainEvent\UserCheckedIn;
use Prooph\EventSourcing\AggregateChanged;
use Prooph\EventSourcing\EventStoreIntegration\AggregateTranslator;
use Prooph\EventStore\Aggregate\AggregateType;
use Rhumsaa\Uuid\Uuid;

final class CheckInCheckOut implements Context
{
    /** @var Uuid[] indexed by building name */
    private $buildingIds = [];

    /** @var AggregateChanged[][] */
    private $pastEvents = [];

    /** @var Building[] */
    private $buildings = [];

    /** @var AggregateChanged[][] */
    private $recordedEvents = [];

    /**
     * @Given /^the building "([^"]+)" was registered$/
     */
    public function the_building_was_registered(string $buildingName) : void
    {
        $this->buildingIds[$buildingName]  = Uuid::uuid4();
        $this->pastEvents[$buildingName][] = NewBuildingWasRegistered::occur(
            $this->buildingIds[$buildingName]->toString(),
            ['name' => $buildingName]
        );
    }

    /**
     * @When /^"([^"]+)" checks into "([^"]+)"$/
     */
    public function user_checks_into_building(string $username, string $buildingName) : void
    {
        $this
            ->building($buildingName)
            ->checkInUser($username);
    }

    /**
     * @Then /^"([^"]+)" should have been checked into "([^"]+)"$/
     */
    public function user_should_have_been_checked_into_building(string $username, string $buildingName) : void
    {
        /** @var UserCheckedIn $event */
        $event = $this->popNextRecordedEvent($buildingName);

        Assertion::isInstanceOf($event, UserCheckedIn::class);
        Assertion::same($event->username(), $username);
    }

    private function building(string $buildingName) : Building
    {
        if (isset($this->buildings[$buildingName])) {
            return $this->buildings[$buildingName];
        }

        return $this->buildings[$buildingName] = (new AggregateTranslator())
            ->reconstituteAggregateFromHistory(
                AggregateType::fromAggregateRootClass(Building::class),
                new \ArrayIterator($this->pastEvents[$buildingName])
            );
    }

    private function popNextRecordedEvent(string $buildingName) : AggregateChanged
    {
        if (isset($this->recordedEvents[$buildingName])) {
            return array_shift($this->recordedEvents[$buildingName]);
        }

        $this->recordedEvents[$buildingName] = (new AggregateTranslator())
            ->extractPendingStreamEvents($this->building($buildingName));

        return array_shift($this->recordedEvents[$buildingName]);
    }
}
