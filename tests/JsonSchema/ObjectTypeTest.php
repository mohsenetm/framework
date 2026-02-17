<?php

namespace Illuminate\Tests\JsonSchema;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use PHPUnit\Framework\TestCase;

class ObjectTypeTest extends TestCase
{
    public function testItMayNotHaveProperties(): void
    {
        $type = JsonSchema::object()->title('Payload');

        $this->assertEquals([
            'type' => 'object',
            'title' => 'Payload',
        ], $type->toArray());
    }

    public function testItMayBeInitializedWithAClosureButWithoutProperties(): void
    {
        $type = JsonSchema::object(fn () => [])->title('Payload');

        $this->assertEquals([
            'type' => 'object',
            'title' => 'Payload',
        ], $type->toArray());
    }

    public function testItMayHaveProperties(): void
    {
        $type = JsonSchema::object([
            'age-a' => JsonSchema::integer()->min(0)->required(),
            'age-b' => JsonSchema::integer()->default(30)->max(45),
        ])->description('Root object');

        $this->assertEquals([
            'type' => 'object',
            'description' => 'Root object',
            'properties' => [
                'age-a' => [
                    'type' => 'integer',
                    'minimum' => 0,
                ],
                'age-b' => [
                    'type' => 'integer',
                    'default' => 30,
                    'maximum' => 45,
                ],
            ],
            'required' => ['age-a'],
        ], $type->toArray());
    }

    public function testItMayBeInitializedWithAClosureButMayHaveProperties(): void
    {
        $type = JsonSchema::object(fn (JsonSchemaTypeFactory $schema) => [
            'age-a' => $schema->integer()->min(0)->required(),
            'age-b' => $schema->integer()->default(30)->max(45),
        ])->description('Root object');

        $this->assertEquals([
            'type' => 'object',
            'description' => 'Root object',
            'properties' => [
                'age-a' => [
                    'type' => 'integer',
                    'minimum' => 0,
                ],
                'age-b' => [
                    'type' => 'integer',
                    'default' => 30,
                    'maximum' => 45,
                ],
            ],
            'required' => ['age-a'],
        ], $type->toArray());
    }

    public function testItMayDisableAdditionalProperties(): void
    {
        $type = JsonSchema::object()->default(['age' => 1])->withoutAdditionalProperties();

        $this->assertEquals([
            'type' => 'object',
            'default' => ['age' => 1],
            'additionalProperties' => false,
        ], $type->toArray());
    }

    public function testItMaySetEnum(): void
    {
        $type = JsonSchema::object()->enum([
            ['a' => 1],
            ['a' => 2],
        ]);

        $this->assertEquals([
            'type' => 'object',
            'enum' => [
                ['a' => 1],
                ['a' => 2],
            ],
        ], $type->toArray());
    }
}
