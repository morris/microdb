<?php

namespace MicroDB;

/**
 * A file-based JSON object database
 */
class Database {

	/**
	 * Constructor
	 */
	function __construct($path) {
		$path = (string) $path;
		if (substr($path, -1) != '/')
			$path .= '/';
		$this->path = $path;
	}
	
	/**
	 * Create an item with auto incrementing id
	 */
	function create($data = array()) {
		$self = $this;
		return $this->synchronized('_auto', function() use ($self, $data) {
			$next = 1;
			if($self->exists('_auto'))
				$next = $self->load('_auto', 'next');
			
			$self->save('_auto', array('next' => $next+1));
			$self->save($next, $data);
			
			return $next;
		});
	}

	/**
	 * Save data to database
	 */
	function save($id, $data) {
		$self = $this;
		return $this->synchronized($id, function() use ($self, $id, $data) {
			$self->triggerId('beforeSave', $id, $data);
			
			$self->put($this->path.$id, json_encode($data));
			
			$self->triggerId('saved', $id, $data);
		});
	}
	
	/**
	 * Load data from database
	 */
	function load($id, $key = null) {
		if(is_array($id)) {
			$results = array();
			foreach($id as $i) {
				$results[$i] = $this->load($i);
			}
			return $results;
		}
		
		$this->triggerId('beforeLoad', $id);
		
		$data = json_decode($this->get($this->path.$id), true);
			
		$this->triggerId('loaded', $id, $data);
		
		if(isset($key))
			return @$data[$key];
		return $data;
	}
	
	/**
	 * Delete data from database
	 */
	function delete($id) {
		if(is_array($id)) {
			$results = array();
			foreach($id as $i) {
				$results[$i] = $this->delete($i);
			}
			return $results;
		}
		
		$self = $this;
		return $this->synchronized($id, function() use ($self, $id) {
			$self->triggerId('beforeDelete', $id);
			
			$self->erase($this->path.$id);
			
			$self->triggerId('deleted', $id);
		});
	}
	
	/**
	 * Find data matching key-value map or callback
	 */
	function find($where = array(), $first = false) {
		$results = array();
		
		if(is_callable($where)) {
			$this->eachId(function($id) use (&$results, $where, $first) {
				$data = $this->load($id);
				if($where($data)) {
					if($first)
						return $data;
					$results[$id] = $data;
				}
			});
		} else {
			$this->eachId(function($id) use (&$results, $where, $first) {
				$match = true;
				$data = $this->load($id);
				foreach($where as $key => $value) {
					if(@$data[$key] !== $value) {
						$match = false;
						break;
					}
				}
				if($match) {
					if($first)
						return $data;
					$results[$id] = $data;
				}
			});
		}
		
		return $results;
	}
	
	/**
	 * Find first item key-value map or callback
	 */
	function first($where = null) {
		return $this->find($where, true);
	}
	
	/**
	 * Checks wether an id exists
	 */
	function exists($id) {
		return is_file($this->path.$id);
	}
	
	/**
	 * Triggers "repair" event.
	 * On this event, applications should repair inconsistencies in the
	 * database, e.g. rebuild indices.
	 */
	function repair() {
		$this->trigger('repair');
	}
	
	/**
	 * Call a function for each id in the database
	 */
	function eachId($func) {		
		$res = opendir($this->path);

		while(($id = readdir($res)) !== false) {
			if($id == "." || $id == ".." || $id{0} == '_')
				continue;

			$func($id);
		}
	}
	
	/**
	 * Trigger an event only if id is not hidden
	 */
	function triggerId($event, $id, $args = null) {
		if(!$this->hidden($id))
			call_user_func_array(array($this, 'trigger'), func_get_args());
		return $this;
	}
	
	/**
	 * Is this id hidden, i.e. no events should be triggered?
	 * Hidden ids start with an underscore
	 */
	function hidden($id) {
		return $id{0} == '_';
	}
	
	// SYNCHRONIZATION
	
	/**
	 * Call a function in a mutually exclusive way, locking on a file
	 * A process will only block other processes and never block itself,
	 * so you can safely nest synchronized operations.
	 */
	function synchronized($lock, $func) {
		// if already locked by this process, just call function
		if(isset($this->locks[$lock])) {
			return $func();
		}
		
		// otherwise, acquire lock
		$file = $this->path . '_' . $lock . '_lock';
		$handle = fopen($file, 'w');

		if($handle && flock($handle, LOCK_EX)) {
			$this->locks[$lock] = true;
			try {
				$return = $func();
				unset($this->locks[$lock]);
				flock($handle, LOCK_UN);
				fclose($handle);
				return $return;
			} catch(\Exception $e) {
				unset($this->locks[$lock]);
				flock($handle, LOCK_UN);
				fclose($handle);
				throw $e;
			}
		} else {
			throw new \Exception('Unable to synchronize over '.$lock);
		}
	}
	
	/**
	 * Set of acquired locks
	 */
	protected $locks = array();
	
	// IO

	/**
	 * Put file contents
	 */
	protected function put($file, $data, $mode = false) {
		// don't overwrite if unchanged, just touch
		if(is_file($file) && file_get_contents($file) === $data) {
			touch($file);
			chmod($file, $this->mode);
			return;
		}
	
		file_put_contents($file, $data);
		chmod($file, $this->mode);
		return true;
	}

	/**
	 * Get file contents
	 */
	protected function get($file) {
		if(!is_file($file))
			return null;
		return file_get_contents($file);
	}
	
	/**
	 * Remove file from filesystem
	 */
	protected function erase($file) {
		return unlink($file);
	}
	
	/**
	 * Get data path
	 */
	public function getPath() {
		return $this->path;
	}
	
	/**
	 * Directory where data files are stored
	 */
	protected $path;
	
	/**
	 * Mode for created files
	 */
	public $mode = 0644;
	
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
	 * @param string|array Event keys, whitespace/comma separated
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
	 * Split event keys by whitespace and/or comma
	 */
	protected function splitEvents($events) {
		if(is_array($events))
			return $events;
		
		return preg_split('([\s,]+)', $events);
	}
	
	/**
	 * Map of event handlers
	 */
	protected $handlers = array();
}