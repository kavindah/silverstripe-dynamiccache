<?php

namespace TractorCow\DynamicCache;

use Exception;


use SilverStripe\Dev\Deprecation;
use SilverStripe\Control\Director;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Core\Config\Config;
use SilverStripe\Security\BasicAuth;
use SilverStripe\ORM\DB;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Security\Member;
use SilverStripe\Assets\File;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\ViewableData;
use SilverStripe\Core\Flushable;

use Psr\SimpleCache\CacheInterface;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;

use SilverStripe\Control\HTTPApplication;
use SilverStripe\Control\HTTPRequestBuilder;
use SilverStripe\Core\CoreKernel;
use SilverStripe\Core\Startup\ErrorControlChainMiddleware;

/**
 * Handles on the fly caching of pages
 *
 * @author Damian Mooyman
 * @package dynamiccache
 */


class DynamicCache implements Flushable
{
    use Extensible;
    use Injectable;
    use Configurable;

    public static function flush()
    {
        self::inst()->clear();
    }

    public static function bypass_cache()
    {
        // Build request and detect flush
        $request = HTTPRequestBuilder::createFromEnvironment();

        // Default application
        $kernel = new CoreKernel(BASE_PATH);
        $app = new HTTPApplication($kernel);
        $app->addMiddleware(new ErrorControlChainMiddleware($app));
        $response = $app->handle($request);
        $response->output();
    }


    /**
     * Instance of DynamicCache
     *
     * @var DynamicCache
     */
    protected static $instance;

    /**
     * Return the current cache instance
     *
     * @return DynamicCache
     */
    public static function inst()
    {
        if (!self::$instance) {
            self::$instance = DynamicCache::create();
        }
        return self::$instance;
    }

    /**
     * Determine if the cache should be enabled for the current request
     *
     * @param string $url
     * @return boolean
     */
    protected function enabled($url)
    {

        // Master override
        if (!self::config()->enabled) {
            return false;
        }

        // No GET params other than cache relevant config is passed (e.g. "?stage=Stage"),
        // which would mean that we have to bypass the cache
        if (count(array_diff(array_keys($_GET), array('url')))) {
            return false;
        }

        // Request is not POST (which would have to be handled dynamically)
        if ($_POST) {
            return false;
        }

        // Check url doesn't hit opt out filter
        $optOutURL = self::config()->optOutURL;
        if (!empty($optOutURL) && preg_match($optOutURL, $url)) {
            return false;
        }

        // Check url hits the opt in filter
        $optInURL = self::config()->optInURL;
        if (!empty($optInURL) && !preg_match($optInURL, $url)) {
            return false;
        }

        // Check ajax filter
        if (!self::config()->enableAjax && Director::is_ajax()) {
            return false;
        }

        // Disable caching on staging site
        $isStage = ($stage = Versioned::get_stage()) && ($stage !== 'Live');
        if ($isStage) {
            return false;
        }

        // If user failed BasicAuth, disable cache and fallback to PHP code
        $basicAuthConfig = Config::inst()->forClass(BasicAuth::class);
        if ($basicAuthConfig->entire_site_protected) {
            return false;
            // // NOTE(Jake): Required so BasicAuth::requireLogin() doesn't early exit with a 'true' value
            // //             This will affect caching performance with BasicAuth turned on.
            // if (!DB::is_active()) {
            //     global $databaseConfig;
            //     if ($databaseConfig) {
            //         DB::connect($databaseConfig);
            //     }
            // }
            //
            // // If no DB configured / failed to connect
            // if (!DB::is_active()) {
            //     return false;
            // }
            //
            // // NOTE(Jake): Required so MemberAuthenticator::record_login_attempt() can call
            // //             Controller::curr()->getRequest()->getIP()
            // $stubController = new Controller;
            // $stubController->pushCurrent();
            //
            // $member = null;
            // try {
            //     $member = BasicAuth::requireLogin($basicAuthConfig->entire_site_protected_message, $basicAuthConfig->entire_site_protected_code, false);
            // } catch (HTTPResponse_Exception $e) {
            //     // This codepath means Member auth failed
            // } catch (Exception $e) {
            //     // This means an issue occurred elsewhere
            //     throw $e;
            // }
            // $stubController->popCurrent();
            // // Do not cache because:
            // // - $member === true when: "Security::database_is_ready()" is false (No Member tables configured) or unit testing
            // // - $member is not a Member object, means the authentication failed.
            // if ($member === true || !$member instanceof Member) {
            //     return false;
            // }
        }

        // If displaying form errors then don't display cached result

        foreach (Controller::curr()->getRequest()->getSession()->getAll() as $field => $data) {
            // Check for session details in the form FormInfo.{$FormName}.errors/FormInfo.{$FormName}.formError
            if ($field === 'FormInfo') {
                foreach ($data as $formData) {
                    if (isset($formData['errors']) || isset($formData['formError'])) {
                        return false;
                    }
                }
            }
        }

        // OK!
        return true;
    }

