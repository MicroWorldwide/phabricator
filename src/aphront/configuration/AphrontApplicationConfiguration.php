<?php

/**
 * @task  routing URI Routing
 */
abstract class AphrontApplicationConfiguration extends Phobject {

  private $request;
  private $host;
  private $path;
  private $console;

  abstract public function getApplicationName();
  abstract public function buildRequest();
  abstract public function build404Controller();
  abstract public function buildRedirectController($uri, $external);

  final public function setRequest(AphrontRequest $request) {
    $this->request = $request;
    return $this;
  }

  final public function getRequest() {
    return $this->request;
  }

  final public function getConsole() {
    return $this->console;
  }

  final public function setConsole($console) {
    $this->console = $console;
    return $this;
  }

  final public function setHost($host) {
    $this->host = $host;
    return $this;
  }

  final public function getHost() {
    return $this->host;
  }

  final public function setPath($path) {
    $this->path = $path;
    return $this;
  }

  final public function getPath() {
    return $this->path;
  }

  public function willBuildRequest() {}


  /**
   * @phutil-external-symbol class PhabricatorStartup
   */
  public static function runHTTPRequest(AphrontHTTPSink $sink) {
    PhabricatorStartup::beginStartupPhase('multimeter');
    $multimeter = MultimeterControl::newInstance();
    $multimeter->setEventContext('<http-init>');
    $multimeter->setEventViewer('<none>');

    // Build a no-op write guard for the setup phase. We'll replace this with a
    // real write guard later on, but we need to survive setup and build a
    // request object first.
    $write_guard = new AphrontWriteGuard('id');

    PhabricatorStartup::beginStartupPhase('env.init');
    PhabricatorEnv::initializeWebEnvironment();

    $multimeter->setSampleRate(
      PhabricatorEnv::getEnvConfig('debug.sample-rate'));

    $debug_time_limit = PhabricatorEnv::getEnvConfig('debug.time-limit');
    if ($debug_time_limit) {
      PhabricatorStartup::setDebugTimeLimit($debug_time_limit);
    }

    // This is the earliest we can get away with this, we need env config first.
    PhabricatorStartup::beginStartupPhase('log.access');
    PhabricatorAccessLog::init();
    $access_log = PhabricatorAccessLog::getLog();
    PhabricatorStartup::setAccessLog($access_log);
    $access_log->setData(
      array(
        'R' => AphrontRequest::getHTTPHeader('Referer', '-'),
        'r' => idx($_SERVER, 'REMOTE_ADDR', '-'),
        'M' => idx($_SERVER, 'REQUEST_METHOD', '-'),
      ));

    DarkConsoleXHProfPluginAPI::hookProfiler();

    // We just activated the profiler, so we don't need to keep track of
    // startup phases anymore: it can take over from here.
    PhabricatorStartup::beginStartupPhase('startup.done');

    DarkConsoleErrorLogPluginAPI::registerErrorHandler();

    $response = PhabricatorSetupCheck::willProcessRequest();
    if ($response) {
      PhabricatorStartup::endOutputCapture();
      $sink->writeResponse($response);
      return;
    }

    $host = AphrontRequest::getHTTPHeader('Host');
    $path = $_REQUEST['__path__'];

    switch ($host) {
      default:
        $config_key = 'aphront.default-application-configuration-class';
        $application = PhabricatorEnv::newObjectFromConfig($config_key);
        break;
    }

    $application->setHost($host);
    $application->setPath($path);
    $application->willBuildRequest();
    $request = $application->buildRequest();

    // Now that we have a request, convert the write guard into one which
    // actually checks CSRF tokens.
    $write_guard->dispose();
    $write_guard = new AphrontWriteGuard(array($request, 'validateCSRF'));

    // Build the server URI implied by the request headers. If an administrator
    // has not configured "phabricator.base-uri" yet, we'll use this to generate
    // links.

    $request_protocol = ($request->isHTTPS() ? 'https' : 'http');
    $request_base_uri = "{$request_protocol}://{$host}/";
    PhabricatorEnv::setRequestBaseURI($request_base_uri);

    $access_log->setData(
      array(
        'U' => (string)$request->getRequestURI()->getPath(),
      ));

    $processing_exception = null;
    try {
      $response = $application->processRequest(
        $request,
        $access_log,
        $sink,
        $multimeter);
      $response_code = $response->getHTTPResponseCode();
    } catch (Exception $ex) {
      $processing_exception = $ex;
      $response_code = 500;
    }

    $write_guard->dispose();

    $access_log->setData(
      array(
        'c' => $response_code,
        'T' => PhabricatorStartup::getMicrosecondsSinceStart(),
      ));

    $multimeter->newEvent(
      MultimeterEvent::TYPE_REQUEST_TIME,
      $multimeter->getEventContext(),
      PhabricatorStartup::getMicrosecondsSinceStart());

    $access_log->write();

    $multimeter->saveEvents();

    DarkConsoleXHProfPluginAPI::saveProfilerSample($access_log);

    // Add points to the rate limits for this request.
    if (isset($_SERVER['REMOTE_ADDR'])) {
      $user_ip = $_SERVER['REMOTE_ADDR'];

      // The base score for a request allows users to make 30 requests per
      // minute.
      $score = (1000 / 30);

      // If the user was logged in, let them make more requests.
      if ($request->getUser() && $request->getUser()->getPHID()) {
        $score = $score / 5;
      }

      PhabricatorStartup::addRateLimitScore($user_ip, $score);
    }

    if ($processing_exception) {
      throw $processing_exception;
    }
  }


