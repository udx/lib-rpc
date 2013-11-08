<?php

/**
 * UD XML-RPC Library
 */
namespace UsabilityDynamics {

  /**
   * Check if WordPress environment is loaded since lib is WordPress dependent.
   */
  if ( defined('ABSPATH') ) {

    /**
     * Standard IXR
     */
    include_once( ABSPATH . WPINC . '/class-IXR.php' );

    /**
     * Prevent class redeclaration since lib will be included into each product.
     */
    if ( !class_exists('UsabilityDynamics\UD_IXR_Client') ) {
      /**
       * UD IXR extended from standard
       * @author korotkov@ud
       */
      class UD_IXR_Client extends \IXR_Client {

        /**
         * Construct
         * @param type $server
         * @param type $path
         * @param type $port
         * @param type $timeout
         * @author korotkov@ud
         */
        function __construct($server, $secret_key, $public_key, $useragent = 'UD XML-RPC Client', $headers = array(), $path = false, $port = 80, $timeout = 15, $debug = false) {
          if ( empty( $secret_key ) || empty( $public_key ) ) return false;

          parent::__construct( $server, $path, $port, $timeout );

          /**
           * Basic Authorization Header
           */
          $headers['Authorization'] = 'Basic '.base64_encode($public_key);

          /**
           * Set Callback URL Header
           */
          $headers['Callback-URL'] = get_bloginfo( 'pingback_url' );

          /**
           * Encryption key
           */
          $this->ecryption_key = $secret_key;

          /**
           * Custom useragent
           */
          $this->useragent = $useragent;

          /**
           * Custom headers
           */
          $this->headers = $headers;

          /**
           * Enable debug
           */
          $this->debug = $debug;

        }

        /**
         * Send a query to server
         * @return boolean
         */
        private function _query() {

          /**
           * All request args
           */
          $args = func_get_args();

          /**
           * Get method stored on first place
           */
          $method = array_shift($args);

          /**
           * Hash request data
           */
          $secure_args = array(
            array(
              $this->_hash_args($args[0])
            )
          );

          /**
           * Build request
           */
          $request = new \IXR_Request($method, $secure_args);
          $length = $request->getLength();
          $xml = $request->getXml();
          $r = "\r\n";
          $request = "POST {$this->path} HTTP/1.0$r";
          $this->headers['Host'] = $this->server;
          $this->headers['Content-Type'] = 'text/xml';
          $this->headers['User-Agent'] = $this->useragent;
          $this->headers['Content-Length'] = $length;
          foreach ($this->headers as $header => $value) {
            $request .= "{$header}: {$value}{$r}";
          }
          $request .= $r;
          $request .= $xml;

          /**
           * Debug step
           */
          if ($this->debug) {
            echo '<pre class="ixr_request">' . htmlspecialchars($request) . "\n</pre>\n\n";
          }

          /**
           * Send
           */
          if ($this->timeout) {
            $fp = @fsockopen($this->server, $this->port, $errno, $errstr, $this->timeout);
          } else {
            $fp = @fsockopen($this->server, $this->port, $errno, $errstr);
          }
          if (!$fp) {
            $this->error = new \IXR_Error(-32300, 'transport error - could not open socket');
            return false;
          }
          fputs($fp, $request);
          $contents = '';
          $debugContents = '';
          $gotFirstLine = false;
          $gettingHeaders = true;
          while (!feof($fp)) {
            $line = fgets($fp, 4096);
            if (!$gotFirstLine) {
              if (strstr($line, '200') === false) {
                $this->error = new \IXR_Error(-32300, 'transport error - HTTP status code was not 200');
                return false;
              }
              $gotFirstLine = true;
            }
            if (trim($line) == '') {
              $gettingHeaders = false;
            }
            if (!$gettingHeaders) {
              $contents .= $line;
            }
            if ($this->debug) {
              $debugContents .= $line;
            }
          }
          if ($this->debug) {
            echo '<pre class="ixr_response">' . htmlspecialchars($debugContents) . "\n</pre>\n\n";
          }

          /**
           * Now parse what we've got back
           */
          $this->message = new \IXR_Message($contents);
          if ( !$this->message->parse() ) {
            /**
             * XML is bad
             */
            $this->error = new \IXR_Error(-32700, 'parse error. not well formed');
            return false;
          }

          /**
           * Is the message a fault?
           */
          if ($this->message->messageType == 'fault') {
            $this->error = new \IXR_Error($this->message->faultCode, $this->message->faultString);
            return false;
          }

          return true;
        }

        /**
         * Secure arguments
         * @param type $args
         * @return type
         */
        private function _hash_args( $args ) {
          return trim(
            base64_encode(
              mcrypt_encrypt(
                MCRYPT_RIJNDAEL_256,
                md5( $this->ecryption_key ),
                json_encode( $args ),
                MCRYPT_MODE_ECB,
                mcrypt_create_iv(
                  mcrypt_get_iv_size(
                    MCRYPT_RIJNDAEL_256,
                    MCRYPT_MODE_ECB
                  ),
                  MCRYPT_RAND
                )
              )
            )
          );
        }
      }
    }

    /**
     * Prevent class redeclaration
     */
    if( !class_exists('UsabilityDynamics\XMLRPC') ) {
      /**
       * Base XML-RPC handler
       * @author korotkov@ud
       */
      abstract class XMLRPC {

        /**
         * Available calls
         * @var type
         */
        private $calls = Array();

        /**
         * Current methods' namespace
         * @var type
         */
        protected $namespace;

        /**
         *
         * @var type
         */
        protected $root_namespace = 'wp';

        /**
         * Secret key
         * @var type
         */
        protected $secret_key;

        /**
         * Public Key
         * @var type
         */
        protected $public_key;

        /**
         *
         * @var type
         */
        public $ui;

        /**
         * Construct
         * @param type $namespace
         */
        function __construct( $secret_key, $public_key, $namespace = 'ud' ) {
          $this->ui         = new UI( $namespace );

          if ( empty( $secret_key ) || empty( $public_key ) ) return false;

          $this->namespace  = $namespace;
          $this->secret_key = $secret_key;
          $this->public_key = $public_key;

          $reflector = new \ReflectionClass($this);

          foreach ($reflector->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isUserDefined() && $method->getDeclaringClass()->name != get_class()) {
              $this->calls[] = $method;
            }
          }

          add_filter('xmlrpc_methods', array($this, 'xmlrpc_methods'));
        }

        /**
         * Register methods
         * @param type $methods
         * @return array
         */
        public function xmlrpc_methods($methods) {
          foreach ($this->calls as $call) {
            $namespace = $call->getDeclaringClass()->name != 'UsabilityDynamics\UD_XMLRPC' ? $this->namespace.'.' : '';
            $methods[$this->root_namespace.'.'.$namespace . $call->name] = array($this, "dispatch");
          }
          return $methods;
        }

        /**
         * Call methods (__call similar)
         * @global type $wp_xmlrpc_server
         * @param type $args
         * @return string
         */
        public function dispatch($args) {

          $call = $this->_get_called_method();

          if (method_exists($this, $call)) {
            if ( $this->_read_args( $args ) ) {
              $status = call_user_func_array(array($this, $call), array( $args ));
              return $status;
            } else {
              return "Unauthorized";
            }
          } else {
            return "Method not allowed";
          }
        }

        /**
         * Get method that was actually called to find it in child class
         * @global $wp_xmlrpc_server
         * @return type
         */
        private function _get_called_method() {
          global $wp_xmlrpc_server;

          $call = $wp_xmlrpc_server->message->methodName;
          $pieces = explode(".", $call);

          return $pieces[1];
        }

        /**
         * Decrypt args
         * @param type $args
         */
        private function _read_args( &$args ) {
          $args = json_decode(
            trim(
              mcrypt_decrypt(
                MCRYPT_RIJNDAEL_256,
                md5( $this->secret_key ),
                base64_decode($args[0]),
                MCRYPT_MODE_ECB,
                mcrypt_create_iv(
                  mcrypt_get_iv_size(
                    MCRYPT_RIJNDAEL_256,
                    MCRYPT_MODE_ECB
                  ),
                  MCRYPT_RAND
                )
              )
            )
          );
          if ( is_array( $args ) && !empty( $args ) ) {
            return true;
          }
          return false;
        }
      }
    }

