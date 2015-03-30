<?php

namespace MicroDB;

/**
 * A container for database events
 */
class Event
{

    /**
     * @var Database
     */
    private $db;

    /**
     * @var string
     */
    public $id;

    /**
     * @var null
     */
    public $data;

    /**
     * Constructor
     *
     * @param $db Database
     * @param $id string
     * @param null $data
     */
    function __construct($db, $id, $data = null)
    {
        $this->db   = $db;
        $this->id   = $id;
        $this->data = $data;
    }

}