  public function processRequest(
    AphrontRequest $request,
    PhutilDeferredLog $access_log,
    AphrontHTTPSink $sink,
    MultimeterControl $multimeter) {

    $this->setRequest($request);

    list($controller, $uri_data) = $this->buildController();

    $controller_class = get_class($controller);
    $access_log->setData(
      array(
        'C' => $controller_class,
      ));
    $multimeter->setEventContext('web.'.$controller_class);

    $request->setController($controller);
    $request->setURIMap($uri_data);

    $controller->setRequest($request);

    // If execution throws an exception and then trying to render that
    // exception throws another exception, we want to show the original
    // exception, as it is likely the root cause of the rendering exception.
    $original_exception = null;
    try {
      $response = $controller->willBeginExecution();

      if ($request->getUser() && $request->getUser()->getPHID()) {
        $access_log->setData(
          array(
            'u' => $request->getUser()->getUserName(),
            'P' => $request->getUser()->getPHID(),
          ));
        $multimeter->setEventViewer('user.'.$request->getUser()->getPHID());
      }

      if (!$response) {
        $controller->willProcessRequest($uri_data);
        $response = $controller->handleRequest($request);
      }
    } catch (Exception $ex) {
      $original_exception = $ex;
      $response = $this->handleException($ex);
    }

    try {
      $response = $controller->didProcessRequest($response);
      $response = $this->willSendResponse($response, $controller);
      $response->setRequest($request);

      $unexpected_output = PhabricatorStartup::endOutputCapture();
      if ($unexpected_output) {
        $unexpected_output = pht(
          "Unexpected output:\n\n%s",
          $unexpected_output);

        phlog($unexpected_output);

        if ($response instanceof AphrontWebpageResponse) {
          echo phutil_tag(
            'div',
            array(
              'style' =>
                'background: #eeddff;'.
                'white-space: pre-wrap;'.
                'z-index: 200000;'.
                'position: relative;'.
                'padding: 8px;'.
                'font-family: monospace',
            ),
            $unexpected_output);
        }
      }

      $sink->writeResponse($response);
    } catch (Exception $ex) {
      if ($original_exception) {
        throw $original_exception;
      }
      throw $ex;
    }

    return $response;
  }


/* -(  URI Routing  )-------------------------------------------------------- */


