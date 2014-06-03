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
		$this->db->on('saved', array($this, 'update'));
		$this->db->on('deleted', array($this, 'delete'));
		$this->db->on('repair', array($this, 'rebuild'));
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
	 * Find ids that match a key/callback
	 */
	function findIds($key) {
		$this->load();
		
		$ids = array();
		
		if(is_callable($key)) {
			foreach($this->map as $k => $i) {
				if($key($k))
					$ids = array_merge($ids, $i);
			}
		} else if(isset($this->map[$key])) {
			$ids = array_merge($ids, $this->map[$key]);
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
		
		// compute new keys
		$keys = $this->keys($data);
		
		// skip if key is undefined
		if($keys === null || $keys === false)
			return;
		if(!is_array($keys))
			$keys = array($keys);
		
		$save = false;
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
			$save = true;
		}
		
		// remove remaining invalid entries
		if(!empty($oldKeys)) {
			foreach($oldKeys as $key => $offset) {
				array_splice($this->map[$key], $offset, 1);
				unset($this->inverse[$id][$key]);
				$save = true;
			}
		}
		
		if($save)
			$this->save();
	}
	
	/**
	 * Rebuild index completely
	 */
	function rebuild() {
		$this->rebuilding = true;
		
		$this->map = array();
		$this->inverse = array();
		
		$index = $this;
		$this->db->eachId(function($id) use ($index) {
			$index->update($id, $index->db->load($id));
		});
		
		$this->rebuilding = false;
		$this->save();
	}
	
	/**
	 * Delete item from index
	 */
	function delete($id) {
		$this->load();

		$save = false;
		$oldKeys = @$this->inverse[$id];
		
		// remove all old entries
		if(!empty($oldKeys)) {
			foreach($oldKeys as $key => $offset) {
				array_splice($this->map[$key], $offset, 1);
				unset($this->inverse[$id][$key]);
				$save = true;
			}
		}
		
		if($save)
			$this->save();
	}
	
	/**
	 * Load index
	 */
	function load() {
		if(!isset($this->map)) {
			$index = $this->db->load('index_'.$this->name);
			
			$this->map = @$index['map'];
			$this->inverse = @$index['inverse'];
			
			if(empty($this->map))
				$this->map = array();
			if(empty($this->inverse))
				$this->inverse = array();
		}
	}
	
	/**
	 * Save index
	 */
	function save() {
		if($this->rebuilding)
			return;
		
		$this->db->save('index_'.$this->name, array(
			'name' => $this->name,
			'type' => 'index',
			'map' => $this->map,
			'inverse' => $this->inverse
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
	function keys($data) {
		$keys = $this->keyFunc;
		if(is_callable($keys))
			return $keys($data);
		return @$data[$keys];
	}
	
	protected $db;
	protected $name;
	protected $keyFunc;
	protected $map;
	protected $inverse;
	protected $rebuilding = false;
}