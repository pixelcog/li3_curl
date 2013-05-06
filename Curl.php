<?php
/**
 * Enhanced Curl Socket for Lithium
 *
 * @copyright     Copyright 2013, PixelCog Inc. (http://pixelcog.com)
 *                Original Copyright 2013, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_curl;

use lithium\net\http\Message;
use li3_curl\CurlStackException;
use UnexpectedValueException;

/**
 * A cURL-based socket adapter
 *
 * This cURL adapter provides the required method implementations of the abstract Socket class
 * for `open`, `close`, `read`, `write`, `timeout` `eof` and `encoding`.
 *
 * Your PHP installation must have been compiled with the `--with-curl[=DIR]` directive. If this
 * is not the case, you must either recompile PHP with the proper configuration flags to enable
 * curl, or you may use the `Stream` adapter that is also included with the Lithium core.
 *
 * @link http://www.php.net/manual/en/curl.installation.php
 * @see lithium\net\socket\Stream
 */
class Curl extends \lithium\net\socket\Curl {

	/**
	 * cURL stack status constants
	 *
	 * @var integer
	 */
	const STATUS_WAITING  = 1;
	const STATUS_ACTIVE   = 2;
	const STATUS_FINISHED = 3;
	const STATUS_CANCELED = 4;
	const STATUS_EXPIRED  = 5;
	const STATUS_ERROR    = 6;

	/**
	 * A resource identifier returned from `curl_multi_init()`
	 *
	 * @var resource
	 */
	protected static $_stackHandle = null;

	/**
	 * cURL resource stack. This array contains references to instantiated cURL objects, their settings
	 * and their results.
	 *
	 * @var array
	 */
	protected static $_stack = array();

	/**
	 * An array mapping currently enqueued resource identifiers (typecast as integers) to stack entries
	 *
	 * @var array
	 */
	protected static $_resourceMap = array();

	/**
	 * An array of resources which are currently bound to `$_batchResource` (LIFO)
	 *
	 * @var array
	 */
	protected static $_active = array();

	/**
	 * An array of resources which are waiting to be bound to `$_batchResource` (FIFO)
	 *
	 * @var array
	 */
	protected static $_queue = array();

	/**
	 * How many cURL requests to run at a given time
	 *
	 * @var integer
	 */
	public static $limit = 4;

	/**
	 * Cache for the cURL object's information array
	 *
	 * @var array
	 */
	protected $_info = null;

	/**
	 * Cache for the cURL object's error string
	 *
	 * @var string
	 */
	protected $_error = null;

	/**
	 * Cache for the cURL object's status code
	 *
	 * @var integer
	 */
	protected $_code = null;

	/**
	 * Initialize our stack handle if it is not already initialized.
	 *
	 * @todo handle error responses from curl_multi_init()
	 * @return resource
	 */
	protected static function _initStack() {
		if (!is_resource(static::$_stackHandle)) {
			static::$_stackHandle = curl_multi_init();
		}
		return static::$_stackHandle;
	}

	/**
	 * Initialize our stack handle if it is not already initialized.
	 *
	 * @return resource
	 */
	protected static function _closeStack() {
		if (!is_resource(static::$_stackHandle)) {
			return true;
		}
		while (count(static::$_active)) {
			static::pull();
		}
		curl_multi_close(static::$_stackHandle);
		static::$_stackHandle = null;
		return true;
	}

	/**
	 * Enqueue a cURL object for parallel processing.
	 *
	 * @param object $obj A cURL object to add to our stack
	 * @param array $options Options to apply to our cURL execution. Valid keys are `callback` and
	 *        `timeout`. `callback` refers to a simple function which takes $obj as a parameter once
	 *        its corresponding handle has finished executing. `timeout` is the time in seconds this
	 *        handle is given to execute before it is to be expired.
	 * @return void
	 */
	public static function enqueue(Curl $obj, array $options = array()) {
		$defaults = array(
			'callback' => null,
			'timeout' => 30
		);
		if (!$obj || !$resource = $obj->resource()) {
			return false;
		}
		if (!empty($options['callback']) && !is_callable($options['callback'])) {
			throw new UnexpectedValueException('Invalid callback function provided.');
		}

		if (!static::queued($obj, $key)) {
			$stack = array(
				'obj' => $obj,
				'url' => curl_getinfo($resource, CURLINFO_EFFECTIVE_URL),
				'added' => microtime(true),
				'removed' => null,
				'time' => null,
				'response' => null,
				'code' => null,
				'info' => array(),
				'error' => null,
				'status' => static::STATUS_WAITING
			) + $options + $defaults;

			$key = array_push(static::$_stack, $stack) - 1;
			static::$_resourceMap[(integer) $resource] = $key;
			static::$_queue[] = $resource;
		} else {
			$options = array_intersect_key($options, $defaults);
			static::$_stack[$key] = $options + static::$_stack[$key];
		}
		static::_refresh(array('clean' => false));

		return static::$_stack[$key]['status'];
	}