    /**
     * Prevent class redeclaration
     */
    if ( !class_exists( 'UsabilityDynamics\UD_XMLRPC' ) ) {
      /**
       * UD XML-RPC Server Library. May be extended with a class with public methods.
       * @author korotkov@ud
       */
      class UD_XMLRPC extends XMLRPC {

        /**
         * Validate all incoming requests using callback to this method.
         *
         * @param md5 string $request_data
         * @return boolean
         */
        public function validate( $request_data ) {
          if ( md5( $_SERVER['HTTP_HOST'].$this->public_key.$this->secret_key ) == $request_data[0] ) { return true; }
          return false;
        }

        /**
         * Test call to check if request is correct. Sent data will be returned in decrypted state.
         * @return mixed
         */
        public function test( $request_data ) {
          return $request_data;
        }

      }
    }

    /**
     * Prevent class redeclaration
     */
    if ( !class_exists('UsabilityDynamics\UD_PRODUCTS_XMLRPC') ) {
      /**
       * WP UD Products should initialize this server to listen for incoming commands connected to premium features management
       */
      class UD_PRODUCTS_XMLRPC extends UD_XMLRPC {

        /**
         * Add Premium Feature to the client's site
         * @param type $request_data
         */
        public function add_feature( $request_data ) {
          /**
           * @todo: implement
           */
          return $this->namespace;
        }

        /**
         * Update Premium Feature on client's site
         * @param type $request_data
         */
        public function update_feature( $request_data ) {
          /**
           * @todo: implement
           */
          return $this->namespace;
        }

        /**
         * Delete Premium Feature from client's site
         * @param type $request_data
         */
        public function delete_feature( $request_data ) {
          /**
           * @todo: implement
           */
          return $this->namespace;
        }

      }
    }

