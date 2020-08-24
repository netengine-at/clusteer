<?php

namespace RenokiCo\Clusteer;

use Illuminate\Support\Str;

use Storage;

use Illuminate\Support\Facades\Log;

class Clusteer
{
    const DESKTOP_DEVICE = 'desktop';

    const TABLET_DEVICE = 'tablet';

    const MOBILE_DEVICE = 'mobile';
    
    protected $additionalOptions = [];

    /**
     * Get the parameters sent to Clusteer.
     *
     * @var array
     */
    protected $query = [];

    /**
     * Get the URL to crawl.
     *
     * @var string
     */
    protected $url;
    
    /**
     * Get the URL to crawl.
     *
     * @var string
     */
    protected $tmpDir = '';

    /**
     * Initialize a Clusteer instance with an URL.
     *
     * @param  string  $url
     * @return $this
     */
    public static function to(string $url)
    {
        return (new static)->setUrl($url);
    }

    /**
     * Set the URL address.
     *
     * @param  string  $url
     * @return $this
     */
    public function setUrl(string $url)
    {
        $this->url = $url;

        return $this;
    }  
    
    /**
     * Set the URL address.
     *
     * @param  string  $url
     * @return $this
     */
    public static function loadHtml(string $html)
    {
        $tmp = new static();

        if(empty($tmp->tmpDir)) {
          $tmp->createTemporaryDirectory();
        }       
        Storage::disk('public')->put('clusteer/'.$tmp->tmpDir.'/index.html', $html);
        
        $url = url('storage/clusteer/'.$tmp->tmpDir.'/index.html'); 
        
        $tmp->setUrl($url);
   
        return $tmp;
    }

    /**
     * Set the viewport.
     *
     * @param  int  $width
     * @param  int  $height
     * @return $this
     */
    public function setViewport(int $width, int $height, int $device_scale_factor = 1)
    {   $this->setParameter('viewport', "{$width}x{$height}");
        $this->setParameter('device_scale_factor', $device_scale_factor);
    
        return $this;
    }

    /**
     * Set the device.
     *
     * @param  string  $device
     * @return $this
     */
    public function setDevice(string $device)
    {
        return $this->setParameter('device', $device);
    }

    /**
     * Set the user agent. Overwrites the `setDevice` method.
     *
     * @param  string  $userAgent
     * @return $this
     */
    public function setUserAgent(string $userAgent)
    {
        return $this->setParameter('user_agent', $userAgent);
    }

    /**
     * Set the extra headers. They get serialized as JSON.
     *
     * @param  array  $headers
     * @return $this
     */
    public function setExtraHeaders(array $headers)
    {
        return $this->setParameter('extra_headers', json_encode($headers));
    }
       
    /**
     * Provide credentials for HTTP authentication.
     *
     * @param  string  $username
     * @param  string  $password
     * @return $this
     */
    public function authenticate(string $username, string $password)
    {
        $this->setParameter('authentication', compact('username', 'password'));

        return $this;
    }
    
    /**
     * useCookies.
     *
     * @param  array  $headers
     * @return $this
     */
    public function useCookies(array $cookies, string $domain = null)
    {
        if (! count($cookies)) {
            return $this;
        }

        if (is_null($domain)) {
            $domain = parse_url($this->url)['host'];
        }

        $cookies = array_map(function ($value, $name) use ($domain) {
            return compact('name', 'value', 'domain');
        }, $cookies, array_keys($cookies));

        if (isset($this->additionalOptions['cookies'])) {
            $cookies = array_merge($this->additionalOptions['cookies'], $cookies);
        }

        $this->setParameter('cookies', $cookies);
    }
    
    
    /**
     * Set addStyleTag.
     *
     * @return $this
     */
    public function addStyleTag(string $url = '', string $path = '', string $content = '')
    {
        $this->setParameter('add_style_tag', 1);
        $this->setParameter('add_style_tag_url', $url);
        $this->setParameter('add_style_tag_path', $path);
        $this->setParameter('add_style_tag_content', $content);
        
        return $this;
    }
    
