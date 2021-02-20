<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Factories;

use Vaimo\ComposerPatches\Config as PluginConfig;
use Vaimo\ComposerPatches\Environment;

class ConfigFactory
{
    /**
     * @var \Vaimo\ComposerPatches\Composer\Context
     */
    private $composerContext;

    /**
     * @var \Vaimo\ComposerPatches\Config\Defaults
     */
    private $defaultsProvider;

    /**
     * @var \Vaimo\ComposerPatches\Utils\ConfigUtils
     */
    private $configUtils;

    /**
     * @var \Vaimo\ComposerPatches\Config\Context
     */
    private $context;

    /**
     * @var array
     */
    private $defaults;
    
    /**
     * @var \Vaimo\ComposerPatches\Utils\DataUtils
     */
    private $dataUtils;
    
    /**
     * @param \Vaimo\ComposerPatches\Composer\Context $composerContext
     * @param array $defaults
     */
    public function __construct(
        \Vaimo\ComposerPatches\Composer\Context $composerContext,
        array $defaults = array()
    ) {
        $this->composerContext = $composerContext;
        $this->defaults = $defaults;

        $this->defaultsProvider = new \Vaimo\ComposerPatches\Config\Defaults();
        $this->configUtils = new \Vaimo\ComposerPatches\Utils\ConfigUtils();
        $this->context = new \Vaimo\ComposerPatches\Config\Context();
        $this->dataUtils = new \Vaimo\ComposerPatches\Utils\DataUtils();
    }

    public function create(array $configSources = array())
    {
        $defaults = array_replace(
            $this->defaultsProvider->getPatcherConfig(),
            $this->defaults,
            array_filter(array(PluginConfig::PATCHER_GRACEFUL => (bool)getenv(Environment::GRACEFUL_MODE)))
        );

        $composer = $this->composerContext->getLocalComposer();
        
        $extra = $composer->getPackage()->getExtra();

        if (isset($extra['patcher-config']) && !isset($extra[PluginConfig::PATCHER_CONFIG_ROOT])) {
            $extra[PluginConfig::PATCHER_CONFIG_ROOT] = $extra['patcher-config'];
        }

        $subConfigKeys = array(
            $this->context->getOperationSystemTypeCode(),
            $this->context->getOperationSystemName(),
            $this->context->getOperationSystemFamily(),
            '',
        );

        foreach (array_unique($subConfigKeys) as $key) {
            $configRootKey = PluginConfig::PATCHER_CONFIG_ROOT . ($key ? ('-' . $key) : '');

            $patcherConfig = $this->resolvePatcherConfigBase($extra, $configRootKey);

            if (isset($patcherConfig['patchers']) && !isset($patcherConfig[PluginConfig::PATCHER_APPLIERS])) {
                $patcherConfig[PluginConfig::PATCHER_APPLIERS] = $patcherConfig['patchers'];
                unset($patcherConfig['patchers']);
            }

            if ($patcherConfig) {
                array_unshift($configSources, $patcherConfig);
            }
        }

        $config = array_reduce(
            $configSources,
            array($this->configUtils, 'mergeApplierConfig'),
            $defaults
        );

        return new PluginConfig(
            $this->resolveValidSubOperations($config, $subConfigKeys)
        );
    }

    private function resolvePatcherConfigBase(array $extra, $rootKey)
    {
        $patcherConfig = isset($extra[$rootKey]) ? $extra[$rootKey] : array();

        if ($patcherConfig === false) {
            $patcherConfig = array(
                PluginConfig::PATCHER_SOURCES => false
            );
        }

        if (!isset($patcherConfig[PluginConfig::PATCHER_SOURCES])) {
            if (isset($extra['enable-patching']) && !$extra['enable-patching']) {
                $patcherConfig[PluginConfig::PATCHER_SOURCES] = false;
            } elseif (isset($extra['enable-patching-from-packages']) && !$extra['enable-patching-from-packages']) {
                $patcherConfig[PluginConfig::PATCHER_SOURCES] = array('packages' => false, 'vendors' => false);
            }
        }

        return $patcherConfig;
    }

    private function resolveValidSubOperations(array $config, array $subConfigKeys)
    {
        $subOperationKeys = array_merge(
            array_filter($subConfigKeys),
            array(PluginConfig::OS_DEFAULT)
        );

        $baseOperations = $config[PluginConfig::PATCHER_APPLIERS][PluginConfig::APPLIER_DEFAULT];

        foreach ($config[PluginConfig::PATCHER_APPLIERS] as $applierCode => $operations) {
            if ($applierCode === PluginConfig::APPLIER_DEFAULT) {
                continue;
            }

            $operations = array_replace($baseOperations, $operations);

            foreach ($operations as $opCode => $operation) {
                if (!is_array($operation)) {
                    continue;
                }

                if (array_filter($operation, 'is_numeric')) {
                    continue;
                }

                $subOperations = $this->dataUtils->extractOrderedItems($operation, $subOperationKeys);

                if (empty($subOperations)) {
                    continue;
                }

                $config[PluginConfig::PATCHER_APPLIERS][$applierCode][$opCode] = reset($subOperations);
            }
        }

        return $config;
    }
}
