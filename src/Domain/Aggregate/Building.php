<?php

declare(strict_types=1);

namespace Building\Domain\Aggregate;

use Building\Domain\DomainEvent\NewBuildingWasRegistered;
use Building\Domain\DomainEvent\UserCheckedIn;
use Building\Domain\DomainEvent\UserCheckedOut;
use Prooph\EventSourcing\AggregateRoot;
use Rhumsaa\Uuid\Uuid;

final class Building extends AggregateRoot
{
    /**
     * @var Uuid
     */
    private $uuid;

    /**
     * @var string
     */
    private $name;

    /** @var null[] indexed by username */
    private $checkedInUsers = [];

    public static function new(string $name) : self
    {
        $self = new self();

        $self->recordThat(NewBuildingWasRegistered::occur(
            (string) Uuid::uuid4(),
            [
                'name' => $name
            ]
        ));

        return $self;
    }

    public function checkInUser(string $username) : void
    {
        if (array_key_exists($username, $this->checkedInUsers)) {
            throw new \RuntimeException(sprintf(
                'User "%s" already checked into "%s" (%s)',
                $username,
                $this->name,
                $this->uuid->toString()
            ));
        }

        $this->recordThat(UserCheckedIn::toBuilding(
            $this->uuid,
            $username
        ));
    }

    public function checkOutUser(string $username)
    {
        if (! array_key_exists($username, $this->checkedInUsers)) {
            throw new \RuntimeException(sprintf(
                'User "%s" is not checked into "%s" (%s)',
                $username,
                $this->name,
                $this->uuid->toString()
            ));
        }

        $this->recordThat(UserCheckedOut::fromBuilding(
            $this->uuid,
            $username
        ));
    }

    protected function whenNewBuildingWasRegistered(NewBuildingWasRegistered $event) : void
    {
        $this->uuid = Uuid::fromString($event->aggregateId());
        $this->name = $event->name();
    }

    protected function whenUserCheckedIn(UserCheckedIn $event) : void
    {
        $this->checkedInUsers[$event->username()] = null;
    }

    protected function whenUserCheckedOut(UserCheckedOut $event) : void
    {
        unset($this->checkedInUsers[$event->username()]);
    }

    /**
     * {@inheritDoc}
     */
    protected function aggregateId() : string
    {
        return (string) $this->uuid;
    }

    /**
     * {@inheritDoc}
     */
    public function id() : string
    {
        return $this->aggregateId();
    }
}
