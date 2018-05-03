<?php

declare(strict_types=1);

namespace Building\App;

use Bernard\Driver\FlatFileDriver;
use Bernard\Producer;
use Bernard\Queue;
use Bernard\QueueFactory;
use Bernard\QueueFactory\PersistentFactory;
use Building\Domain\Aggregate\Building;
use Building\Domain\Command;
use Building\Domain\DomainEvent\CheckInAnomalyDetected;
use Building\Domain\DomainEvent\NewBuildingWasRegistered;
use Building\Domain\DomainEvent\UserCheckedIn;
use Building\Domain\DomainEvent\UserCheckedOut;
use Building\Domain\Repository\BuildingRepositoryInterface;
use Building\Infrastructure\Repository\BuildingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOSqlite\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\SchemaException;
use Interop\Container\ContainerInterface;
use Prooph\Common\Event\ActionEvent;
use Prooph\Common\Event\ActionEventEmitter;
use Prooph\Common\Event\ActionEventListenerAggregate;
use Prooph\Common\Event\ProophActionEventEmitter;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\NoOpMessageConverter;
use Prooph\EventSourcing\AggregateChanged;
use Prooph\EventSourcing\EventStoreIntegration\AggregateTranslator;
use Prooph\EventStore\Adapter\Doctrine\DoctrineEventStoreAdapter;
use Prooph\EventStore\Adapter\Doctrine\Schema\EventStoreSchema;
use Prooph\EventStore\Adapter\PayloadSerializer\JsonPayloadSerializer;
use Prooph\EventStore\Aggregate\AggregateRepository;
use Prooph\EventStore\Aggregate\AggregateType;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Stream\StreamName;
use Prooph\EventStoreBusBridge\EventPublisher;
use Prooph\EventStoreBusBridge\TransactionManager;
use Prooph\ServiceBus\Async\MessageProducer;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\Message\Bernard\BernardMessageProducer;
use Prooph\ServiceBus\Message\Bernard\BernardSerializer;
use Prooph\ServiceBus\MessageBus;
use Prooph\ServiceBus\Plugin\ServiceLocatorPlugin;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Zend\ServiceManager\ServiceManager;

require_once __DIR__ . '/vendor/autoload.php';

