<?php

declare(strict_types=1);

namespace DigitalWizard\DoctrineHydrationModule\Service;

use DigitalWizard\DoctrineHydrationModule\Hydrator\DoctrineHydrator;
use Doctrine\Persistence\ObjectManager;
use Doctrine\ORM\EntityManager;
use DoctrineModule\Persistence\ObjectManagerAwareInterface;
use Doctrine\Laminas\Hydrator\DoctrineObject;
use Laminas\Hydrator\AbstractHydrator;
use Laminas\Hydrator\Filter\FilterComposite;
use Laminas\Hydrator\Filter\FilterInterface;
use Laminas\Hydrator\HydratorInterface;
use Laminas\Hydrator\NamingStrategy\NamingStrategyInterface;
use Laminas\Hydrator\Strategy\StrategyInterface;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;
use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class DoctrineHydratorFactory.
 */
class DoctrineHydratorFactory implements AbstractFactoryInterface
{
    const string FACTORY_NAMESPACE = 'doctrine-hydrator';

    const string OBJECT_MANAGER_TYPE_ORM = 'ORM';

    /**
     * Cache of canCreateServiceWithName lookups.
     *
     * @var array
     */
    protected array $lookupCache = array();

    /**
     * Determine if we can create a service with name.
     *
     * @param ContainerInterface $container
     * @param $requestedName
     *
     * @return bool
     *
     * @throws ServiceNotFoundException|NotFoundExceptionInterface|ContainerExceptionInterface
     */
    public function canCreate(ContainerInterface $container, $requestedName): bool
    {
        if (array_key_exists($requestedName, $this->lookupCache)) {
            return $this->lookupCache[$requestedName];
        }

        if (!$container->has('config')) {
            return false;
        }

        // Validate object is set
        $config = $container->get('config');
        $namespace = self::FACTORY_NAMESPACE;
        if (!isset($config[$namespace])
            || !is_array($config[$namespace])
            || !isset($config[$namespace][$requestedName])
        ) {
            $this->lookupCache[$requestedName] = false;

            return false;
        }

        // Validate object manager
        $config = $config[$namespace];
        if (!isset($config[$requestedName]) || !isset($config[$requestedName]['object_manager'])) {
            throw new ServiceNotFoundException(sprintf(
                '%s requires that a valid "object_manager" is specified for hydrator %s; no service found',
                __METHOD__,
                $requestedName
            ));
        }

        // Validate object class
        if (!isset($config[$requestedName]['entity_class'])) {
            throw new ServiceNotFoundException(sprintf(
                '%s requires that a valid "entity_class" is specified for hydrator %s; no service found',
                __METHOD__,
                $requestedName
            ));
        }

        $this->lookupCache[$requestedName] = true;

        return true;
    }

    /**
     * Determine if we can create a service with name. (v2)
     *
     * Provided for backwards compatibility; proxies to canCreate().
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @param $name
     * @param $requestedName
     *
     * @return bool
     *
     * @throws ServiceNotFoundException|NotFoundExceptionInterface|ContainerExceptionInterface
     */
    public function canCreateServiceWithName(
        ServiceLocatorInterface $serviceLocator,
        $name,
        $requestedName
    ): bool {
        return $this->canCreate($serviceLocator, $requestedName);
    }

    /**
     * Create and return the database-connected resource.
     *
     * @param ContainerInterface $container
     * @param $requestedName
     * @param null|array $options
     *
     * @return DoctrineHydrator
     * @throws ServiceNotCreatedException|ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null): DoctrineHydrator
    {
        $config = $container->get('config');
        $config = $config[self::FACTORY_NAMESPACE][$requestedName];

        $objectManager = $this->loadObjectManager($container, $config);

        $extractService = null;
        $hydrateService = null;

        $useCustomHydrator = (array_key_exists('hydrator', $config));
        if ($useCustomHydrator) {
            try {
                $extractService = $container->build($config['hydrator'], $config);
            } catch (ServiceNotFoundException|NotFoundExceptionInterface|ContainerExceptionInterface $e) {
                $extractService = $container->get($config['hydrator']);
            }

            $hydrateService = $extractService;
        }

        # Use DoctrineModuleHydrator by default
        if (!isset($extractService, $hydrateService)) {
            $doctrineModuleHydrator = $this->loadDoctrineModuleHydrator($container, $config, $objectManager);
            $extractService = $doctrineModuleHydrator;
            $hydrateService = $extractService;
        }

        $this->configureHydrator($extractService, $container, $config, $objectManager);
        $this->configureHydrator($hydrateService, $container, $config, $objectManager);

        return new DoctrineHydrator($extractService, $hydrateService);
    }

    /**
     * Create and return the database-connected resource (v2).
     *
     * Provided for backwards compatibility; proxies to __invoke().
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @param $name
     * @param $requestedName
     *
     * @return DoctrineHydrator
     * @throws ServiceNotCreatedException|ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function createServiceWithName(
        ServiceLocatorInterface $serviceLocator,
        $name,
        $requestedName
    ): DoctrineHydrator {
        return $this($serviceLocator, $requestedName);
    }

    /**
     * @param $objectManager
     *
     * @return string
     *
     * @throws ServiceNotCreatedException
     */
    protected function getObjectManagerType($objectManager): string
    {
        if (class_exists(EntityManager::class) && $objectManager instanceof EntityManager) {
            return self::OBJECT_MANAGER_TYPE_ORM;
        }

        throw new ServiceNotCreatedException('Unknown object manager type: ' . get_class($objectManager));
    }