  /**
   * Build a controller to respond to the request.
   *
   * @return pair<AphrontController,dict> Controller and dictionary of request
   *                                      parameters.
   * @task routing
   */
  final private function buildController() {
    $request = $this->getRequest();

    // If we're configured to operate in cluster mode, reject requests which
    // were not received on a cluster interface.
    //
    // For example, a host may have an internal address like "170.0.0.1", and
    // also have a public address like "51.23.95.16". Assuming the cluster
    // is configured on a range like "170.0.0.0/16", we want to reject the
    // requests received on the public interface.
    //
    // Ideally, nodes in a cluster should only be listening on internal
    // interfaces, but they may be configured in such a way that they also
    // listen on external interfaces, since this is easy to forget about or
    // get wrong. As a broad security measure, reject requests received on any
    // interfaces which aren't on the whitelist.

    $cluster_addresses = PhabricatorEnv::getEnvConfig('cluster.addresses');
    if ($cluster_addresses) {
      $server_addr = idx($_SERVER, 'SERVER_ADDR');
      if (!$server_addr) {
        if (php_sapi_name() == 'cli') {
          // This is a command line script (probably something like a unit
          // test) so it's fine that we don't have SERVER_ADDR defined.
        } else {
          throw new AphrontUsageException(
            pht('No %s', 'SERVER_ADDR'),
            pht(
              'Phabricator is configured to operate in cluster mode, but '.
              '%s is not defined in the request context. Your webserver '.
              'configuration needs to forward %s to PHP so Phabricator can '.
              'reject requests received on external interfaces.',
              'SERVER_ADDR',
              'SERVER_ADDR'));
        }
      } else {
        if (!PhabricatorEnv::isClusterAddress($server_addr)) {
          throw new AphrontUsageException(
            pht('External Interface'),
            pht(
              'Phabricator is configured in cluster mode and the address '.
              'this request was received on ("%s") is not whitelisted as '.
              'a cluster address.',
              $server_addr));
        }
      }
    }

    $site = $this->buildSiteForRequest($request);

    if ($site->shouldRequireHTTPS()) {
      if (!$request->isHTTPS()) {
        $https_uri = $request->getRequestURI();
        $https_uri->setDomain($request->getHost());
        $https_uri->setProtocol('https');

        // In this scenario, we'll be redirecting to HTTPS using an absolute
        // URI, so we need to permit an external redirect.
        return $this->buildRedirectController($https_uri, true);
      }
    }

    $maps = $site->getRoutingMaps();
    $path = $request->getPath();

    $result = $this->routePath($maps, $path);
    if ($result) {
      return $result;
    }

    // If we failed to match anything but don't have a trailing slash, try
    // to add a trailing slash and issue a redirect if that resolves.

    // NOTE: We only do this for GET, since redirects switch to GET and drop
    // data like POST parameters.
    if (!preg_match('@/$@', $path) && $request->isHTTPGet()) {
      $result = $this->routePath($maps, $path.'/');
      if ($result) {
        $slash_uri = $request->getRequestURI()->setPath($path.'/');
        $external = strlen($request->getRequestURI()->getDomain());
        return $this->buildRedirectController($slash_uri, $external);
      }
    }

    return $this->build404Controller();
  }

  /**
   * Map a specific path to the corresponding controller. For a description
   * of routing, see @{method:buildController}.
   *
   * @param list<AphrontRoutingMap> List of routing maps.
   * @param string Path to route.
   * @return pair<AphrontController,dict> Controller and dictionary of request
   *                                      parameters.
   * @task routing
   */
  private function routePath(array $maps, $path) {
    foreach ($maps as $map) {
      $result = $map->routePath($path);
      if ($result) {
        return array($result->getController(), $result->getURIData());
      }
    }
  }

  private function buildSiteForRequest(AphrontRequest $request) {
    $sites = PhabricatorSite::getAllSites();

    $site = null;
    foreach ($sites as $candidate) {
      $site = $candidate->newSiteForRequest($request);
      if ($site) {
        break;
      }
    }

    if (!$site) {
      $path = $request->getPath();
      $host = $request->getHost();
      throw new Exception(
        pht(
          'This request asked for "%s" on host "%s", but no site is '.
          'configured which can serve this request.',
          $path,
          $host));
    }

    $request->setSite($site);

    return $site;
  }

}
