<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Patch\SourceLoaders;

use Vaimo\ComposerPatches\Patch\Definition as PatchDefinition;

class PatchesSearch implements \Vaimo\ComposerPatches\Interfaces\PatchSourceLoaderInterface
{
    /**
     * @var \Composer\Installer\InstallationManager
     */
    private $installationManager;

    /**
     * @var \Vaimo\ComposerPatches\Package\ConfigReader
     */
    private $configLoader;

    /**
     * @var \Vaimo\ComposerPatches\Patch\File\Analyser
     */
    private $fileAnalyser;

    /**
     * @var \Vaimo\ComposerPatches\Patch\File\Header\Parser
     */
    private $patchHeaderParser;

    /**
     * @var \Vaimo\ComposerPatches\Utils\FileSystemUtils
     */
    private $fileSystemUtils;

    /**
     * @var array
     */
    private $tagAliases = array();

    /**
     * @param \Composer\Installer\InstallationManager $installationManager
     */
    public function __construct(
        \Composer\Installer\InstallationManager $installationManager
    ) {
        $this->installationManager = $installationManager;

        $this->configLoader = new \Vaimo\ComposerPatches\Package\ConfigReader();
        $this->fileAnalyser = new \Vaimo\ComposerPatches\Patch\File\Analyser();
        $this->patchHeaderParser = new \Vaimo\ComposerPatches\Patch\File\Header\Parser();
        $this->fileSystemUtils = new \Vaimo\ComposerPatches\Utils\FileSystemUtils();

        $this->tagAliases = array(
            PatchDefinition::LABEL => array('desc', 'description', 'reason'),
            PatchDefinition::ISSUE => array('ticket', 'issues', 'tickets'),
            PatchDefinition::VERSION => array('constraint'),
            PatchDefinition::PACKAGE => array('target', 'module', 'targets'),
            PatchDefinition::LINK => array('links', 'reference', 'ref', 'url')
        );
    }

    public function load(\Composer\Package\PackageInterface $package, $source)
    {
        if (!is_array($source)) {
            $source = array($source);
        }

        if ($package instanceof \Composer\Package\RootPackage) {
            $basePath = getcwd();
        } else {
            $basePath = $this->installationManager->getInstallPath($package);
        }

        $results = array();

        foreach ($source as $item) {
            $rootPath = $basePath . DIRECTORY_SEPARATOR . $item;
            $basePathLength = strlen($basePath);

            $paths = $this->fileSystemUtils->collectPathsRecursively(
                $rootPath,
                \Vaimo\ComposerPatches\Config::PATCH_FILE_REGEX_MATCHER
            );

            $groups = array();

            foreach ($paths as $path) {
                $contents = file_get_contents($path);

                $definition = $this->createDefinitionItem($contents, array(
                    PatchDefinition::PATH => $path,
                    PatchDefinition::SOURCE => trim(substr($path, $basePathLength), '/')
                ));

                if (!isset($definition[PatchDefinition::TARGET])) {
                    continue;
                }

                $target = $definition[PatchDefinition::TARGET];

                if (!isset($groups[$target])) {
                    $groups[$target] = array();
                }

                $groups[$target][] = $definition;
            }

            $results[] = $groups;
        }

        return $results;
    }

    private function createDefinitionItem($contents, array $values = array())
    {
        $header = $this->fileAnalyser->getHeader($contents);

        $data = $this->applyAliases(
            $this->patchHeaderParser->parseContents($header),
            $this->tagAliases
        );

        $target = false;

        $package = $this->extractSingleValue($data, PatchDefinition::PACKAGE);
        $depends = $this->extractSingleValue($data, PatchDefinition::DEPENDS);
        $version = $this->extractSingleValue($data, PatchDefinition::VERSION, '>=0.0.0');

        if (strpos($version, ':') !== false) {
            $valueParts = explode(':', $version);

            $depends = trim(array_shift($valueParts));
            $version = trim(implode(':', $valueParts));
        }

        if (strpos($package, ':') !== false) {
            $valueParts = explode(':', $package);

            $package = trim(array_shift($valueParts));
            $version = trim(implode(':', $valueParts));
        }

        if (!$target && $package) {
            $target = $package;
        }

        if (!$target && $depends) {
            $target = $depends;
        }

        if (!$depends && $target) {
            $depends = $target;
        }

        if (!$target) {
            return array();
        }

        return array_replace(array(
            PatchDefinition::LABEL => implode(
                PHP_EOL,
                isset($data[PatchDefinition::LABEL]) ? $data[PatchDefinition::LABEL] : array('')
            ),
            PatchDefinition::TARGET => $target,
            PatchDefinition::DEPENDS => array($depends => $version),
            PatchDefinition::SKIP => isset($data[PatchDefinition::SKIP]),
            PatchDefinition::AFTER => $this->extractValueList($data, PatchDefinition::AFTER),
            PatchDefinition::ISSUE => $this->extractSingleValue($data, PatchDefinition::ISSUE),
            PatchDefinition::LINK => $this->extractSingleValue($data, PatchDefinition::LINK)
        ), $values);
    }

    private function extractValueList(array $data, $name)
    {
        return isset($data[$name]) ? array_filter($data[$name]) : array();
    }

    private function extractSingleValue(array $data, $name, $default = null)
    {
        return isset($data[$name]) ? reset($data[$name]) : $default;
    }

    private function applyAliases(array $data, array $aliases)
    {
        foreach ($aliases as $target => $origins) {
            if (isset($data[$target])) {
                continue;
            }

            foreach ($origins as $origin) {
                if (!isset($data[$origin])) {
                    continue;
                }

                $data[$target] = $data[$origin];
            }
        }

        return $data;
    }
}