	/**
	 * Return an array represenation of the current cURL call stack.
	 *
	 * @return array
	 */
	public static function stack() {
		$stack = array();
		foreach (static::$_stack as $call) {
			$exclude = array('obj', 'response', 'callback');
			$stack[] = array_diff_key($call, array_flip($exclude));
		}
		return $stack;
	}

	/**
	 * Remove a cURL object from parallel processing queue.
	 *
	 * @param object $obj A cURL object to remove from our stack
	 * @param boolean $wait If true, wait for the queued object to finish processing, otherwise cancel
	 *        and dequeue immediately.
	 * @return array The results of the cURL execution
	 */
	public static function dequeue(Curl $obj, $wait = false) {
		if (!static::queued($obj, $key)) {
			return false;
		}
		if ($wait) {
			static::wait($obj);
		}
		$resource = $obj->resource();
		$status = static::$_stack[$key]['status'];

		if ($status === static::STATUS_WAITING || $status === static::STATUS_ACTIVE) {
			static::_finish($resource, array('status' => static::STATUS_CANCELED));
			static::_refresh(array('clean' => false));
		}
		$data = static::$_stack[$key];
		$update = array(
			'obj' => null,
			'response' => null,
			'callback' => null,
			'removed' => microtime(true)
		);
		static::$_stack[$key] = $update + static::$_stack[$key];
		unset(static::$_resourceMap[(integer) $resource]);

		return $data;
	}

	/**
	 * Check whether a cURL object is currently in the parallel processing queue, and set the `$key`
	 * to a non-null integer representing its position within $_stack if so.
	 *
	 * @param object $obj A cURL object to remove from our stack
	 * @param integer $key Gets set to reference to the position of `$obj` within `Curl::$_stack`
	 * @return array The results of the cURL execution
	 */
	public static function queued(Curl $obj, &$key = null) {
		$id = (integer) $obj->resource();
		if (isset(static::$_resourceMap[$id])) {
			$key = static::$_resourceMap[$id];
			return true;
		}
		return false;
	}

	/**
	 * Push a resource from `Curl::$_queue` into `Curl::$_active` and bind it to the stack handle.
	 *
	 * @param resource $resource A cURL resource to make active
	 * @return boolean True if the operation was successful, false otherwise
	 */
	public static function push($resource = null) {
		$handle = static::_initStack();

		if ($resource) {
			if (in_array($resource, static::$_active)) {
				return true;
			}
			$position = array_search($resource, static::$_queue, true);
			if ($position === false) {
				return false;
			}
			array_splice(static::$_queue, $position, 1);
		}
		elseif (!$resource = array_shift(static::$_queue)) {
			return false;
		}
		curl_multi_add_handle($handle, $resource);
		static::$_active[] = $resource;
		$key = static::$_resourceMap[(integer) $resource];
		static::$_stack[$key]['status'] = static::STATUS_ACTIVE;
		return true;
	}

	/**
	 * Pull a resource from `Curl::$_active` into `Curl::$_queue` and unbind it from the stack handle.
	 *
	 * @param resource $resource A cURL resource to make inactive
	 * @return boolean True if the operation was successful, false otherwise
	 */
	public static function pull($resource = null) {
		$handle = static::_initStack();

		if ($resource) {
			$position = array_search($resource, static::$_active, true);
			if ($position === false) {
				return true;
			}
			array_splice(static::$_active, $position, 1);
		}
		elseif (!$resource = array_pop(static::$_active)) {
			return false;
		}
		curl_multi_remove_handle($handle, $resource);
		array_unshift(static::$_queue, $resource);
		$key = static::$_resourceMap[(integer) $resource];
		static::$_stack[$key]['status'] = static::STATUS_WAITING;
		return true;
	}