    /**
     * Set addStyleTag.
     *
     * @return $this
     */
    public function addScriptTag(string $url = '', string $path = '', string $content = '')
    {
        $this->setParameter('add_script_tag', 1);
        $this->setParameter('add_script_tag_url', $url);
        $this->setParameter('add_script_tag_path', $path);
        $this->setParameter('add_script_tag_content', $content);
        
        return $this;
    }
    
    
    
    /**
     * Set timeout.
     *
     * @param  int  $timeout (default 60 seconds)
     * @return $this
     */
    public function waitFor($wait_for = 0)
    {
        $this->setParameter('wait_for', $wait_for);
        
        return $this;
    }
    
    
    /**
     * Set timeout.
     *
     * @param  int  $timeout (default 60 seconds)
     * @return $this
     */
    public function timeout(int $timeout = 60)
    {
        $this->setParameter('timeout', $timeout * 1000);
        
        return $this;
    }
    
     
    /**
     * Set the extra headers. They get serialized as JSON.
     *
     * @param  array  $headers
     * @return $this
     */
    public function clickIT(string $selector, $options = array())
    {      
        //button Defaults to left <"left"|"right"|"middle">
        if(!array_key_exists('button', $options)) $options['button'] = "left";
        
        //clickCount <number> defaults to 1
        if(!array_key_exists('clickCount', $options)) $options['clickCount'] = 1;
        
        //delay <number> Time to wait between mousedown and mouseup in milliseconds. Defaults to 0
        if(!array_key_exists('delay ', $options)) $options['delay'] = 0;
        
        $options['delay'] = $options['delay'] * 1000; //milliseconds
    
        $this->setParameter('click_selector', $selector);
        $this->setParameter('click_options', $options);
        $this->setParameter('click', 1); 
        
        return $this;
    }
    
    /**
     * Set the extra headers. They get serialized as JSON.
     *
     * @param  array  $headers
     * @return $this
     */
    public function typeIT(string $selector, string $text = '', int $delay = 0)
    {            
      //delay <number> Time to wait between key presses in milliseconds. Defaults to 0.
      $delay = $delay * 1000;
      
      $this->setParameter('type_selector', $selector);
      $this->setParameter('type_text', $text);
      $this->setParameter('type_delay', $delay);
      $this->setParameter('type', 1); 
        
      return $this;
    }
    
    public function selectOption(string $selector, string $value = '')
    {
        $dropdownSelects = $this->additionalOptions['selects'] ?? [];

        $dropdownSelects[] = compact('selector', 'value');

        return $this->setParameter('selects', $dropdownSelects);
    }
  

    /**
     * Set the extensions to block.
     *
     * @param  array  $extensions
     * @return $this
     */
    public function blockExtensions($extensions = array())
    {
        return $this->setParameter('blocked_extensions', implode(',', $extensions));
    }
    
    
    /**
     * Set disable javascript.
     *
     * @return $this
     */
    public function disableJavascript()
    {
        return $this->setParameter('disable_javascript', 1);
    }
    
    /**
     * Set dismiss dialogs.
     *
     * @return $this
     */
    public function dismissDialogs()
    {
        return $this->setParameter('dismiss_dialogs', 1);
    }
    
    
    /**
     * Set disable images.
     *
     * @return $this
     */
    public function disableImages()
    {
        return $this->setParameter('disable_images', 1);
    }

    /**
     * Set the timeout.
     *
     * @param  int  $seconds
     * @return $this
     */
    public function navigationTimeout(int $seconds)
    {
        return $this->setParameter('navigation_timeout', $seconds);
    }

    /**
     * Wait until all the requests get triggered.
     *  
     * load              - consider navigation to be finished when the load event is fired.
     * domcontentloaded  - consider navigation to be finished when the DOMContentLoaded event is fired.
     * networkidle0      - consider navigation to be finished when there are no more than 0 network connections for at least 500 ms.
     * networkidle2      - consider navigation to be finished when there are no more than 2 network connections for at least 500 ms. 
     *
     * @param  string  $option 
     * @return $this
     */
    public function waitUntilAllRequestsFinish($option = 'networkidle0')
    {   
        return $this->setParameter('until_idle', $option);
    }

    /**
     * Output the triggered requests.
     *
     * @return $this
     */
    public function withTriggeredRequests()
    {
        return $this->setParameter('triggered_requests', 1);
    }

