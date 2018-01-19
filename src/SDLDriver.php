<?php

namespace Railt\Doctrine\ORM;

use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Railt\Io\File;
use Railt\Reflection\Contracts\Dependent\FieldDefinition;
use Railt\Reflection\Contracts\Invocations\DirectiveInvocation;
use Railt\SDL\Compiler;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\Common\Persistence\Mapping\Driver\FileDriver;
use Railt\Reflection\Contracts\Definitions\ObjectDefinition;
use Doctrine\Orm\Mapping;

class SDLDriver extends FileDriver
{
    const DEFAULT_FILE_EXTENSION = '.dcm.graphqls';

    /**
     * @var Compiler
     */
    private $compiler;

    public function __construct($locator, ?string $fileExtension = self::DEFAULT_FILE_EXTENSION, ?Compiler $compiler = null)
    {
        if (!$compiler) {
            $compiler = new Compiler();
        }

        $this->compiler = $compiler;

        parent::__construct($locator, $fileExtension);
    }

    /**
     * Loads a mapping file with the given name and returns a map
     * from class/entity names to their corresponding file driver elements.
     *
     * @param string $file The mapping file to load.
     *
     * @return array
     */
    protected function loadMappingFile($file)
    {
        $document = $this->compiler->compile(File::fromPathname($file));

        $result = [];

        foreach ($document->getTypeDefinitions() as $definition) {
            if ($definition instanceof ObjectDefinition) {
                $className = $definition->getDirective('Class')->getPassedArgument('name');
                $result[$className] = $definition;
            }
        }

        return $result;
    }

    /**
     * Loads the metadata for the specified class into the provided container.
     *
     * @param string $className
     * @param ClassMetadata $metadata
     *
     * @return void
     * @throws \Doctrine\Common\Persistence\Mapping\MappingException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function loadMetadataForClass($className, ClassMetadata $metadata)
    {
        $metadata->getReflectionClass()->getName();

        /* @var $metadata \Doctrine\ORM\Mapping\ClassMetadataInfo */

        /* @var $objectDefinition \Railt\Reflection\Contracts\Definitions\ObjectDefinition */
        $objectDefinition = $this->getElement($className);

        $this->evaluateObjectDirectives($objectDefinition, $metadata);


        // Evaluate annotations on properties/fields
        /* @var $property \ReflectionProperty */

        foreach ($objectDefinition->getFields() as $fieldDefinition) {
            $this->evaluateFieldDirectives($fieldDefinition, $metadata);
        }


        // Evaluate AssociationOverrides annotation
        if (isset($classAnnotations[Mapping\AssociationOverrides::class])) {
            $associationOverridesAnnot = $classAnnotations[Mapping\AssociationOverrides::class];

            foreach ($associationOverridesAnnot->value as $associationOverride) {
                $override = [];
                $fieldName = $associationOverride->name;

                // Check for JoinColumn/JoinColumns annotations
                if ($associationOverride->joinColumns) {
                    $joinColumns = [];

                    foreach ($associationOverride->joinColumns as $joinColumn) {
                        $joinColumns[] = $this->joinColumnToArray($joinColumn);
                    }

                    $override['joinColumns'] = $joinColumns;
                }

                // Check for JoinTable annotations
                if ($associationOverride->joinTable) {
                    $joinTableAnnot = $associationOverride->joinTable;
                    $joinTable = [
                        'name' => $joinTableAnnot->name,
                        'schema' => $joinTableAnnot->schema
                    ];

                    foreach ($joinTableAnnot->joinColumns as $joinColumn) {
                        $joinTable['joinColumns'][] = $this->joinColumnToArray($joinColumn);
                    }

                    foreach ($joinTableAnnot->inverseJoinColumns as $joinColumn) {
                        $joinTable['inverseJoinColumns'][] = $this->joinColumnToArray($joinColumn);
                    }

                    $override['joinTable'] = $joinTable;
                }

                // Check for inversedBy
                if ($associationOverride->inversedBy) {
                    $override['inversedBy'] = $associationOverride->inversedBy;
                }

                // Check for `fetch`
                if ($associationOverride->fetch) {
                    $override['fetch'] = constant(Mapping\ClassMetadata::class . '::FETCH_' . $associationOverride->fetch);
                }

                $metadata->setAssociationOverride($fieldName, $override);
            }
        }

