<?php

namespace Railt\Doctrine\ORM;

use Doctrine\ORM\Tools\Setup as BaseSetup;

class Setup
{
    public static function createSDLMetadataConfiguration(array $paths, $isDevMode = false, $proxyDir = null, Cache $cache = null)
    {
        $config = BaseSetup::createConfiguration($isDevMode, $proxyDir, $cache);
        $config->setMetadataDriverImpl(new SDLDriver($paths));
        return $config;
    }
}