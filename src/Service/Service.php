<?php

  namespace Skeletal\Service;
  
  use \Skeletal\HTTP\Method as Method;
  use \Skeletal\HTTP\Request as Request;
  use \Skeletal\HTTP\Response as Response;
  use \Skeletal\Router\Path as Path;
  use \Skeletal\Session\Session as Session;
  use \Skeletal\Session\CLISession as CLISession;
  
  class Service {
  
    protected $router;
    protected $session;

    protected $onException;   // ( $req, &$rsp, $ex )
    protected $onNotFound;    // ( $req, &$rsp )
    
    public function __construct () {
      $this->router = new \Skeletal\Router\Router();
      $this->session = new Session();
      
      $this->onNotFound = function ( $svc, $req ) {
        return (new Response())->text( '404 - Not Found' )->code(404);
      };
      $this->onException = function ( $svc, $req, $ex ) {
        return (new Response())->serverError()->text( '500 - Server Error' );
      };
    }
    
    /*
      Register these two events.
    */
    
    public function onNotFound ( $callback ) {
      $callback = $this->vetHandler( $callback );
      if ( $callback === NULL )
        throw new \InvalidArgumentException( "Invalid callback for onNotFound." );
      $this->onNotFound = $callback;
    }
    
    public function onException ( $callback ) {
      $callback = $this->vetHandler( $callback );
      if ( $callback === NULL )
        throw new \InvalidArgumentException( "Invalid callback for onException." );
      $this->onException = $callback;
    }
    
    /*
      Get and set arbitrary attributes on the Service to give Routes' handlers
      access to dependencies.
    */
    
    public function __get ( $property ) {
      return isset( $this->{$property} ) ? $this->{$property} : NULL;
    }
    
    public function __set ( $property, $value ) {
      $this->{$property} = $value;
    }
    
    public function __isset ( $property ) {
      return isset( $this->{$property} );
    }
    
    public function __unset ( $property ) {
      unset( $this->{$property} );
    }
    
    /*
      HTTP verbs
    */
    
    public function get ( $path, $handler ) {
      $this->addRoute( 'GET', $path, $handler );
    }

    public function post ( $path, $handler ) {
      $this->addRoute( 'POST', $path, $handler );
    }

    public function head ( $path, $handler ) {
      $this->addRoute( 'HEAD', $path, $handler );
    }
    
    public function delete ( $path, $handler ) {
      $this->addRoute( 'DELETE', $path, $handler );
    }
    
    public function put ( $path, $handler ) {
      $this->addRoute( 'PUT', $path, $handler );
    }
    
    public function options ( $path, $handler ) {
      $this->addRoute( 'OPTIONS', $path, $handler );
    }
    
    public function connect ( $path, $handler ) {
      $this->addRoute( 'CONNECT', $path, $handler );
    }
    
    private function addRoute ( $method, $path, $handler ) {
      $handler = $this->vetHandler( $handler );
      if ( $handler === NULL )
        throw new \InvalidArgumentException( "Invalid handler for route $path." );
      $this->router->addRoute( new Path( $path ), $method, $handler );
    }
    
    /*
      Accept as a handler either a closure with argument ( $service, $request )
      or a string in the form 'ControllerClass#ControllerMethod'.
    */
    
    private function vetHandler ( $handler ) {
      if ( is_callable( $handler ) )
        return $handler;
      else if ( is_string( $handler ) && preg_match( "/^.+\#.+$/", $handler ) == 1 )
        return function ( $service, $request ) use ( $handler ) {
          list($controller, $method) = explode( '#', $handler );
          $controller = new $controller( $service );
          return $controller->{$method}( $request );
        };
      return NULL;
    }
    
    /*
      Turn a Request into a Response.
      Merge path variables (e.g. "/item/{id}") into _GET.
      Treat HEAD requests as GETs, but stripping the body.
      Call $this->onNotFound on no match.
    */
    
    public function route ( Request $request ) {
      $route = $this->router->findRoute( $request->path(), $request->method() );
      
      // Found the right path
      if ( $route !== NULL ) {
        $request->queryString = array_merge(
          $route->apply( $request->requestPath ),
          $request->queryString
        );
        return $this->invokeCallback( $request, $route->getHandler() );
      }
      
      // HEAD wasn't explicitly defined, but route to matching GET, strip body.
      if ( $request->method() === Method::$HEAD ) {
        $route = $this->router->findRoute( $request->path(), Method::$GET );
        if ( $route !== NULL ) {
          $request->requestMethod = Method::$GET;
          $response = $this->route( $request );
          $request->requestMethod = Method::$HEAD;
          $contentLength = strlen( $response->body() );
          return $response->body('')->length( $contentLength );
        }
      }
      
      // Not found
      return $this->invokeCallback( $request, $this->onNotFound );
    }
    
    public function serve () {
      Response::send( $this->route( Request::current() ) );
    }
    
    /*
      Execute the handler in $this scope.
      Intercept exceptions and give to $this->onException.
    */
    
    private function invokeCallback ( Request $request, $handler ) {
      try {
        return call_user_func_array( $handler, array( $this, $request ) );
      } catch ( \Exception $ex ) {
        $this->exception = $ex;
        return call_user_func_array( $this->onException, array( $this, $request ) );
      }
    }
  
  };

?>