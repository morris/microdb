<?php

namespace MicroDB;

/**
 * Represents and manages an index on a database.
 * An index maps keys to ids where keys are computed from the item.
 */
class Index {

	/**
	 * Creates an index on a database with a name and an index key
	 * function. The index listens to database events to update itself.
	 */
	function __construct($db, $name, $keyFunc) {
		$this->db = $db;
		$this->name = $name;
		$this->keyFunc = $keyFunc;
		
		// register with database
		$index = $this;
		
		$this->db->on('saved', function($id, $data) use ($index) {
			$index->update($id, $data);
		});
		
		$this->db->on('deleted', function($id) use ($index) {
			$index->delete($id);
		});
	}
	
	/**
	 * Find and load items that match a key/callback
	 */
	function find($key) {
		return $this->db->load($this->findIds($key));
	}
	
	function first($key) {
		$all = $this->db->load($this->findIds($key));
		return @$all[0];
	}
	
	/**
	 * Find IDs that match a key/callback
	 */
	function findIds($key) {
		$this->load();
		
		$ids = array();
		
		if(is_callable($key)) {
			foreach($this->map as $k => $i) {
				if($key($k)) {
					$ids = array_merge($ids, $i);
				}
			}
		} else {
			if(isset($this->map[$key])) {
				$ids = array_merge($ids, $this->map[$key]);
			}
		}
		
		return $ids;
	}
	
	function firstId($key) {
		$all = $this->findIds($key);
		return @$all[0];
	}
	
	/**
	 * Update item in index
	 */
	function update($id, $data) {
		$this->load();
		
		$key = $this->key($data);
		
		if($key === null)
			return;
		
		// find old entry
		$old = false;
		$offset = false;
		
		foreach($this->map as $old => $array) {
			$offset = array_search($id, $array, true);
			if($offset !== false)
				break;
		}
		
		if($offset === false)
			$old = false;
		
		// if value has changed, we need to update the index
		
		if($old !== $key) {				
			if($offset !== false)
				array_splice($this->map[$old], $offset, 1);
			
			$this->map[$key][] = $id;
			$this->save();
		}
	}
	
	/**
	 * Delete item from index
	 */
	function delete($id) {
		$this->load();
		
		// find old entry
		$old = false;
		$offset = false;
		
		foreach($this->map as $old => $array) {
			$offset = array_search($id, $array, true);
			if($offset !== false)
				break;
		}
		
		// remove, if any
		if($offset !== false) {
			array_splice($this->map[$old], $offset, 1);
			$this->save();
		}
	}
	
	/**
	 * Load index
	 */
	function load() {
		if(!isset($this->map)) {
			$index = $this->db->load('index_'.$this->name);
			$this->map = @$index['map'];
			if(empty($this->map)) {
				$this->map = array();
			}
		}
	}
	
	/**
	 * Save index
	 */
	function save() {
		$this->db->save('index_'.$this->name, array(
			'name' => $this->name,
			'type' => 'index',
			'map' => $this->map
		));
	}
	
	/**
	 * Get name of index
	 */
	function getName() {
		return $this->name;
	}
	
	/**
	 * Compute index key of data
	 */
	function key($data) {
		$key = $this->keyFunc;
		if(is_callable($key))
			return $key($data);
		return @$data[$key];
	}
	
	protected $db;
	protected $name;
	protected $keyFunc;
	protected $validFunc;
	protected $unique;
	protected $map = array();
}