	/**
	 * Refresh the stack handle status. If `$options['clean']` is `true` clear out finished and expired
	 * cURL handles and make room for new ones.
	 *
	 * @param resource $resource A cURL resource to make inactive
	 * @param boolean $running Is set to true if there are currently any active downloads
	 * @return void
	 */
	protected static function _refresh(array $options = array()) {
		$defaults = array('clean' => true);
		$options += $defaults;
		$continue = false;

		if (empty(static::$_active) && empty(static::$_queue)) {
			static::_closeStack();
			return;
		}
		$handle = static::_initStack();
		
		while (count(static::$_active) < static::$limit && static::push());
		
		while (($status = curl_multi_exec($handle, $running)) === CURLM_CALL_MULTI_PERFORM);
		
		if ($status !== CURLM_OK) {
			$message = 'Curl Stack Error from `curl_multi_exec()` (Code ' . intval($status) . ')';
			throw new CurlStackException($message);
		}
		if ($options['clean'] || !$running) {
			while ($finished = curl_multi_info_read($handle)) {
				if ($finished['msg'] !== CURLMSG_DONE) {
					$message = 'Unexpected Response ' . intval($ready['msg']) . ' from `curl_multi_info_read()`';
					throw new CurlStackException($message);
				}
				$result = array(
					'code' => $finished['result'],
					'status' => static::STATUS_FINISHED
				);
				static::_finish($finished['handle'], $result);
				$continue = true;
			}
			$now = microtime(true);

			foreach (static::$_stack as $key => $stack) {
				$active = ($stack['status'] === static::STATUS_WAITING || $stack['status'] === static::STATUS_ACTIVE);

				if ($active && $stack['added'] + $stack['timeout'] < $now) {
					$resource = $stack['obj']->resource();
					$result = array(
						'code' => null,
						'error'  => 'The operation timed out after ' . intval($stack['timeout']*1000) . 'ms',
						'status' => static::STATUS_EXPIRED
					);
					static::_finish($resource, $result);
					$continue = true;
				}
			}
		}
		return $continue ? static::_refresh() : $running;
	}

	/**
	 * Finalize a `Curl::$_stack` entry corresponding to `$resource` by gathering response data and status
	 * messages, removing the cURL resource from the stack, and running any callbacks.
	 *
	 * @param resource $resource A cURL resource to finalize
	 * @param boolean $result An associated array of values to update within `Curl::$_stack`
	 * @return void
	 */
	protected static function _finish($resource, $result = array()) {
		$defaults = array(
			'status' => static::STATUS_FINISHED
		);
		$result += $defaults;
		
		if (!is_resource($resource) || !isset(static::$_resourceMap[(integer) $resource])) {
			return;
		}
		$key = static::$_resourceMap[(integer) $resource];
		$handle = static::_initStack();
		
		if (!isset($result['response'])) {
			$result['response'] = curl_multi_getcontent($resource);
		}
		if (!isset($result['info'])) {
			$result['info'] = curl_getinfo($resource);
		}
		if (!isset($result['code'])) {
			$result['code'] = curl_errno($resource);
		}
		if (!isset($result['error'])) {
			$result['error'] = curl_error($resource);
		}
		$result['time'] = microtime(true) - static::$_stack[$key]['added'];
		
		if (count(static::$_active) <= static::$limit) {
			static::push();
		}
		curl_multi_remove_handle($handle, $resource);
		
		$position = array_search($resource, static::$_active, true);
		if ($position !== false) {
			array_splice(static::$_active, $position, 1);
		}
		$position = array_search($resource, static::$_queue, true);
		if ($position !== false) {
			array_splice(static::$_queue, $position, 1);
		}
		
		static::$_stack[$key] = $result + static::$_stack[$key];
		if (is_callable(static::$_stack[$key]['callback'])) {
			$callback = static::$_stack[$key]['callback'];
			$callback(static::$_stack[$key]['obj']);
		}
	}

