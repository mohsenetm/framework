<?php

namespace Illuminate\Tests\Routing;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteBinding;
use PHPUnit\Framework\TestCase;

class RouteBindingTest extends TestCase
{
    public function testItCanResolveTheExplicitModelForTheGivenRoute()
    {
        $container = Container::getInstance();

        $route = new Route('GET', '/users/{user}', function () {
        });

        $callback = RouteBinding::forModel($container, ExplicitRouteBindingUser::class);
        $this->assertInstanceOf(ExplicitRouteBindingUser::class, $callback(1, $route));
    }

    public function testItCannotResolveTheExplicitSoftDeletedModelForTheGivenRoute()
    {
        $container = Container::getInstance();

        $route = new Route('GET', '/users/{user}', function () {
        });

        $callback = RouteBinding::forModel($container, ExplicitRouteBindingSoftDeletableUser::class);

        $this->expectException(ModelNotFoundException::class);
        $callback(1, $route);
    }

    public function testItCanResolveTheExplicitSoftDeletedModelForTheGivenRouteWithTrashed()
    {
        $container = Container::getInstance();

        $route = (new Route('GET', '/users/{user}', function () {
        }))->withTrashed();

        $callback = RouteBinding::forModel($container, ExplicitRouteBindingSoftDeletableUser::class);
        $this->assertInstanceOf(ExplicitRouteBindingSoftDeletableUser::class, $callback(1, $route));
    }
}

class ExplicitRouteBindingUser extends Model
{
    public function resolveRouteBinding($value, $field = null)
    {
        return new self();
    }
}

class ExplicitRouteBindingSoftDeletableUser extends Model
{
    use SoftDeletes;

    public function resolveRouteBinding($value, $field = null)
    {
        return null;
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        return new self();
    }
}
