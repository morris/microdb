<?php

namespace MicroDB;

/**
 * A cache for data loading
 */
class Cache
{
    /**
     * @var Database
     */
    private $db;

    /**
     * @var bool
     */
    private $complete = false;

    /**
     * @var array
     */
    private $map = array();

    /**
     * Constructor
     *
     * @param Database $db
     */
    function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Load a possibly cached item
     *
     * @param string $id
     * @return array|mixed|null
     */
    function load($id)
    {
        if (is_array($id)) {
            $results = array();
            foreach ($id as $i) {
                $results[$i] = $this->load($i);
            }
            return $results;
        }

        if (isset($this->map[$id])) {
            return $this->map[$id];
        } else {
            $data = $this->db->load($id);
            $this->map[$id] = $data;
            return $data;
        }
    }

    /**
     * Execute a function on each item (id, data)
     *
     * @param callable $func
     */
    public function each($func)
    {
        if (!$this->complete) {
            $this->db->eachId(array($this, 'load'));
            $this->complete = true;
        }

        foreach ($this->map as $id => $data) {
            $func($id, $data);
        }
    }
}