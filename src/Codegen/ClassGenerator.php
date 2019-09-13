<?php

declare(strict_types=1);

namespace Spawnia\Sailor\Codegen;

use GraphQL\Type\Schema;
use Spawnia\Sailor\Result;
use GraphQL\Utils\TypeInfo;
use GraphQL\Language\Visitor;
use Spawnia\Sailor\Operation;
use Spawnia\Sailor\TypedObject;
use GraphQL\Type\Definition\Type;
use Nette\PhpGenerator\ClassType;
use GraphQL\Language\AST\NodeKind;
use Spawnia\Sailor\EndpointConfig;
use GraphQL\Language\AST\FieldNode;
use Nette\PhpGenerator\PhpNamespace;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\OperationDefinitionNode;

class ClassGenerator
{
    /**
     * @var Schema
     */
    protected $schema;

    /**
     * @var EndpointConfig
     */
    protected $endpointConfig;

    /**
     * @var string
     */
    protected $endpoint;

    /**
     * @var OperationSet
     */
    private $operationSet;

    /**
     * @var OperationSet[]
     */
    private $operationStorage = [];

    /**
     * @var string[]
     */
    private $namespaceStack = [];

    public function __construct(Schema $schema, EndpointConfig $endpointConfig, string $endpoint)
    {
        $this->schema = $schema;
        $this->endpointConfig = $endpointConfig;
        $this->endpoint = $endpoint;
        $this->namespaceStack [] = $endpointConfig->namespace();
    }

    /**
     * @param  DocumentNode  $document
     * @return OperationSet[]
     */
    public function generate(DocumentNode $document): array
    {
        $typeInfo = new TypeInfo($this->schema);

        Visitor::visit(
            $document,
            Visitor::visitWithTypeInfo(
                $typeInfo,
                [
                    // A named operation, e.g. "mutation FooMutation", maps to a class
                    NodeKind::OPERATION_DEFINITION => [
                        'enter' => function (OperationDefinitionNode $operationDefinition) {
                            $operationName = $operationDefinition->name->value;

                            // Generate a class to represent the query/mutation itself
                            $operation = new ClassType($operationName, $this->makeNamespace());

                            // The base class contains most of the logic
                            $operation->setExtends(Operation::class);

                            // The execute method is the public API of the operation
                            $execute = $operation->addMethod('execute');
                            $execute->setStatic();

                            // It returns a typed result which is a new selection set class
                            $resultName = "{$operationName}Result";

                            // Related classes are put into a nested namespace
                            $this->namespaceStack [] = $operationName;
                            $resultClass = $this->currentNamespace().'\\'.$resultName;

                            $execute->setReturnType($resultClass);
                            $execute->setBody(<<<PHP
                            \$response = self::fetchResponse();

                            return \\$resultClass::fromResponse(\$response);
                            PHP
                            );

                            // Store the actual query string in the operation
                            // TODO minify the query string
                            $document = $operation->addMethod('document');
                            $document->setStatic();
                            $document->setReturnType('string');
                            $document->setBody(<<<PHP
                            return /** @lang GraphQL */ '{$operationDefinition->loc->source->body}';
                            PHP
                            );

                            // Set the endpoint this operation belongs to
                            $document = $operation->addMethod('endpoint');
                            $document->setStatic();
                            $document->setReturnType('string');
                            $document->setBody(<<<PHP
                            return '{$this->endpoint}';
                            PHP
                            );

                            $operationResult = new ClassType($resultName, $this->makeNamespace());
                            $operationResult->setExtends(Result::class);

                            $setData = $operationResult->addMethod('setData');
                            $setData->setVisibility('protected');
                            $dataParam = $setData->addParameter('data');
                            $setData->setReturnType('void');
                            $dataParam->setTypeHint('\\stdClass');
                            $setData->setBody(<<<PHP
                            \$this->data = $operationName::fromSelectionSet(\$data);
                            PHP
                            );

                            $dataProp = $operationResult->addProperty('data');
                            $dataProp->setComment("@var $operationName|null");

                            $this->operationSet = new OperationSet($operation);

                            $this->operationSet->result = $operationResult;

                            $this->operationSet->pushSelection(
                                $this->makeTypedObject($operationName)
                            );
                        },
                        'leave' => function (OperationDefinitionNode $operationDefinition) {
                            // Store the current operation as we continue with the next one
                            $this->operationStorage [] = $this->operationSet;
                        },
                    ],
                    NodeKind::FIELD => [
                        'enter' => function (FieldNode $field) use ($typeInfo) {
                            // We are only interested in the key that will come from the server
                            $resultKey = $field->alias
                                ? $field->alias->value
                                : $field->name->value;

                            $selection = $this->operationSet->peekSelection();

                            $type = $typeInfo->getType();

                            $namedType = Type::getNamedType($type);

                            if ($namedType instanceof ObjectType) {
                                $typedObjectName = ucfirst($resultKey);
                                $typeReference = $this->currentNamespace().'\\'.$typedObjectName;

                                $this->operationSet->pushSelection(
                                    $this->makeTypedObject($typedObjectName)
                                );

                                // We go one level deeper into the selection set
                                // To avoid naming conflicts, we add on another namespace
                                $this->namespaceStack [] = $typeReference;
                                $typeMapper = <<<PHP
                                function (\\stcClass \$value): \Spawnia\Sailor\ObjectType {
                                    return $typeReference::fromSelectionSet(\$value);
                                }
                                PHP;
                            } elseif ($namedType instanceof ScalarType) {
                                // TODO support Int, Boolean, Float, Enum

                                $typeReference = PhpDoc::forScalar($namedType);
                                $typeMapper = <<<PHP
                                new \Spawnia\Sailor\Mapper\StringMapper()
                                PHP;
                            }

                            $field = $selection->addProperty($resultKey);
                            $field->setComment('@var '.PhpDoc::forType($type, $typeReference));

                            $typeField = $selection->addMethod(self::typeDiscriminatorMethodName($resultKey));
                            $keyParam = $typeField->addParameter('key');
                            $keyParam->setTypeHint('string');
                            $typeField->setReturnType('callable');
                            $typeField->setBody(<<<PHP
                            return $typeMapper;
                            PHP
                            );
                        },
                    ],
                    NodeKind::SELECTION_SET => [
                        'leave' => function (SelectionSetNode $selectionSet) use ($typeInfo) {
                            // We are done with building this subtree of the selection set,
                            // so we move the top-most element to the storage
                            $this->operationSet->popSelection();

                            // The namespace moves up a level
                            array_pop($this->namespaceStack);
                        },
                    ],
                ]
            )
        );

        return $this->operationStorage;
    }

    protected function makeTypedObject(string $name): ClassType
    {
        $typedObject = new ClassType(
            $name,
            $this->makeNamespace()
        );
        $typedObject->addExtend(TypedObject::class);

        return $typedObject;
    }

    public static function typeDiscriminatorMethodName(string $propertyKey): string
    {
        return 'type'.ucfirst($propertyKey);
    }

    protected function makeNamespace(): PhpNamespace
    {
        return new PhpNamespace(
            $this->currentNamespace()
        );
    }

    protected function currentNamespace(): string
    {
        return implode('\\', $this->namespaceStack);
    }
}
