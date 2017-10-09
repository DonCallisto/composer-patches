<?php
namespace Vaimo\ComposerPatches\Patch;

use Vaimo\ComposerPatches\Patch\Definition as PatchDefinition;

class DefinitionNormalizer
{
    public function process($patchTarget, $label, $data)
    {
        if (!is_array($data)) {
            $data = array(
                PatchDefinition::SOURCE => (string)$data
            );
        }

        if (!isset($data[PatchDefinition::URL]) && !isset($data[PatchDefinition::SOURCE])) {
            return false;
        }

        $source = isset($data[PatchDefinition::URL])
            ? $data[PatchDefinition::URL]
            : $data[PatchDefinition::SOURCE];

        $sourceSegments = explode('#', $source);
        $lastSegment = array_pop($sourceSegments);

        if ($lastSegment === PatchDefinition::SKIP) {
            $source = implode('#', $sourceSegments);
            $data[PatchDefinition::SKIP] = true;
        }
        
        $depends = array();
        
        if (isset($data[PatchDefinition::VERSION])) {
            if (is_array($data[PatchDefinition::VERSION])) {
                $depends = array_replace(
                    $depends, 
                    $data[PatchDefinition::VERSION]
                );
            } else {
                $depends = array_replace(
                    $depends, 
                    array($patchTarget => $data[PatchDefinition::VERSION])
                );
            }
        }
        
        if (isset($data[PatchDefinition::DEPENDS])) {
            $depends = array_replace(
                $depends, 
                $data[PatchDefinition::DEPENDS]
            );
        }
        
        return array(
            PatchDefinition::SOURCE => $source,
            PatchDefinition::TARGETS => isset($data[PatchDefinition::TARGETS])
                ? $data[PatchDefinition::TARGETS]
                : array($patchTarget),
            PatchDefinition::SKIP => isset($data[PatchDefinition::SKIP])
                ? $data[PatchDefinition::SKIP]
                : false,
            PatchDefinition::LABEL => isset($data[PatchDefinition::LABEL])
                ? $data[PatchDefinition::LABEL]
                : $label,
            PatchDefinition::DEPENDS => $depends
        );
    }
}
