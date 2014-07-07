<?php

namespace MicroDB;

/**
 * Represents and manages an index on a database.
 * An index maps keys to ids where keys are computed from items.
 */
class Index {

	/**
	 * Creates an index on a database with a name and an index key
	 * function. The index listens to database events to update itself.
	 */
	function __construct($db, $name, $keyFunc, $compare = null) {
		$this->db = $db;
		$this->name = $name;
		$this->keyFunc = $keyFunc;
		$this->compare = $compare;
		
		// register with database
		$this->db->on('saved', array($this, 'update'));
		$this->db->on('deleted', array($this, 'delete'));
		$this->db->on('repair', array($this, 'rebuild'));
	}
	
	/**
	 * Find ids that match a key/callback
	 */
	function find($where, $first = false) {
		if(empty($this->map)) $this->restore();
		
		if(!is_string($where) && is_callable($where)) {
			$ids = array();
			foreach($this->map as $k => $i) {
				if($where($k)) {
					if($first)
						return $i;
					$ids = array_merge($ids, $i);
				}
			}
			return $ids;
		}
		
		if($first) {
			if(isset($this->map[$where][0]))
				return $this->map[$where][0];
		} else {
			if(isset($this->map[$where]))
				return $this->map[$where];
			return array();
		}
	}
	
	/**
	 * Get first matching id
	 */
	function first($where) {
		return $this->find($where, true);
	}
	
	/**
	 * Get slice of mapping, useful for paging
	 */
	function slice($offset = 0, $length = null) {
		if(empty($this->map)) $this->restore();
		
		$slice = array_slice($this->map, $offset, $length);
		return call_user_func_array('array_merge', $slice);
	}
	
	/**
	 * Load items that match a key/callback
	 */
	function load($where, $first = false) {
		$ids = $this->find($where, $first);
		return $this->db->load($ids);
	}
	
	/**
	 * Load first matching item
	 */
	function loadFirst($where) {
		return $this->load($where, true);
	}
	
	/**
	 * Load slice of mapping
	 */
	function loadSlice($offset = 0, $length = null) {
		$ids = $this->slice($offset, $length);
		return $this->db->load($ids);
	}
	
	/**
	 * Update item in index
	 * Synchronized
	 */
	function update($id, $data) {		
		$self = $this;
		$this->apply(function() use ($self, $id, $data) {
			if($self->updateTemp($id, $data))
				$self->store();
		});
	}
	
	protected function updateTemp($id, $data) {
		// compute new keys
		$keys = $this->keys($data);
		
		// skip if key is undefined
		if($keys === null || $keys === false)
			return;
		if(!is_array($keys))
			$keys = array($keys);
		
		$store = false;
		$oldKeys = @$this->inverse[$id];
		
		// insert new keys
		foreach($keys as $key) {
			// skip if key is already in index
			if(isset($oldKeys[$key])) {
				unset($oldKeys[$key]); // don't remove that entry later
				continue;
			}
			
			$this->map[$key][] = $id;
			$this->inverse[$id][$key] = count($this->map[$key]) - 1;
			$store = true;
		}
		
		// remove remaining invalid entries
		if(!empty($oldKeys)) {
			foreach($oldKeys as $key => $offset) {
				array_splice($this->map[$key], $offset, 1);
				unset($this->inverse[$id][$key]);
				$store = true;
			}
		}
		
		return $store;
	}
	
	/**
	 * Delete item from index
	 * Synchronized
	 */
	function delete($id) {
		$self = $this;
		$this->apply(function() use ($self, $id) {
			$store = false;
			$oldKeys = @$self->inverse[$id];
			
			// remove all old entries
			if(!empty($oldKeys)) {
				foreach($oldKeys as $key => $offset) {
					array_splice($self->map[$key], $offset, 1);
					unset($self->inverse[$id][$key]);
					$store = true;
				}
			}
			
			if($store)
				$self->store();
		});
	}
	
	/**
	 * Rebuild index completely
	 */
	function rebuild() {
		$self = $this;
		$this->apply(function() use ($self) {
			$self->map = array();
			$self->inverse = array();
		
			$self->db->eachId(function($id) use ($self) {
				$self->updateTemp($id, $self->db->load($id));
			});

			$self->store();
		});
	}
	
	/**
	 * Apply a synchronized operation on the index
	 */
	function apply($func) {
		$self = $this;
		$this->db->synchronized($this->name . '_index', function() use ($self, $func) {
			$self->restore();
			$func();
		});
	}
	
	/**
	 * Load index
	 */
	function restore() {
		$index = $this->db->load('_' . $this->name . '_index');
		
		$this->map = @$index['map'];
		$this->inverse = @$index['inverse'];
		
		if(empty($this->map))
			$this->map = array();
		if(empty($this->inverse))
			$this->inverse = array();
			
		$this->sort(); // json does not guarantee sorted storage
	}
	
	/**
	 * Save index
	 */
	function store() {
		$this->sort(); // keep map sorted by key
		
		$this->db->save('_' . $this->name . '_index', array(
			'name' => $this->name,
			'type' => 'index',
			'map' => $this->map,
			'inverse' => $this->inverse
		));
	}
	
	/**
	 * Compute index key(s) of data
	 */
	function keys($data) {
		$keys = $this->keyFunc;
		if(!is_string($keys) && is_callable($keys))
			return $keys($data);
		return @$data[$keys];
	}
	
	/**
	 * Get name of index
	 */
	function getName() {
		return $this->name;
	}
	
	/**
	 * Sort index by key
	 */
	protected function sort() {
		if(is_callable($this->compare))
			return uksort($this->map, $this->compare);
		return ksort($this->map);
	}
	
	protected $db;
	protected $name;
	protected $keyFunc;
	protected $compare;
	protected $map;
	protected $inverse;
}