return new ServiceManager([
    'factories' => [
        Connection::class => function () {
            $connection = DriverManager::getConnection([
                'driverClass' => Driver::class,
                'path'        => __DIR__ . '/data/db.sqlite3',
            ]);

            try {
                $schema = $connection->getSchemaManager()->createSchema();

                EventStoreSchema::createSingleStream($schema, 'event_stream', true);

                foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
                    $connection->exec($sql);
                }
            } catch (SchemaException $ignored) {
            }

            return $connection;
        },

        EventStore::class                  => function (ContainerInterface $container) {
            $eventBus   = new EventBus();
            $eventStore = new EventStore(
                new DoctrineEventStoreAdapter(
                    $container->get(Connection::class),
                    new FQCNMessageFactory(),
                    new NoOpMessageConverter(),
                    new JsonPayloadSerializer()
                ),
                new ProophActionEventEmitter()
            );

            $eventBus->utilize(new class ($container, $container) implements ActionEventListenerAggregate
            {
                /**
                 * @var ContainerInterface
                 */
                private $eventHandlers;

                /**
                 * @var ContainerInterface
                 */
                private $projectors;

                public function __construct(
                    ContainerInterface $eventHandlers,
                    ContainerInterface $projectors
                ) {
                    $this->eventHandlers = $eventHandlers;
                    $this->projectors    = $projectors;
                }

                public function attach(ActionEventEmitter $dispatcher)
                {
                    $dispatcher->attachListener(MessageBus::EVENT_ROUTE, [$this, 'onRoute']);
                }

                public function detach(ActionEventEmitter $dispatcher)
                {
                    throw new \BadMethodCallException('Not implemented');
                }

                public function onRoute(ActionEvent $actionEvent)
                {
                    $messageName = (string) $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME);

                    $handlers = [];

                    $listeners  = $messageName . '-listeners';
                    $projectors = $messageName . '-projectors';

                    if ($this->projectors->has($projectors)) {
                        $handlers = array_merge($handlers, $this->eventHandlers->get($projectors));
                    }

                    if ($this->eventHandlers->has($listeners)) {
                        $handlers = array_merge($handlers, $this->eventHandlers->get($listeners));
                    }

                    if ($handlers) {
                        $actionEvent->setParam(EventBus::EVENT_PARAM_EVENT_LISTENERS, $handlers);
                    }
                }
            });

            (new EventPublisher($eventBus))->setUp($eventStore);

            return $eventStore;
        },

        CommandBus::class                  => function (ContainerInterface $container) : CommandBus {
            $commandBus = new CommandBus();

            $commandBus->utilize(new ServiceLocatorPlugin($container));
            $commandBus->utilize(new class implements ActionEventListenerAggregate {
                public function attach(ActionEventEmitter $dispatcher)
                {
                    $dispatcher->attachListener(MessageBus::EVENT_ROUTE, [$this, 'onRoute']);
                }

                public function detach(ActionEventEmitter $dispatcher)
                {
                    throw new \BadMethodCallException('Not implemented');
                }

                public function onRoute(ActionEvent $actionEvent)
                {
                    $actionEvent->setParam(
                        MessageBus::EVENT_PARAM_MESSAGE_HANDLER,
                        (string) $actionEvent->getParam(MessageBus::EVENT_PARAM_MESSAGE_NAME)
                    );
                }
            });

            $transactionManager = new TransactionManager();
            $transactionManager->setUp($container->get(EventStore::class));

            $commandBus->utilize($transactionManager);

            return $commandBus;
        },

        // ignore this - this is async stuff
        // we'll get to it later

        QueueFactory::class => function () : QueueFactory {
            return new PersistentFactory(
                new FlatFileDriver(__DIR__ . '/data/bernard'),
                new BernardSerializer(new FQCNMessageFactory(), new NoOpMessageConverter())
            );
        },

        Queue::class => function (ContainerInterface $container) : Queue {
            return $container->get(QueueFactory::class)->create('commands');
        },

        MessageProducer::class => function (ContainerInterface $container) : MessageProducer {
            return new BernardMessageProducer(
                new Producer($container->get(QueueFactory::class),new EventDispatcher()),
                'commands'
            );
        },

        // Command -> CommandHandlerFactory
        // this is where most of the work will be done (by you!)
        Command\RegisterNewBuilding::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);

            return function (Command\RegisterNewBuilding $command) use ($buildings) {
                $buildings->store(Building::new($command->name()));
            };
        },
        Command\CheckInUser::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);

            return function (Command\CheckInUser $command) use ($buildings) {
                $building = $buildings->get($command->building());

                $building->checkInUser($command->username());

                $buildings->store($building);
            };
        },
        Command\CheckOutUser::class => function (ContainerInterface $container) : callable {
            $buildings = $container->get(BuildingRepositoryInterface::class);

            return function (Command\CheckOutUser $command) use ($buildings) {
                $building = $buildings->get($command->building());

                $building->checkOutUser($command->username());

                $buildings->store($building);
            };
        },
        Command\NotifySecurityOfCheckInAnomaly::class => function () : callable {
            return function (Command\NotifySecurityOfCheckInAnomaly $command) {
                error_log(sprintf(
                    'Check-in anomaly detected in building "%s" for user "%s"',
                    $command->building()->toString(),
                    $command->username()
                ));
            };
        },
        CheckInAnomalyDetected::class . '-listeners' => function (ContainerInterface $container) : array {
            $commandBus = $container->get(CommandBus::class);

            return [
                function (CheckInAnomalyDetected $event) use ($commandBus) : void {
                    $commandBus->dispatch(Command\NotifySecurityOfCheckInAnomaly::inBuilding(
                        $event->building(),
                        $event->username()
                    ));
                }
            ];
        },
        'checked-in-users-projection' =>  function (ContainerInterface $container) : callable {
            $eventStore = $container->get(EventStore::class);

            return function () use ($eventStore) : void {
                $pastEvents = $eventStore->loadEventsByMetadataFrom(
                    new StreamName('event_stream'),
                    [
                        'aggregate_type' => Building::class,
                    ]
                );

                $users = [];

                /** @var AggregateChanged[] $pastEvents */
                foreach ($pastEvents as $pastEvent) {
                    if (! isset($users[$pastEvent->aggregateId()])) {
                        $users[$pastEvent->aggregateId()] = [];
                    }

                    if ($pastEvent instanceof UserCheckedIn) {
                        $users[$pastEvent->aggregateId()][$pastEvent->username()] = null;
                    }

                    if ($pastEvent instanceof UserCheckedOut) {
                        unset($users[$pastEvent->aggregateId()][$pastEvent->username()]);
                    }
                }

                array_walk($users, function (array $users, string $aggregateId) : void {
                    file_put_contents(
                        __DIR__ . '/public/building-' . $aggregateId . '.json',
                        json_encode(array_keys($users))
                    );
                });
            };
        },
        UserCheckedIn::class . '-projectors' => function (ContainerInterface $container) : array {
            return [
                $container->get('checked-in-users-projection'),
            ];
        },
        UserCheckedOut::class . '-projectors' => function (ContainerInterface $container) : array {
            return [
                $container->get('checked-in-users-projection'),
            ];
        },
        NewBuildingWasRegistered::class . '-projectors' => function (ContainerInterface $container) : array {
            return [
                $container->get('checked-in-users-projection'),
            ];
        },
        BuildingRepositoryInterface::class => function (ContainerInterface $container) : BuildingRepositoryInterface {
            return new BuildingRepository(
                new AggregateRepository(
                    $container->get(EventStore::class),
                    AggregateType::fromAggregateRootClass(Building::class),
                    new AggregateTranslator()
                )
            );
        },
    ],
]);