    /**
     * Prevent class redeclaration
     */
    if ( !class_exists('UsabilityDynamics\UI') ) {
      /**
       * Class that is responsible for API UI
       */
      class UI {

        /**
         * Namespace similar to UD_XMLRPC namespace
         * @var type
         */
        private $namespace;

        /**
         * Construct
         * @param type $namespace
         */
        function __construct( $namespace ) {
          $this->namespace = $namespace;

          add_action('wp_ajax_'.$namespace.'_ud_api_save_keys', array( $this, 'ud_api_save_keys' ));
        }

        /**
         * Use this for rendering API Keys fields anywhere.
         * @param type $args
         * @return type
         */
        function render_api_fields( $args = array() ) {

          wp_enqueue_script('jquery');

          $defaults = array(
              'return' => false,
              'input_class' => 'ud_api_input',
              'container' => 'div',
              'container_class' => 'ud_api_credentials',
              'input_wrapper' => 'div',
              'input_wrapper_class' => 'ud_api_field',
              'secret_key_label' => 'Secret Key',
              'public_key_label' => 'Public Key',
              'before' => '',
              'after' => ''
          );

          extract( wp_parse_args($args, $defaults) );

          ob_start();
          echo $before;
          ?>

          <script type="text/javascript">
            jQuery(document).ready(function(){
              jQuery('.up_api_keys .ud_api_keys_save').on('click', function(e){
                jQuery('.up_api_keys .ud_api_message').empty();

                var data = {
                  action: '<?php echo $this->namespace ?>_ud_api_save_keys',
                  <?php echo $this->namespace ?>_api_public_key: jQuery('[name="<?php echo $this->namespace ?>_api_public_key"]').val(),
                  <?php echo $this->namespace ?>_api_secret_key: jQuery('[name="<?php echo $this->namespace ?>_api_secret_key"]').val()
                };

                jQuery.ajax(ajaxurl, {
                  dataType: 'json',
                  type: 'post',
                  data: data,
                  success: function(data) {
                    jQuery.each(data.message, function(key, value){
                      jQuery('.up_api_keys .ud_api_message').append('<p>'+value+'</p>');
                    });
                  }
                });
              });
            });
          </script>

          <<?php echo $container; ?> class="<?php echo $container_class; ?> up_api_keys">

              <<?php echo $input_wrapper; ?> class="<?php echo $input_wrapper_class; ?>">
                <label for="<?php echo $this->namespace ?>_api_secret_key"><?php echo $secret_key_label; ?></label>
                <input id="<?php echo $this->namespace ?>_api_secret_key" value="<?php echo get_option( $this->namespace.'_api_secret_key', '' ); ?>" name="<?php echo $this->namespace ?>_api_secret_key" />
              </<?php echo $input_wrapper; ?>>

              <<?php echo $input_wrapper; ?> class="<?php echo $input_wrapper_class; ?>">
                <label for="<?php echo $this->namespace ?>_api_public_key"><?php echo $public_key_label; ?></label>
                <input id="<?php echo $this->namespace ?>_api_public_key" value="<?php echo get_option( $this->namespace.'_api_public_key', '' ); ?>" name="<?php echo $this->namespace ?>_api_public_key" />
              </<?php echo $input_wrapper; ?>>

              <input class="ud_api_keys_save" type="button" value="Save" />

            <span class="ud_api_message"></span>

          </<?php echo $container; ?>>

          <?php
          echo $after;
          $html = apply_filters( $this->namespace.'_ud_api_ui', ob_get_clean() );

          if ( $return ) return $html;
          echo $html;
        }

        /**
         * Save API keys
         */
        function ud_api_save_keys() {
          $result = array();
          $success = false;

          if ( update_option( $this->namespace.'_api_public_key', $_POST[$this->namespace.'_api_public_key'] ) ) {
            $result[] = 'Public Key has been updated.';
            $success = true;
          }
          if ( update_option( $this->namespace.'_api_secret_key', $_POST[$this->namespace.'_api_secret_key'] ) ) {
            $result[] = 'Secret Key has been updated.';
            $success = true;
          }

          if ( empty( $result ) ) {
            $result[] = 'Nothing has been updated.';
          }

          die( json_encode( array(
              'success' => $success,
              'message' => $result
          ) ) );
        }
      }
    }
  }
}