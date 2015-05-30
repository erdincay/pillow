<?php
/**
 * Created by PhpStorm.
 * User: bthomas
 * Date: 5/19/15
 * Time: 9:49 PM
 */

namespace Framework\Controller;


use FastRoute\RouteCollector;
use Framework\Request\Filter\FilterChain;
use Framework\Request\Filter\FilterManagerTrait;
use Framework\Route\Route;
use Framework\View\Handler\ViewHandlerInterface;
use Framework\View\TemplateView\SimpleFileBasedTemplateView;
use Framework\View\TemplateView\SimpleJSONTemplateView;
use Framework\View\TemplateView\TemplateViewInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FrontController implements ControllerInterface
{
    use RequestResponseTrait;
    use FilterManagerTrait;

    /** @var  FrontController $instance */
    private static $instance;
    private $dispatcher;
    /** @var  Route $route */
    private $route;
    /** @var  ControllerInterface $controller */
    private $controller;
    /** @var  User $currentUser */
    private $currentUser;
    /** @var  string $rootPath */
    private static $rootPath;

    private $viewHandler;

    private static $httpMethods = [
        "GET",
        "PUT",
        "POST",
        "PATCH",
        "DELETE",
        "HEAD",
        "TRACE",
        "CONNECT",
        "OPTIONS",
    ];

    public static function getRootPath(){
        if(defined("PILLOW_ROOT_PATH")){
            self::$rootPath = PILLOW_ROOT_PATH;
        } else if(!isset(self::$rootPath)){
            $path = explode(DIRECTORY_SEPARATOR, __DIR__);
            while(array_pop($path) !== 'src');
            self::$rootPath = implode(DIRECTORY_SEPARATOR, $path);
        }
        return self::$rootPath;
    }

    private $routes;

    private function __construct(Array $routes)
    {
        $this->routes = $routes;
        $this->filterChain = new FilterChain();
    }

    private function __clone()
    {

    }

    /**
     * @return ViewHandlerInterface
     */
    public function getViewHandler()
    {
        return $this->viewHandler;
    }

    /**
     * @param ViewHandlerInterface $viewHandler
     */
    public function setViewHandler(ViewHandlerInterface $viewHandler = null)
    {
        $this->viewHandler = $viewHandler;
    }

    /**
     * @return \User
     */
    public function getCurrentUser()
    {
        return $this->currentUser;
    }

    /**
     * @param \User $currentUser
     */
    public function setCurrentUser($currentUser)
    {
        $this->currentUser = $currentUser;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function execute(Request $request){
        if (!$request) {
            $request = Request::createFromGlobals();
        }
        $this->request = $request;
        $this->response = new Response();
        try {
            $this->route();
            $this->filterRequest($this->request);
            $reflection = new \ReflectionClass($this->controller);
            $params = [];
            $vars = $this->getRequest()->query->all();
            $this->request->attributes->add(["route", $this->route]);
//            echo 'wtf';var_dump($this->request->attributes);die;

//            foreach($this->getRequest()->query->all() as $key => $param){
//                if(property_exists($param, "name") && array_key_exists($param->name, $vars)){
//                    $params[$key] = $vars[$param->name];
//                }
//            }
//            var_dump($params);die;
            foreach($reflection->getMethod($this->route->getAction())->getParameters() as $key => $param){
                if(property_exists($param, "name") && array_key_exists($param->name, $vars)){
                    $params[$key] = $vars[$param->name];
                }
            }
//            var_dump($params);die;
            $controllerReturn = $this->controller->{$this->route->getAction()}(...$params);
            if($controllerReturn instanceof Response){
                return $controllerReturn;
            } else if (!$this->getViewHandler()){
                throw new \Exception("Controller must return either a response object or you must set a view handler!");
            }
            $this->request->attributes->add(["viewData" => $controllerReturn]);
            $this->response->setStatusCode(200);
        } catch (\Exception $e){
            $this->getResponse()->setContent($e->getMessage());
//            $content = $template->renderError()
            try {
                $this->getResponse()->setStatusCode($e->getCode());
            } catch (\InvalidArgumentException $e){
                $this->getResponse()->setStatusCode(400);
            }
        } finally {
            try {
                $this->setResponse($this->getViewHandler()->transform($this->request, $this->response));
            } catch (\InvalidArgumentException $e){
                $this->getResponse()->setContent($e->getMessage());
                $this->getResponse()->setStatusCode(500);
            }
            return $this->getResponse();
        }
    }

    private function route(){
        $routes = $this->routes;
        $this->dispatcher = \FastRoute\simpleDispatcher(function (RouteCollector $r) use ($routes) {
            $addRoute = function (RouteCollector &$r, Array $route){
                foreach ($route["methods"] as $key => $value) {
                    if(in_array(strtoupper($key), static::$httpMethods)){
                        $method = strtoupper($key);
                    } else if(is_numeric($key)){
                        $method = strtoupper($value);
                    }
                    $r->addRoute($method, $route["uri"], $route);
                }
            };

            foreach ($routes as $route) {
                if(is_array($route["uri"])){
                    foreach($route["uri"] as $uri){
                        $multiRoute = $route;
                        $multiRoute["uri"] = $uri;
                        $addRoute($r, $multiRoute);
                    }
                } else {
                    $addRoute($r, $route);
                }
            }
        });

        $routeInfo = $this->dispatcher->dispatch($this->getRequest()->getMethod(), parse_url($this->getRequest()->getRequestUri(), PHP_URL_PATH));
        switch ($routeInfo[0]) {
            case \FastRoute\Dispatcher::NOT_FOUND:
                // ... 404 Not Found
                throw new \Exception("Not Found.", 404);
                break;
            case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                $allowedMethods = $routeInfo[1];
                // ... 405 Method Not Allowed
                throw new \Exception("Method not allowed.", 405);
                break;
            case \FastRoute\Dispatcher::FOUND:
                $route = $routeInfo[1];
                $vars = $routeInfo[2];
                if (!isset($route["controller"]) && !class_exists($route["controller"])) {
                    throw new \Exception("Invalid controller class!");
                }

                $this->controller = new $route["controller"]($this->request);
                if(array_key_exists("methods", $route)){ // clean up the method names in the route array so we don't have to worry about case
                    $newMethods = [];
                    foreach($route["methods"] as $key => $value){
                        if(in_array(strtoupper($key), self::$httpMethods)){
                            $newMethods[strtoupper($key)] = $value;
                        } else if(in_array(strtoupper($value), self::$httpMethods)){
                            $newMethods[] = $value;
                        }
                    }
                    $route["methods"] = $newMethods;
                }
                if (array_key_exists(strtoupper($this->request->getMethod()), $route["methods"])) {
                    if(is_array($route["methods"][strtoupper($this->request->getMethod())])){
                        if(!isset($route["methods"][strtoupper($this->request->getMethod())]["action"])){
//                            throw new \Exception("Action not specified!");
                            $route["action"] = $this->request->getMethod()."Action";
                        } else {
                            $route["action"] = $route["methods"][strtoupper($this->request->getMethod())]["action"];
                        }
                    } else {
                        $route["action"] = $route["methods"][strtoupper($this->request->getMethod())];
                    }
                } else if(in_array(strtoupper($this->request->getMethod()), $route["methods"])){
                    $route["action"] = strtolower($this->request->getMethod())."Action";
                }

                if (!method_exists($this->controller, $route["action"])) {
                    throw new \Exception("Invalid controller method: {$route["action"]}");
                }
                if(!isset($route["templateFile"])){
                    $route["templateFile"] = null;
                }
                if(!isset($route["viewClass"])){
                    $route["viewClass"] = SimpleFileBasedTemplateView::class;
                }
                $this->route = new Route($route["uri"], $this->request->getMethod(), $route["controller"], $route["action"], $route["viewClass"], $route["templateFile"], $vars);
                $this->request->attributes->add(["route" => $this->route]);
                break;
            default:
                throw new \Exception("An unknown error has occurred!", 400);
        }
    }

    /**
     * @return ControllerInterface
     */
    public function getController()
    {
        return $this->controller;
    }

    /**
     * @param ControllerInterface $controller
     */
    public function setController($controller)
    {
        $this->controller = $controller;
    }

    public static function getInstance()
    {
        if (!static::$instance) {
            $routes = include "config/routes.php";
            static::$instance = new FrontController($routes);
        }
        return static::$instance;
    }

    /**
     * @return Route
     */
    public function getRoute()
    {
        return $this->route;
    }

    /**
     * @param Route $route
     */
    public function setRoute($route)
    {
        $this->route = $route;
    }
}