    /**
     * Output the cookies.
     *
     * @return $this
     */
    public function withCookies()
    {
        return $this->setParameter('cookies', 1);
    }

    /**
     * Output the HTML.
     *
     * @return $this
     */
    public function withHtml()
    {
        return $this->setParameter('html', 1);
    }

    /**
     * Output the console lines.
     *
     * @return $this
     */
    public function withConsoleLines()
    {
        return $this->setParameter('console_lines', 1);
    }
    
    public function waitForFunction(string $function, $polling = self::POLLING_REQUEST_ANIMATION_FRAME, int $timeout = 0)
    {
        $this->setParameter('functionPolling', $polling);
        $this->setParameter('functionTimeout', $timeout);
        $this->setParameter('function', $function);

        return $this;
    }
    
    public function pages(string $pages)
    {
      return $this->setOption('pageRanges', $pages);
    }
    
    public function clip(int $x, int $y, int $width, int $height)
    {
      return $this->setParameter('clip', compact('x', 'y', 'width', 'height'));
    }

    public function select($selector)
    {
       return $this->setParameter('selector', $selector);
    }

    /**
     * Output the screenshot.
     *
     * @param  int  $quality
     * @return $this
     */
    public function withScreenshot($options = array())
    { 
      foreach($options AS $key => $option) {
        if(is_bool($option) && $option === true) $options[$key] = "true";
        else if(is_bool($option) && $option === false) $options[$key] = "false";
      }
      
      $options['encoding'] = 'base64'; //standard
      
      $this->setParameter('screenshot_options', $options);
      $this->setParameter('screenshot', 1);
      
      return $this;
    }
    
    /**
     * Output the pdf.
     *
     * @param  int  $quality
     * @return $this
     */
    public function withPdf($options = array()) 
    { 
      foreach($options AS $key => $option) {
        if(is_bool($option) && $option === true) $options[$key] = "true";
        else if(is_bool($option) && $option === false) $options[$key] = "false";
      }
    
      $this->setParameter('pdf_options', $options);
      $this->setParameter('pdf', 1);
        
      return $this;
    }

    /**
     * Trigger the crawling.
     *
     * @return ClusteerResponse
     */
    public function get(): ClusteerResponse
    {      
        $response = json_decode(
            file_get_contents($this->getCallableUrl()), true
        )['data'];
               
        if(!empty($this->tmpDir)) {
          $del = \File::deleteDirectory(public_path().'/storage/clusteer/'.$this->tmpDir);
        }
        
        if(!is_null($response['error'])) {
          Log::debug($response['error']);
          dump($response['error']);
        }
        
        return new ClusteerResponse($response);
    }

    /**
     * Set a parameter for Clusteer.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function setParameter(string $key, $value)
    {
        $this->query[$key] = $value;

        return $this;
    }

    /**
     * Get the callable URL.
     *
     * @return string
     */
    protected function getCallableUrl(): string
    {       
        // Ensure url is at the end of the query string.
        $this->setParameter('url', $this->url);

        $endpoint = config('clusteer.endpoint');
        $query = http_build_query($this->query);

        $config_file = $this->createTemporaryOptionsFile($this->query);

        return "{$endpoint}?url={$this->url}&options={$config_file}";
    }
    
    protected function createTemporaryDirectory() {
      Storage::disk('public')->makeDirectory('clusteer', 'public');
        
      do {
        $tmpDir = Str::random(40);
        $tmpdirCheck = public_path().'/storage/clusteer/'.$tmpDir;
      } while(file_exists($tmpdirCheck));
      
      Storage::disk('public')->makeDirectory('/clusteer/'.$tmpDir, 'public');
      $this->tmpDir = $tmpDir;
      //chmod($tmpdirCheck.'/command.js', 0777);
      
      return true;
    }
    
    protected function createTemporaryOptionsFile($options)
    {   
        if(empty($this->tmpDir)) {
          $this->createTemporaryDirectory();
        }
        
        Storage::disk('public')->put('/clusteer/'.$this->tmpDir.'/command.js', json_encode($options));
        
        
        return public_path().'/storage/clusteer/'.$this->tmpDir.'/command.js';
    }

}
