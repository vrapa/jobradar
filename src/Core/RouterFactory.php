<?php

declare(strict_types=1);

namespace App\Core;

use Nette\Application\Routers\Route;
use Nette\Application\Routers\RouteList;
use Nette\StaticClass;

final class RouterFactory
{
    use StaticClass;

    public static function createRouter(): RouteList
    {
        $router = new RouteList();
        $apiDetail = [
            'presenter' => 'Api:Api',
            'action' => 'default',
            'version' => '1',
            'package' => 'search-requests',
            'apiAction' => 'detail',
            'id' => [Route::FILTER_IN => self::publishApiId(...)],
        ];
        $apiControl = [
            'presenter' => 'Api:Api',
            'action' => 'default',
            'version' => '1',
            'package' => 'search-requests',
            'id' => [Route::FILTER_IN => self::publishApiId(...)],
        ];
        $router->addRoute('api/v1/search-requests/<id \d+>/<apiAction resume|cancel>', $apiControl);
        $router->addRoute('api/v1/search-requests/<id \d+>', $apiDetail);
        $router->addRoute('api/v<version>/<package>[/<apiAction>]', 'Api:Api:default');
        $router->addRoute('prihlaseni', 'Sign:in');
        $router->addRoute('zdroje', 'Source:default');
        $router->addRoute('kontroly/<id \d+>', 'SearchRequest:detail');
        $router->addRoute('nabidky/pridat', 'OpportunityImport:default');
        $router->addRoute('nabidky/k-reakci', 'Home:reactionQueue');
        $router->addRoute('nabidky/nezajimave', 'Home:uninteresting');
        $router->addRoute('nabidky/<id \d+>/podminky', 'OpportunityTerms:default');
        $router->addRoute('nabidky/<id \d+>', 'Opportunity:detail');
        $router->addRoute('<presenter>/<action>[/<id>]', 'Home:default');

        return $router;
    }

    private static function publishApiId(string $id): string
    {
        $_GET['id'] = $id;
        return $id;
    }
}