	/**
	 * Block until a specified curl object is finished executing, or until all objects in the stack
	 * are finished executing, or `$timeout` seconds.
	 *
	 * @param object $obj A cURL object to wait for
	 * @param boolean $timeout Time in seconds to wait before returning
	 * @return boolean True if operation completed before timing out, false otherwise.
	 */
	public static function wait(Curl $obj = null, $timeout = 30) {
		$timeout = microtime(true) + $timeout;

		if ($obj === null || !static::queued($obj, $key)) {
			if (empty(static::$_queue) && empty(static::$_active)) {
				return true;
			}
			$key = null;
		} else {
			$status = static::$_stack[$key]['status'];
			if ($status !== static::STATUS_WAITING && $status !== static::STATUS_ACTIVE) {
				return true;
			}
		}
		$handle = static::_initStack();
		while (static::_refresh()) {
			if ($key !== null) {
				$status = static::$_stack[$key]['status'];
				if ($status !== static::STATUS_WAITING && $status !== static::STATUS_ACTIVE) {
					break;
				}
			}
			if (microtime(true) > $timeout) {
				return false;
			}
			curl_multi_select($handle, 0.05);
		}
		return true;
	}
	
	/**
	 * Return the status constant (one of `Curl::STATUS_***`) corresponding to `$obj` or `false` if
	 * the object does not exist in the stack.
	 *
	 * @param $obj cURL object
	 * @return integer
	 */
	public static function status(Curl $obj) {
		if (!$obj || !static::queued($obj, $key)) {
			return false;
		}
		return static::$_stack[$key]['status'];
	}

	/**
	 * Opens a cURL connection and initializes the internal resource handle.
	 *
	 * @param array $options
	 * @return mixed cURL resource on success, false otherwise
	 */
	public function open(array $options = array()) {
		if (is_resource($this->_resource)) {
			$this->close();
		}
		return parent::open($options);
	}

	/**
	 * Closes the cURL connection.
	 *
	 * @return boolean True on closed connection
	 */
	public function close() {
		if (is_resource($this->_resource) && static::queued($this)) {
			static::dequeue($this);
		}
		$this->_info = null;
		$this->_error = null;
		$this->_code = null;
		return parent::close();
	}

	/**
	 * Returns info from the last cURL connection.
	 *
	 * @return array cURL information corresponding with `curl_getinfo()`
	 */
	public function info() {
		if (empty($this->_info) && is_resource($this->_resource)) {
			$this->_info = curl_getinfo($this->_resource);
		}
		return $this->_info;
	}

	/**
	 * Returns error string from the last cURL connection.
	 *
	 * @return array cURL information corresponding with `curl_getinfo()`
	 */
	public function error() {
		if (empty($this->_error) && is_resource($this->_resource)) {
			$this->_error = curl_error($this->_resource);
		}
		return $this->_error;
	}

	/**
	 * Reads data from the cURL connection.
	 *
	 * @return mixed Response data on success, false otherwise
	 */
	public function read() {
		if (is_resource($this->_resource) && static::queued($this)) {
			$info = static::dequeue($this, true);
			$this->_info  = $info['info'] ?: null;
			$this->_error = $info['error'] ?: (empty($info['response']) ? "Empty Response" : null);
			$this->_code  = $info['code'] ?: null;
			return $info['response'] ?: false;
		}
		$this->_info  = null;
		$this->_error = null;
		$this->_code  = null;
		return parent::read();
	}

	/**
	 * Writes data to cURL options
	 *
	 * @param object $data a `lithium\net\Message` object or array
	 * @return boolean
	 */
	public function write($data = null) {
		if (is_resource($this->_resource) && static::queued($this)) {
			static::dequeue($this);
		}
		return parent::write($data);
	}

	/**
	 * Starts executing this object's cURL handle using the cURL stack.
	 *
	 * @return boolean True if started, false otherwise.
	 */
	public function start($callback = null) {
		if (!is_resource($this->_resource)) {
			return false;
		}
		static::enqueue($this, array_filter(compact('callback')));
		return true;
	}

	/**
	 * Removes this object's cURL handle from the cURL stack.
	 *
	 * @return boolean True if stopped, false otherwise.
	 */
	public function stop() {
		if (!is_resource($this->_resource)) {
			return false;
		}
		static::dequeue($this);
		return true;
	}

	/**
	 * A convenience method to set the curl `CURLOPT_CONNECTTIMEOUT`
	 * setting for the current connection. This determines the number
	 * of seconds to wait while trying to connect.
	 *
	 * Note: A value of 0 may be used to specify an indefinite wait time.
	 *
	 * @param integer $time The timeout value in seconds
	 * @return boolean False if the resource handle is unavailable or the
	 *         option could not be set, true otherwise.
	 */
	public function timeout($time) {
		if (is_resource($this->_resource) && static::queued($this)) {
			static::enqueue($this, array('timeout' => $time));
		}
		parent::timeout($time);
	}
}

?>