    /**
     * @param ContainerInterface $container
     * @param array $config
     *
     * @return ObjectManager
     *
     * @throws NotFoundExceptionInterface|ContainerExceptionInterface
     */
    protected function loadObjectManager(ContainerInterface $container, array $config): ObjectManager
    {
        if (!$container->has($config['object_manager'])) {
            throw new ServiceNotCreatedException('The object_manager could not be found.');
        }

        return $container->get($config['object_manager']);
    }

    /**
     * @param ContainerInterface $container
     * @param array $config
     * @param ObjectManager $objectManager
     *
     * @return null
     */
    protected function loadEntityHydrator(
        ContainerInterface $container,
        array $config,
        ObjectManager $objectManager
    ): null {
        return null;
    }

    /**
     * @param ContainerInterface $container
     * @param array $config
     * @param ObjectManager $objectManager
     *
     * @return HydratorInterface|DoctrineObject
     */
    protected function loadDoctrineModuleHydrator(
        ContainerInterface $container,
        array $config,
        ObjectManager $objectManager
    ): HydratorInterface|DoctrineObject {
        $this->getObjectManagerType($objectManager);
        return new DoctrineObject($objectManager, $config['by_value']);
    }

    /**
     * @param AbstractHydrator $hydrator
     * @param ContainerInterface $container
     * @param array $config
     * @param ObjectManager $objectManager
     *
     * @throws ServiceNotCreatedException|ContainerExceptionInterface
     */
    public function configureHydrator(
        AbstractHydrator $hydrator,
        ContainerInterface $container,
        array $config,
        ObjectManager $objectManager
    ): void {
        $this->configureHydratorFilters($hydrator, $container, $config, $objectManager);
        $this->configureHydratorStrategies($hydrator, $container, $config, $objectManager);
        $this->configureHydratorNamingStrategy($hydrator, $container, $config, $objectManager);
    }

    /**
     * @param AbstractHydrator $hydrator
     * @param ContainerInterface $container
     * @param array $config
     * @param ObjectManager $objectManager
     *
     * @throws ServiceNotCreatedException|ContainerExceptionInterface
     */
    public function configureHydratorNamingStrategy(
        AbstractHydrator $hydrator,
        ContainerInterface $container,
        array $config,
        ObjectManager $objectManager
    ): void {
        if (!isset($config['naming_strategy'])) {
            return;
        }

        $namingStrategyKey = $config['naming_strategy'];
        if (!$container->has($namingStrategyKey)) {
            throw new ServiceNotCreatedException(sprintf('Invalid naming strategy %s.', $namingStrategyKey));
        }

        $namingStrategy = $container->get($namingStrategyKey);
        if (!$namingStrategy instanceof NamingStrategyInterface) {
            throw new ServiceNotCreatedException(
                sprintf('Invalid naming strategy class %s', get_class($namingStrategy))
            );
        }

        // Attach object manager:
        if ($namingStrategy instanceof ObjectManagerAwareInterface) {
            $namingStrategy->setObjectManager($objectManager);
        }

        $hydrator->setNamingStrategy($namingStrategy);
    }

    /**
     * @param AbstractHydrator $hydrator
     * @param ContainerInterface $container
     * @param array $config
     * @param ObjectManager $objectManager
     *
     * @throws ServiceNotCreatedException|ContainerExceptionInterface
     */
    protected function configureHydratorStrategies(
        AbstractHydrator $hydrator,
        ContainerInterface $container,
        array $config,
        ObjectManager $objectManager
    ): void {
        if (!isset($config['strategies'])
            || !is_array($config['strategies'])
        ) {
            return;
        }

        foreach ($config['strategies'] as $field => $strategyKey) {
            if (!$container->has($strategyKey)) {
                throw new ServiceNotCreatedException(
                    sprintf('Invalid strategy %s for field %s', $strategyKey, $field)
                );
            }

            $strategy = $container->get($strategyKey);
            if (!$strategy instanceof StrategyInterface) {
                throw new ServiceNotCreatedException(
                    sprintf('Invalid strategy class %s for field %s', get_class($strategy), $field)
                );
            }

            // Attach object manager:
            if ($strategy instanceof ObjectManagerAwareInterface) {
                $strategy->setObjectManager($objectManager);
            }

            $hydrator->addStrategy($field, $strategy);
        }
    }

    /**
     * Add filters to the Hydrator based on a predefined configuration format, if specified.
     *
     * @param AbstractHydrator $hydrator
     * @param ContainerInterface $container
     * @param array $config
     * @param ObjectManager $objectManager
     *
     * @throws ServiceNotCreatedException|ContainerExceptionInterface
     */
    protected function configureHydratorFilters(
        AbstractHydrator $hydrator,
        ContainerInterface $container,
        array $config,
        ObjectManager $objectManager
    ): void {
        if (!isset($config['filters'])
            || !is_array($config['filters'])
        ) {
            return;
        }

        foreach ($config['filters'] as $name => $filterConfig) {
            $conditionMap = array(
                'and' => FilterComposite::CONDITION_AND,
                'or' => FilterComposite::CONDITION_OR,
            );
            $condition = isset($filterConfig['condition']) ?
                $conditionMap[$filterConfig['condition']] :
                FilterComposite::CONDITION_OR;

            $filterService = $filterConfig['filter'];
            if (!$container->has($filterService)) {
                throw new ServiceNotCreatedException(
                    sprintf('Invalid filter %s for field %s: service does not exist', $filterService, $name)
                );
            }

            $filterService = $container->get($filterService);
            if (!$filterService instanceof FilterInterface) {
                throw new ServiceNotCreatedException(
                    sprintf('Filter service %s must implement FilterInterface', get_class($filterService))
                );
            }

            if ($filterService instanceof ObjectManagerAwareInterface) {
                $filterService->setObjectManager($objectManager);
            }
            $hydrator->addFilter($name, $filterService, $condition);
        }
    }
}
