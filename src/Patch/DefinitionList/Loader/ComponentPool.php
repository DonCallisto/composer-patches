<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Patch\DefinitionList\Loader;

use Vaimo\ComposerPatches\Patch\DefinitionList\LoaderComponents;

use Vaimo\ComposerPatches\Config as PluginConfig;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ComponentPool
{
    /**
     * @var \Composer\Composer
     */
    private $composer;

    /**
     * @var \Composer\IO\IOInterface
     */
    private $appIO;

    /**
     * @var bool[]|\Vaimo\ComposerPatches\Interfaces\DefinitionListLoaderComponentInterface[]
     */
    private $components = array();

    /**
     * @param \Composer\Composer $composer
     * @param \Composer\IO\IOInterface $appIO
     */
    public function __construct(
        \Composer\Composer $composer,
        \Composer\IO\IOInterface $appIO
    ) {
        $this->composer = $composer;
        $this->appIO = $appIO;
    }

    public function getList($skippedPackages)
    {
        $rootPackage = $this->composer->getPackage();
        $extra = $rootPackage->getExtra();
        
        if (isset($extra['excluded-patches']) && !isset($extra[PluginConfig::EXCLUDED_PATCHES])) {
            $extra[PluginConfig::EXCLUDED_PATCHES] = $extra['excluded-patches'];
        }

        $excludes = isset($extra[PluginConfig::EXCLUDED_PATCHES])
            ? $extra[PluginConfig::EXCLUDED_PATCHES]
            : array();

        $installationManager = $this->composer->getInstallationManager();
        $composerConfig = clone $this->composer->getConfig();
        
        $vendorRoot = $composerConfig->get(\Vaimo\ComposerPatches\Composer\ConfigKeys::VENDOR_DIR);

        $packageInfoResolver = new \Vaimo\ComposerPatches\Package\InfoResolver(
            $installationManager,
            $vendorRoot
        );

        $configExtractor = new \Vaimo\ComposerPatches\Package\ConfigExtractors\VendorConfigExtractor(
            $packageInfoResolver
        );

        $downloader = new \Composer\Util\RemoteFilesystem($this->appIO, $composerConfig);

        $platformPackages = $this->resolveConstraintPackages($composerConfig);
        
        $defaults = array(
            'bundle' => new LoaderComponents\BundleComponent($rootPackage),
            'global-exclude' => $excludes ? new LoaderComponents\GlobalExcludeComponent($excludes) : false,
            'local-exclude' => new LoaderComponents\LocalExcludeComponent(),
            'root-patch' => new LoaderComponents\RootPatchComponent($rootPackage),
            'custom-exclude' => new LoaderComponents\CustomExcludeComponent($skippedPackages),
            'path-normalizer' => new LoaderComponents\PathNormalizerComponent($packageInfoResolver),
            'platform' => new LoaderComponents\PlatformComponent($platformPackages),
            'constraints' => new LoaderComponents\ConstraintsComponent($configExtractor),
            'downloader' => new LoaderComponents\DownloaderComponent($rootPackage, $downloader),
            'validator' => new LoaderComponents\ValidatorComponent(),
            'targets-resolver' => new LoaderComponents\TargetsResolverComponent($packageInfoResolver),
            'merger' => new LoaderComponents\MergerComponent(),
            'sorter' => new LoaderComponents\SorterComponent()
        );

        return array_values(
            array_filter(
                array_replace($defaults, $this->components)
            )
        );
    }

    private function resolveConstraintPackages(\Composer\Config $composerConfig)
    {
        $platformOverrides = array_filter(
            (array)$composerConfig->get('platform')
        );
        
        if (!empty($platformOverrides)) {
            $platformOverrides = array();
        }

        $platformRepo = new \Composer\Repository\PlatformRepository(
            array(),
            $platformOverrides ? $platformOverrides : array()
        );

        $platformPackages = array();

        foreach ($platformRepo->getPackages() as $package) {
            $platformPackages[$package->getName()] = $package;
        }
        
        return $platformPackages;
    }
    
    public function registerComponent($name, $instance)
    {
        $this->components[$name] = $instance;
    }
}
