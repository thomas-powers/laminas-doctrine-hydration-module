<?php

declare(strict_types=1);

namespace DigitalWizard\DoctrineHydrationModule\Hydrator;

use Laminas\Hydrator\HydratorInterface;

/**
 * Class DoctrineHydrator.
 */
class DoctrineHydrator implements HydratorInterface
{
    /**
     * @var HydratorInterface
     */
    protected $extractService;

    /**
     * @var HydratorInterface
     */
    protected $hydrateService;

    /**
     * @param $extractService
     * @param $hydrateService
     */
    public function __construct($extractService, $hydrateService)
    {
        $this->extractService = $extractService;
        $this->hydrateService = $hydrateService;
    }

    /**
     * @return \Laminas\Hydrator\HydratorInterface
     */
    public function getExtractService()
    {
        return $this->extractService;
    }

    /**
     * @return \Laminas\Hydrator\HydratorInterface
     */
    public function getHydrateService()
    {
        return $this->hydrateService;
    }

    /**
     * Extract values from an object.
     *
     * @param object $object
     *
     * @return array
     */
    public function extract(object $object): array
    {
        return $this->extractService->extract($object);
    }

    /**
     * Hydrate $object with the provided $data.
     *
     * @param array $data
     * @param object $object
     *
     * @return object
     */
    public function hydrate(array $data, $object)
    {
        if ($this->hydrateService instanceof HydratorInterface) {
            return $this->hydrateService->hydrate($data, $object);
        }

        return $this->hydrateService->hydrate($object, $data);
    }
}