        // Evaluate AttributeOverrides annotation
        if (isset($classAnnotations[Mapping\AttributeOverrides::class])) {
            $attributeOverridesAnnot = $classAnnotations[Mapping\AttributeOverrides::class];

            foreach ($attributeOverridesAnnot->value as $attributeOverrideAnnot) {
                $attributeOverride = $this->columnToArray($attributeOverrideAnnot->name, $attributeOverrideAnnot->column);

                $metadata->setAttributeOverride($attributeOverrideAnnot->name, $attributeOverride);
            }
        }

        // Evaluate EntityListeners annotation
        if (isset($classAnnotations[Mapping\EntityListeners::class])) {
            $entityListenersAnnot = $classAnnotations[Mapping\EntityListeners::class];

            foreach ($entityListenersAnnot->value as $item) {
                $listenerClassName = $metadata->fullyQualifiedClassName($item);

                if (!class_exists($listenerClassName)) {
                    throw MappingException::entityListenerClassNotFound($listenerClassName, $className);
                }

                $hasMapping = false;
                $listenerClass = new \ReflectionClass($listenerClassName);

                /* @var $method \ReflectionMethod */
                foreach ($listenerClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    // find method callbacks.
                    $callbacks = $this->getMethodCallbacks($method);
                    $hasMapping = $hasMapping ?: (!empty($callbacks));

                    foreach ($callbacks as $value) {
                        $metadata->addEntityListener($value[1], $listenerClassName, $value[0]);
                    }
                }

                // Evaluate the listener using naming convention.
                if (!$hasMapping) {
                    EntityListenerBuilder::bindEntityListener($metadata, $listenerClassName);
                }
            }
        }

