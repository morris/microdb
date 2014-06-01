<?php

namespace MicroDB;

/**
 * A file-based JSON object database
 */
class Database {

	/**
	 * Constructor
	 */
	public function __construct($path, $idFunc = '?') {
		$path = (string) $path;
		if (substr($path, -1) != '/')
			$path .= '/';
		$this->path = $path;
		$this->idFunc = $idFunc;
	}

	/**
	 * Save data to database
	 */
	public function save($id, $data) {		
		$this->trigger('beforeSave', $id, $data);
		
		if($this->caching)
			$this->cache[$id] = $data;
		
		$this->put($this->path . $id, json_encode($data));
		
		$this->trigger('saved', $id, $data);
	}
	
	/**
	 * Load data from database
	 */
	public function load($id) {
		if(is_array($id)) {
			$results = array();
			foreach($id as $i) {
				$results[$i] = $this->load($i);
			}
			return $results;
		}
		
		$this->trigger('beforeLoad', $id);
		
		if($this->caching && isset($this->cache[$id])) {
			$this->trigger('loaded', $id, $this->cache[$id]);
			return $this->cache[$id];
		}
		
		$data = json_decode($this->get($this->path . $id), true);
		
		if($this->caching)
			$this->cache[$id] = $data;
			
		$this->trigger('loaded', $id, $data);
		
		return $data;
	}
	
	/**
	 * Delete data from database
	 */
	public function delete($id) {
		if(is_array($id)) {
			$results = array();
			foreach($id as $i) {
				$results[$i] = $this->delete($i);
			}
			return $results;
		}
		
		$this->trigger('beforeDelete', $id);
		
		if($this->caching)
			unset($this->cache[$id]);
		
		$return = unlink($this->path . $id);
		
		$this->trigger('deleted', $id, $return);
		
		return $return;
	}
	
	/**
	 * Find data using either key-value map or callback
	 */
	public function find($where = array()) {
		$ids = $this->scandir($this->path);
		
		$results = array();
		
		if(is_callable($where)) {
			foreach($ids as $id) {
				$data = $this->load($id);
				if($where($data))
					$results[$id] = $data;
			}
		} else {
			foreach($ids as $id) {
				$match = true;
				$data = $this->load($id);
				foreach($where as $key => $value) {
					if(@$data[$key] !== $value) {
						$match = false;
						break;
					}
				}
				if($match)
					$results[$id] = $data;
			}
		}
		
		return $results;
	}
	
	function first($where) {
		$all = $this->find($where);
		return @$all[0];
	}

	/**
	 * Put file contents
	 */
	function put($file, $data, $mode = false) {
		// don't overwrite if unchanged, just touch
		if(file_exists($file) && file_get_contents($file) === $data) {
			touch($file);
			return;
		}
	
		if(!$fp = @fopen($file, 'wb')) {
			throw new \Exception('MicroDB error: Could not open '.$file.' for writing');
		}

		fwrite($fp, $data);
		fclose($fp);

		$this->chmod($file, $mode);
		return true;
	}

	/**
	 * Get file contents
	 */
	function get($file) {
		if(!$this->check($file))
			return null;
		return file_get_contents($file);
	}
	
	/**
	 * Set file permissions
	 */
	function chmod($file, $mode = false) {
		if(!$mode)
			$mode = 0644;
		return @chmod($file, $mode);
	}

	/**
	 * Check if file exists
	 */
	function check($path) {
		return file_exists($path);
	}
	
	/**
	 * List files in a directory
	 */
	function scandir($path) {
		return array_slice(scandir($path), 2);
	}
	
	/**
	 * Directory of this database
	 */
	protected $path;
	
	/**
	 * Maps ids to cached data
	 */
	public $cache = array();
	
	/**
	 * Is caching enabled?
	 */
	public $caching = true;
	
	// EVENTS
	
	/**
	 * Bind a handler to an event, with given priority.
	 * Higher priority handlers will be executed earlier.
	 * @param string|array Event keys
	 * @param callable Handler
	 * @param number Priority of handler
	 */
	function on($event, $handler, $priority = 0) {
		$events = $this->splitEvents($event);

		foreach ($events as $event) {
			if (!is_callable($handler)) {
				throw new \InvalidArgumentException('Handler must be callable');
			}

			if (!isset($this->handlers[$event])) {
				$this->handlers[$event] = array();
			}

			if (!isset($this->handlers[$event][$priority])) {
				$this->handlers[$event][$priority] = array();

				// keep handlers sorted by priority
				krsort($this->handlers[$event]);
			}

			$this->handlers[$event][$priority][] = $handler;
		}

		return $this;
	}

	/** 
	 * Unbind a handler on one, multiple or all events
	 * @param string|array Event keys, comma separated
	 * @param callable Handler
	 */
	function off($event, $handler = null) {
		if(is_callable($event)) {
			$handler = $event;
			$event = array_keys($this->handlers);
		}
		
		$events = $this->splitEvents($event);

		foreach ($events as $event) {
			foreach ($this->handlers[$event] as $priority => $handlers) {
				foreach ($handlers as $i => $h) {
					if (!isset($handler) || $handler === $h) {
						unset($this->handlers[$event][$priority][$i]);
					}
				}
			}
		}
		
		return $this;
	}
	
	/**
	 * Trigger one or more events with given arguments
	 * @param string|array Event keys, comma separated
	 * @param mixed Optional arguments
	 */
	function trigger($event, $args = null) {
		$args = func_get_args();
		array_shift($args);
		$args[] = $event;

		$events = $this->splitEvents($event);

		foreach ($events as $event) {
			if (isset($this->handlers[$event])) {
				foreach ($this->handlers[$event] as $priority => $handlers) {
					foreach ($handlers as $handler) {
						call_user_func_array($handler, $args);
					}
				}
			}
		}
		
		return $this;
	}
	
	/**
	 * Split event keys by comma
	 */
	protected function splitEvents($events) {
		if(is_array($events))
			return $events;
		
		return preg_split('(\s*,\s*)', $events);
	}
	
	/**
	 * Map of registered handlers
	 */
	protected $handlers = array();
}