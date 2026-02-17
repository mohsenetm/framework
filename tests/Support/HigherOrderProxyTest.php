<?php

namespace Illuminate\Tests\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\HigherOrderCollectionProxy;
use Illuminate\Support\HigherOrderTapProxy;
use PHPUnit\Framework\TestCase;

class HigherOrderProxyTest extends TestCase
{
    public function testGetProxiesPropertyAccessToItems()
    {
        $items = new Collection([
            (object) ['name' => 'Alice'],
            (object) ['name' => 'Bob'],
        ]);

        $proxy = new HigherOrderCollectionProxy($items, 'pluck');

        // The proxied method returns a Collection instance; assert type and values
        $this->assertInstanceOf(Collection::class, $proxy->name);
        $this->assertEquals(['Alice', 'Bob'], $proxy->name->all());
    }

    public function testCallProxiesMethodCallToItems()
    {
        $items = new Collection([
            new class
            {
                public function shout($s)
                {
                    return strtoupper($s);
                }
            },
            new class
            {
                public function shout($s)
                {
                    return strtoupper($s).'!';
                }
            },
        ]);

        $proxy = new HigherOrderCollectionProxy($items, 'map');

        $result = $proxy->shout('hey');

        $this->assertEquals(['HEY', 'HEY!'], $result->all());
    }

    public function testCallForwardsAndReturnsTarget()
    {
        $target = new class
        {
            public $count = 0;

            public function increment($by = 1)
            {
                $this->count += $by;
            }
        };

        $proxy = new HigherOrderTapProxy($target);

        $result = $proxy->increment(3);

        $this->assertSame(3, $target->count);
        $this->assertSame($target, $result);
    }
}