        // Evaluate @HasLifecycleCallbacks annotation
        if (isset($classAnnotations[Mapping\HasLifecycleCallbacks::class])) {
            /* @var $method \ReflectionMethod */
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($this->getMethodCallbacks($method) as $value) {
                    $metadata->addLifecycleCallback($value[0], $value[1]);
                }
            }
        }
    }

    protected function cacheToArray(DirectiveInvocation $cacheMapping)
    {
        $region = $cacheMapping->getPassedArgument('region');
        $usage = $cacheMapping->getPassedArgument('usage');

        $region = isset($region) ? (string)$region : null;
        $usage = isset($usage) ? strtoupper($usage) : null;

        if ($usage && !defined('Doctrine\ORM\Mapping\ClassMetadata::CACHE_USAGE_' . $usage)) {
            throw new \InvalidArgumentException(sprintf('Invalid cache usage "%s"', $usage));
        }

        if ($usage) {
            $usage = constant('Doctrine\ORM\Mapping\ClassMetadata::CACHE_USAGE_' . $usage);
        }

        return [
            'usage' => $usage,
            'region' => $region,
        ];
    }

    /**
     * @param ObjectDefinition $definition
     * @param ClassMetadataInfo $metadata
     * @throws MappingException
     */
    private function evaluateEntity(ObjectDefinition $definition, ClassMetadataInfo $metadata)
    {
        switch (true) {
            case $entity = $definition->getDirective('Entity'):
                if ($repository = $entity->getPassedArgument('repositoryClass')) {
                    $metadata->setCustomRepositoryClass($repository);
                }

                if ($entity->getPassedArgument('readOnly')) {
                    $metadata->markReadOnly();
                }
                break;
            case $mappedSuperClass = $definition->getDirective('MappedSuperClass'):
                if ($repository = $mappedSuperClass->getPassedArgument('repositoryClass')) {
                    $metadata->setCustomRepositoryClass($repository);
                }
                $metadata->isMappedSuperclass = true;
                break;
            case $definition->getDirective('Embeddable'):
                $metadata->isEmbeddedClass = true;
                break;
            default:
                throw MappingException::classIsNotAValidEntityOrMappedSuperClass($className);
                break;
        }
    }

    private function evaluateTable(ObjectDefinition $definition, ClassMetadataInfo $metadata)
    {
        if ($table = $definition->getDirective('Table')) {
            $primaryTable = [
                'name' => $table->getPassedArgument('name'),
                'schema' => $table->getPassedArgument('schema')
            ];

            if ($indexes = $table->getPassedArgument('indexes')) {
                $primaryTable['indexes'] = $indexes;
            }

            if ($uniqueConstraints = $table->getPassedArgument('uniqueConstraints ')) {
                $primaryTable['uniqueConstraints'] = $uniqueConstraints;
            }

            if ($options = $table->getPassedArgument('options')) {
                $primaryTable['options'] = $options;
            }

            $metadata->setPrimaryTable($primaryTable);
        }
    }

    private function evaluateCache(ObjectDefinition $definition, ClassMetadataInfo $metadata)
    {
        if ($cache = $definition->getDirective('Cache')) {
            $cacheMap = [
                'region' => $cache->getPassedArgument('region'),
                'usage' => constant('Doctrine\ORM\Mapping\ClassMetadata::CACHE_USAGE_' . $cache->getPassedArgument('usage')),
            ];


            $metadata->enableCache($cacheMap);
        }
    }

    /**
     * @param ObjectDefinition $definition
     * @param ClassMetadataInfo $metadata
     * @throws MappingException
     */
    private function evaluateNamedNativeQueries(ObjectDefinition $definition, ClassMetadataInfo $metadata)
    {
        if ($namedNative = $definition->getDirective('NamedNative')) {
            foreach ($namedNative['queries'] as $namedNativeQuery) {
                $metadata->addNamedNativeQuery($namedNativeQuery);
            }
        }
    }

    /**
     * @param ObjectDefinition $definition
     * @param ClassMetadataInfo $metadata
     * @throws MappingException
     */
    private function evaluateSqlResultSetMappings(ObjectDefinition $definition, ClassMetadataInfo $metadata)
    {
        if ($sqlResult = $definition->getDirective('SqlResult')) {
            foreach ($sqlResult['mappings'] as $resultMapping) {
                $metadata->addSqlResultSetMapping($resultMapping);
            }
        }
    }

    /**
     * @param ObjectDefinition $definition
     * @param ClassMetadataInfo $metadata
     * @throws MappingException
     */
    private function evaluateNamedQueries(ObjectDefinition $definition, ClassMetadataInfo $metadata)
    {
        if ($named = $definition->getDirective('Named')) {
            foreach ($named['queries'] as $namedQuery) {
                $metadata->addNamedQuery($namedQuery);
            }
        }
    }

    /**
     * @param ObjectDefinition $definition
     * @param ClassMetadataInfo $metadata
     * @throws MappingException
     */
    private function evaluateInheritance(ObjectDefinition $definition, ClassMetadataInfo $metadata)
    {
        // Evaluate InheritanceType annotation
        if ($inheritance = $definition->getDirective('Inheritance')) {
            $metadata->setInheritanceType(
                constant('Doctrine\ORM\Mapping\ClassMetadata::INHERITANCE_TYPE_' . $inheritance->getPassedArgument('type'))
            );

            if ($metadata->inheritanceType != Mapping\ClassMetadata::INHERITANCE_TYPE_NONE) {
                $this->evaluateDiscriminator($definition, $metadata);
            }
        }
    }

    /**
     * @param ObjectDefinition $definition
     * @param ClassMetadataInfo $metadata
     * @throws MappingException
     */
    private function evaluateDiscriminator(ObjectDefinition $definition, ClassMetadataInfo $metadata)
    {
        if ($discriminator = $definition->getDirective('Discriminator')) {
            $metadata->setDiscriminatorColumn($discriminator->getPassedArgument('column'));

            $map = $discriminator->getPassedArgument('map');

            $metadata->setDiscriminatorMap(
                array_combine(
                    array_column($map, 'alias'),
                    array_column($map, 'class')
                )
            );
        }
    }

    private function evaluateChangeTrackingPolicy(ObjectDefinition $definition, ClassMetadataInfo $metadata)
    {
        if ($changeTracking = $definition->getDirective('ChangeTracking')) {
            $metadata->setChangeTrackingPolicy(constant('Doctrine\ORM\Mapping\ClassMetadata::CHANGETRACKING_' . $changeTracking->getPassedArgument('policy')));
        }
    }

    /**
     * @param $definition
     * @param $metadata
     * @throws MappingException
     */
    private function evaluateObjectDirectives(ObjectDefinition $definition, $metadata)
    {
        $this->evaluateEntity($definition, $metadata);
        $this->evaluateTable($definition, $metadata);
        $this->evaluateCache($definition, $metadata);
        $this->evaluateNamedNativeQueries($definition, $metadata);
        $this->evaluateSqlResultSetMappings($definition, $metadata);
        $this->evaluateNamedQueries($definition, $metadata);
        $this->evaluateInheritance($definition, $metadata);
        $this->evaluateChangeTrackingPolicy($definition, $metadata);
    }

    /**
     * @param FieldDefinition $definition
     * @param $metadata
     * @throws MappingException
     */
    private function evaluateFieldDirectives(FieldDefinition $definition, ClassMetadataInfo $metadata)
    {

        // TODO: What happens there?
        //        if ($metadata->isMappedSuperclass && !$property->isPrivate()
        //            ||
        //            $metadata->isInheritedField($property->name)
        //            ||
        //            $metadata->isInheritedAssociation($property->name)
        //            ||
        //            $metadata->isInheritedEmbeddedClass($property->name)) {
        //            return;
        //        }


        $mapping = [];
        $mapping['fieldName'] = $definition->getName();

        // Evaluate @Cache annotation
        if ($cache = $definition->getDirective('Cache')) {
            $mapping['cache'] = $metadata->getAssociationCacheDefaults(
                $mapping['fieldName'],
                [
                    'usage' => constant('Doctrine\ORM\Mapping\ClassMetadata::CACHE_USAGE_' . $cache->getPassedArgument('usage')),
                    'region' => $cache->getPassedArgument('region'),
                ]
            );
        }

        // Check for JoinColumn/JoinColumns annotations
        $joinColumns = [];

        if ($join = $definition->getDirective('Join')) {
            switch (true) {
                case $column = $join->getPassedArgument('column'):
                    $joinColumns[] = $column;
                    break;
                case $columns = $join->getPassedArgument('columns'):
                    foreach ($columns as $column) {
                        $joinColumns[] = $column;
                    }
                    break;
                default:
                    throw new MappingException('Directive @Join should contain argument "column" or "columns"');
            }
        }

        // Field can only be annotated with one of:
        // @Column, @OneToOne, @OneToMany, @ManyToOne, @ManyToMany
        if ($column = $definition->getDirective('Column')) {
            $this->evaluateColumn($definition, $metadata);
        } else if ($oneToOneAnnot = $this->reader->getPropertyAnnotation($property, Mapping\OneToOne::class)) {
            if ($idAnnot = $this->reader->getPropertyAnnotation($property, Mapping\Id::class)) {
                $mapping['id'] = true;
            }

            $mapping['targetEntity'] = $oneToOneAnnot->targetEntity;
            $mapping['joinColumns'] = $joinColumns;
            $mapping['mappedBy'] = $oneToOneAnnot->mappedBy;
            $mapping['inversedBy'] = $oneToOneAnnot->inversedBy;
            $mapping['cascade'] = $oneToOneAnnot->cascade;
            $mapping['orphanRemoval'] = $oneToOneAnnot->orphanRemoval;
            $mapping['fetch'] = $this->getFetchMode($className, $oneToOneAnnot->fetch);
            $metadata->mapOneToOne($mapping);
        } else if ($oneToManyAnnot = $this->reader->getPropertyAnnotation($property, Mapping\OneToMany::class)) {
            $mapping['mappedBy'] = $oneToManyAnnot->mappedBy;
            $mapping['targetEntity'] = $oneToManyAnnot->targetEntity;
            $mapping['cascade'] = $oneToManyAnnot->cascade;
            $mapping['indexBy'] = $oneToManyAnnot->indexBy;
            $mapping['orphanRemoval'] = $oneToManyAnnot->orphanRemoval;
            $mapping['fetch'] = $this->getFetchMode($className, $oneToManyAnnot->fetch);

            if ($orderByAnnot = $this->reader->getPropertyAnnotation($property, Mapping\OrderBy::class)) {
                $mapping['orderBy'] = $orderByAnnot->value;
            }

            $metadata->mapOneToMany($mapping);
        } else if ($manyToOneAnnot = $this->reader->getPropertyAnnotation($property, Mapping\ManyToOne::class)) {
            if ($idAnnot = $this->reader->getPropertyAnnotation($property, Mapping\Id::class)) {
                $mapping['id'] = true;
            }

            $mapping['joinColumns'] = $joinColumns;
            $mapping['cascade'] = $manyToOneAnnot->cascade;
            $mapping['inversedBy'] = $manyToOneAnnot->inversedBy;
            $mapping['targetEntity'] = $manyToOneAnnot->targetEntity;
            $mapping['fetch'] = $this->getFetchMode($className, $manyToOneAnnot->fetch);
            $metadata->mapManyToOne($mapping);
        } else if ($manyToManyAnnot = $this->reader->getPropertyAnnotation($property, Mapping\ManyToMany::class)) {
            $joinTable = [];

            if ($joinTableAnnot = $this->reader->getPropertyAnnotation($property, Mapping\JoinTable::class)) {
                $joinTable = [
                    'name' => $joinTableAnnot->name,
                    'schema' => $joinTableAnnot->schema
                ];

                foreach ($joinTableAnnot->joinColumns as $joinColumn) {
                    $joinTable['joinColumns'][] = $this->joinColumnToArray($joinColumn);
                }

                foreach ($joinTableAnnot->inverseJoinColumns as $joinColumn) {
                    $joinTable['inverseJoinColumns'][] = $this->joinColumnToArray($joinColumn);
                }
            }

            $mapping['joinTable'] = $joinTable;
            $mapping['targetEntity'] = $manyToManyAnnot->targetEntity;
            $mapping['mappedBy'] = $manyToManyAnnot->mappedBy;
            $mapping['inversedBy'] = $manyToManyAnnot->inversedBy;
            $mapping['cascade'] = $manyToManyAnnot->cascade;
            $mapping['indexBy'] = $manyToManyAnnot->indexBy;
            $mapping['orphanRemoval'] = $manyToManyAnnot->orphanRemoval;
            $mapping['fetch'] = $this->getFetchMode($className, $manyToManyAnnot->fetch);

            if ($orderByAnnot = $this->reader->getPropertyAnnotation($property, Mapping\OrderBy::class)) {
                $mapping['orderBy'] = $orderByAnnot->value;
            }

            $metadata->mapManyToMany($mapping);
        } else if ($embeddedAnnot = $this->reader->getPropertyAnnotation($property, Mapping\Embedded::class)) {
            $mapping['class'] = $embeddedAnnot->class;
            $mapping['columnPrefix'] = $embeddedAnnot->columnPrefix;

            $metadata->mapEmbedded($mapping);
        }
    }

    private function evaluateColumn(FieldDefinition $definition, ClassMetadataInfo $metadata)
    {
        if ($column = $definition->getDirective('Column')) {
            $mapping = ['fieldName'] = $definition->getName();
            $mapping = array_merge($mapping, $column->getPassedArguments());

            if ($definition->getDirective('Id')) {
                $mapping['id'] = true;
            }

            if ($generatedValue = $definition->getDirective('GeneratedValue')) {
                $metadata->setIdGeneratorType(constant('Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_' . $generatedValue->getPassedArgument('strategy')));
            }

            if ($definition->getDirective('Version')) {
                $metadata->setVersionMapping($mapping);
            }

            $metadata->mapField($mapping);

            // Check for SequenceGenerator/TableGenerator definition
            if ($sequenceGenerator = $definition->getDirective('SequenceGenerator')) {
                $metadata->setSequenceGeneratorDefinition($sequenceGenerator->getPassedArguments());

            } else if ($definition->getDirective('TableGenerator')) {
                throw MappingException::tableIdGeneratorNotImplemented($metadata->name);
            } else if ($customGenerator = $definition->getDirective('CustomIdGenerator')) {
                $metadata->setCustomGeneratorDefinition(['class' => $customGenerator->getPassedArgument('class')]);
            }
        }
    }
}