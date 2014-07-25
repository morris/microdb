<?php

namespace MicroDB;

/**
 * A container for database events
 */
class Event {
	
	/**
	 * Constructor
	 */
	function __construct($db, $id, $data = null) {
		$this->db = $db;
		$this->id = $id;
		$this->data = $data;
	}
	
	var $db;
	var $id;
	var $data;
}