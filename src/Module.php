<?php

declare(strict_types=1);

namespace DigitalWizard\DoctrineHydrationModule;

/**
 * Class Module
 */
class Module
{
    /**
     * @return array
     */
    public function getConfig(): array
    {
        return include dirname(__DIR__) . '/config/module.config.php';
    }
}