    /**
     * Determine if the specified headers permit this page to be cached
     *
     * @param array $headers
     * @return boolean
     */
    protected function headersAllowCaching(array $headers)
    {

        // Check if any opt out headers are matched
        $optOutHeader = self::config()->optOutHeader;
        if (!empty($optOutHeader)) {
            foreach ($headers as $header) {
                if (preg_match($optOutHeader, $header)) {
                    return false;
                }
            }
        }

        // Check if any opt in headers are matched
        $optInHeaders = self::config()->optInHeader;
        if (!empty($optInHeaders)) {
            foreach ($headers as $header) {
                if (preg_match($optInHeaders, $header)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    /**
     * Returns control of page rendering to SilverStripe
     */
    protected function yieldControl()
    {
        self::bypass_cache();
    }

    /**
     * Returns the caching factory
     *
     */
    protected function getCache()
    {
        return Injector::inst()->get(CacheInterface::class . '.dynamiccachecache');
    }

    /**
     * Determines identifier by which this page should be identified, given a specific
     * url
     *
     * @param string $url The request URL
     * @return string The cache key
     */
    protected function getCacheKey($url)
    {
        $fragments = array();

        // Segment by protocol (always)
        $fragments['protocol'] = Director::protocol();

        // Stage
        $fragments['stage'] = Versioned::get_stage();

        // Segment by hostname if necessary
        if (self::config()->segmentHostname) {
            $fragments['HTTP_HOST'] = $_SERVER['HTTP_HOST'];
        }

        // Clean up url to match SS_HTTPRequest::setUrl() interpretation
        $fragments['url'] = preg_replace('|/+|', '/', $url);

        // Extend
        $this->extend('updateCacheKeyFragments', $fragments, $url);

        return "DynamicCache_" . md5(implode('|', array_map('md5', $fragments)));
    }

    /**
     * Sends the cached value to the browser, including any necessary headers
     *
     * @param string $cachedValue Serialised cached value
     * @param boolean Flag indicating whether the cache was successful
     */
    protected function presentCachedResult($cachedValue)
    {

        // Check for empty cache
        if (empty($cachedValue)) {
            return false;
        }
        $deserialisedValue = unserialize($cachedValue);

        // Set response code
        http_response_code($deserialisedValue['response_code']);

        // Present cached headers
        foreach ($deserialisedValue['headers'] as $header) {
            header($header);
        }

        // Send success header
        $responseHeader = self::config()->responseHeader;
        if ($responseHeader) {
            header("$responseHeader: hit at " . @date('r'));
        }

        // Substitute security id in forms
        $securityID = SecurityToken::getSecurityID();
        $outputBody = preg_replace(
            '/\<input type="hidden" name="SecurityID" value="\w+"/',
            "<input type=\"hidden\" name=\"SecurityID\" value=\"{$securityID}\"",
            $deserialisedValue['content']
        );

        // Present content
        echo $outputBody;
        return true;
    }

    /**
     * Save a page result into the cache
     *
     * @param Zend_Cache_Core $cache
     * @param string $result Page content
     * @param array $headers Headers to cache
     * @param string $cacheKey Key to cache this page under
     */
    protected function cacheResult($cache, $result, $headers, $cacheKey, $responseCode)
    {
        $cache->set(
            $cacheKey,
            serialize(
                [
                    'headers' => $headers,
                    'response_code' => $responseCode,
                    'content' => $result
                ]
            )
        );
    }

    /**
     * Clear the cache
     *
     * @param $cache
     */
    public function clear($cache = null)
    {
        if (empty($cache)) {
            $cache = $this->getCache();
        }
        $cache->clear();
    }

    /**
     * Determine which already sent headers should be cached
     *
     * @param array List of sent headers to filter
     * @return array List of cacheable headers
     */
    protected function getCacheableHeaders($headers)
    {
        // Caching options
        $responseHeader = self::config()->responseHeader;
        $cachePattern = self::config()->cacheHeaders;

        $saveHeaders = array();
        foreach ($headers as $header) {

            // Filter out headers starting with $responseHeader
            if ($responseHeader && stripos($header, $responseHeader) === 0) {
                continue;
            }

            // Filter only headers that match the specified pattern
            if ($cachePattern && !preg_match($cachePattern, $header)) {
                continue;
            }

            // Save this header
            $saveHeaders[] = $header;
        }
        return $saveHeaders;
    }

    /**
     * Activate caching on a given url
     *
     * @param string $url
     */
    public function run($url)
    {
        //no point in going any further with a flush
        if (isset($_GET['flush'])) {
            self::bypass_cache();
            exit();
        }

        // First make sure we have session

        $request = Controller::curr()->getRequest();

        if (!isset($_SESSION) && $request->getSession()->requestContainsSessionId($request)) {
            $request->getSession()->start($request);
        }
        // Forces the session to be regenerated from $_SESSION

        $request->getSession()->clearAll();
        // This prevents a new user's security token from being regenerated incorrectly
        $_SESSION['SecurityID'] = SecurityToken::getSecurityID();

        // Create mock Controller to for Versioned::choose_site_stage()
        $controllerObj = Injector::inst()->create(Controller::class);
        $controllerObj->pushCurrent();

        // Set the stage of the website
        // This is normally called in VersionedRequestFilter.
        Versioned::choose_site_stage();

        // Remove mock Controller
        $controllerObj->popCurrent();

        // Get cache and cache details
        $responseHeader = self::config()->responseHeader;
        $cache = $this->getCache();
        $cacheKey = $this->getCacheKey($url);

        // Check if caching should be short circuted
        $enabled = $this->enabled($url);
        $this->extend('updateEnabled', $enabled);
        if (!$enabled) {
            if ($responseHeader) {
                header("$responseHeader: skipped");
            }
            $this->yieldControl();
            return;
        }

        // Check if cached value can be returned

        $cachedValue = $cache->get($cacheKey);
        if ($this->presentCachedResult($cachedValue)) {
            return;
        }

        // Run this page, caching output and capturing data
        if ($responseHeader) {
            header("$responseHeader: miss at " . @date('r'));
        }

        ob_start();
        $this->yieldControl();
        $headers = headers_list();
        $result = ob_get_flush();
        $responseCode = http_response_code();

        // Skip blank copy unless redirecting
        $locationHeaderMatches  = preg_grep('/^Location/i', $headers);
        if (empty($result) && empty($locationHeaderMatches)) {
            return;
        }

        // Skip excluded status codes
        $optInResponseCodes = self::config()->optInResponseCodes;
        $optOutResponseCodes = self::config()->optOutResponseCodes;
        if (is_array($optInResponseCodes) && !in_array($responseCode, $optInResponseCodes)) {
            return;
        }
        if (is_array($optOutResponseCodes) && in_array($responseCode, $optOutResponseCodes)) {
            return;
        }

        // Check if any headers match the specified rules forbidding caching
        if (!$this->headersAllowCaching($headers)) {
            return;
        }

        // Include any "X-Header" sent with this request. This is necessary to
        // ensure that additional CSS, JS, and other files are retained
        $saveHeaders = $this->getCacheableHeaders($headers);

        // Save data along with sent headers
        $this->cacheResult($cache, $result, $saveHeaders, $cacheKey, $responseCode);
    }
}
