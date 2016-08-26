<?php

namespace MicroDB;

use Exception;
use InvalidArgumentException;

/**
 * A file-based JSON object database
 */
class Database
{
    /**
     * @var string
     */
    protected $path;

    /**
     * @var int
     */
    protected $mode;
    
    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var array
     */
    private $handlers = [];

    /**
     * @var array
     */
    private $locks = [];

    /**
     * Constructor
     *
     * @param string $path
     * @param int $mode
     */
    public function __construct($path, $mode = 0775, $options = [])
    {
        $path = (string)rtrim($path, '/') . '/';

        $this->path = $path;
        $this->mode = $mode;
        $this->options = $options;

        $this->makeDir($this->path, $this->mode);
    }

    /**
     * Create an item with auto incrementing id
     *
     * @param array $data
     * @return array
     * @throws Exception if synchronization failed
     */
    public function create(array $data = [])
    {

        $self = $this;

        return $this->synchronized('_auto', function () use ($self, $data) {

            $next = 1;

            if ($self->exists('_auto')) {

                $next = $self->load('_auto', 'next');

            }

            $self->save('_auto', array('next' => $next + 1));
            $self->save($next, $data);

            return $next;
        });

    }

    /**
     * Save data to database
     *
     * @param mixed $id
     * @param mixed $data
     * @return void
     * @throws Exception if synchronization failed
     */
    public function save($id, $data)
    {

        $self = $this;
        $event = new Event($this, $id, $data);

        return $this->synchronized($id, function () use ($self, $event) {

            $self->triggerId('beforeSave', $event);

            $self->put($this->path . $this->generateSubTree($event->id), json_encode($event->data));

            $self->triggerId('saved', $event);

        });

    }

    /**
     * Load data from database
     *
     * @param $id
     * @param null $key
     * @return array|mixed|null
     */
    public function load($id, $key = null)
    {
        if (is_array($id)) {
            $results = [];
            foreach ($id as $i) {
                $results[$i] = $this->load($i);
            }
            return $results;
        }

        if (!$this->validId($id)) {
            return null;
        }

        $event = new Event($this, $id);

        $this->triggerId('beforeLoad', $event);

        $event->data = json_decode($this->get($this->path . $this->generateSubTree($event->id)), true);

        $this->triggerId('loaded', $event);

        if (isset($key)) {
            return @$event->data[$key];
        }
        return $event->data;
    }

    /**
     * Delete data from database
     * @param mixed $id
     * @return array
     * @throws Exception if synchronization failed
     */
    public function delete($id)
    {
        if (is_array($id)) {
            $results = [];
            foreach ($id as $i) {
                $results[$i] = $this->delete($i);
            }
            return $results;
        }

        $self = $this;
        $event = new Event($this, $id);

        return $this->synchronized($id, function () use ($self, $event) {
            $self->triggerId('beforeDelete', $event);

            $self->erase($this->path . $this->generateSubTree($event->id));

            $self->triggerId('deleted', $event);
        });
    }

    /**
     * Find data matching key-value map or callback
     *
     * @param array $where
     * @param bool $first
     * @return array
     */
    public function find($where = [], $first = false)
    {
        $results = [];

        if (!is_string($where) && is_callable($where)) {
            $this->eachId(function ($id) use (&$results, $where, $first) {
                $data = $this->load($id);
                if ($where($data)) {
                    if ($first) {
                        $results = $data;
                        return true;
                    }
                    $results[$id] = $data;
                }
            });
        } else {
            $this->eachId(function ($id) use (&$results, $where, $first) {
                $match = true;
                $data = $this->load($id);
                foreach ($where as $key => $value) {
                    if (@$data[$key] !== $value) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    if ($first) {
                        $results = $data;
                        return true;
                    }
                    $results[$id] = $data;
                }
            });
        }

        return $results;
    }

    /**
     * Find first item key-value map or callback
     *
     * @param null $where
     * @return array
     */
    public function first($where = null)
    {
        return $this->find($where, true);
    }

    /**
     * Checks whether an id exists
     *
     * @param mixed $id
     * @return bool
     */
    public function exists($id)
    {
        return is_file($this->path . $id);
    }

    /**
     * Triggers "repair" event.
     * On this event, applications should repair inconsistencies in the
     * database, e.g. rebuild indices.
     *
     * @return void
     */
    public function repair()
    {
        $this->trigger('repair');
    }

    /**
     * Call a function for each id in the database
     *
     * @param callable $func
     * @return void
     */
    public function eachId($func)
    {
        $res = opendir($this->path);

        while (($id = readdir($res)) !== false) {
            if ($id == "." || $id == ".." || $id{0} == '_') {
                continue;
            }

            if ($func($id)) {
                return;
            }
        }
    }

    /**
     * Trigger an event only if id is not hidden
     *
     * @param $type
     * @param mixed $event
     * @return Database
     */
    protected function triggerId($type, $event)
    {
        if (is_object($event) && !$this->hidden($event->id)) {
            call_user_func_array(array($this, 'trigger'), func_get_args());
        }
        return $this;
    }

    /**
     * Is this id hidden, i.e. no events should be triggered?
     * Hidden ids start with an underscore
     *
     * @param mixed $id
     * @return bool
     */
    public function hidden($id)
    {
        return $id{0} == '_';
    }

    /**
     * Check if id is valid
     *
     * @param mixed $id
     * @return bool
     */
    public function validId($id)
    {
        $id = (string)$id;
        return $id !== '.' && $id !== '..' && preg_match('#^[^/?*:;{}\\\\]+$#', $id);
    }

    /**
     * Call a function in a mutually exclusive way, locking on files
     * A process will only block other processes and never block itself,
     * so you can safely nest synchronized operations.
     *
     * @param array $locks
     * @param callable $func
     * @return mixed
     * @throws Exception if synchronization failed
     */
    public function synchronized($locks, $func)
    {

        if (!is_array($locks)) {
            $locks = array($locks);
        }

        // remove already acquired locks
        $acquire = [];
        foreach ($locks as $lock) {

            if (!isset($this->locks[$lock])) {

                $acquire[] = $lock;

            }

        }
        $locks = $acquire;

        array_unique($locks);

        $handles = [];

        try {

            // acquire each lock
            foreach ($locks as $lock) {

                $file = $this->path . '_' . $lock . '_lock';
                $handle = fopen($file, 'w');

                if ($handle && flock($handle, LOCK_EX)) {

                    $this->locks[$lock] = true;
                    $handles[$lock] = $handle;

                } else {

                    throw new Exception('Unable to synchronize over ' . $lock);

                }

            }

            $return = $func();

            // release
            foreach ($locks as $lock) {

                unset($this->locks[$lock]);

                if (isset($handles[$lock])) {

                    flock($handles[$lock], LOCK_UN);
                    fclose($handles[$lock]);

                }

            }

            return $return;

        } catch (Exception $e) {

            // release
            foreach ($locks as $lock) {

                unset($this->locks[$lock]);

                if (isset($handles[$lock])) {

                    flock($handles[$lock], LOCK_UN);
                    fclose($handles[$lock]);

                }

            }

            throw $e;
        }

    }

    /**
     * Put file contents
     *
     * @param string $file
     * @param mixed $data
     * @return bool|void
     * @internal param bool $mode
     */
    protected function put($file, $data)
    {
        // don't overwrite if unchanged, just touch
        if (is_file($file) && file_get_contents($file) === $data) {
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
     *
     * @param string $file
     * @return null|string
     */
    protected function get($file)
    {
        if (!is_file($file)) {
            return null;
        }

        return file_get_contents($file);
    }

    /**
     * Remove file from filesystem
     *
     * @param string $file
     * @return bool
     */
    protected function erase($file)
    {
        return unlink($file);
    }

    /**
     * Get data path
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Bind a handler to an event, with given priority.
     * Higher priority handlers will be executed earlier.
     *
     * @param array $event
     * @param callable $handler
     * @param int $priority
     * @return Database
     */
    public function on($event, $handler, $priority = 0)
    {
        $events = $this->splitEvents($event);

        foreach ($events as $event) {
            if (!is_callable($handler)) {
                throw new InvalidArgumentException('Handler must be callable');
            }

            if (!isset($this->handlers[$event])) {
                $this->handlers[$event] = [];
            }

            if (!isset($this->handlers[$event][$priority])) {
                $this->handlers[$event][$priority] = [];

                // keep handlers sorted by priority
                krsort($this->handlers[$event]);
            }

            $this->handlers[$event][$priority][] = $handler;
        }

        return $this;
    }

    /**
     * Unbind a handler on one, multiple or all events
     *
     * @param string|array $event Event keys, comma separated
     * @param callable $handler
     * @return Database
     */
    public function off($event, $handler = null)
    {
        if (!is_string($event) && is_callable($event)) {
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
     *
     * @param string|array Event keys, whitespace/comma separated
     * @param mixed $args
     * @return Database
     */
    public function trigger($event, $args = null)
    {
        $args = func_get_args();
        array_shift($args);
        $args[] = $event;

        if (isset($this->handlers[$event])) {
            foreach ($this->handlers[$event] as $priority => $handlers) {
                foreach ($handlers as $handler) {
                    call_user_func_array($handler, $args);
                }
            }
        }

        return $this;
    }

    /**
     * Split event keys by whitespace and/or comma
     *
     * @param array $events
     * @return array
     */
    protected function splitEvents($events)
    {
        if (is_array($events)) {
            return $events;
        }

        return preg_split('([\s,]+)', $events);
    }

    /**
     * Make a given directory with given chmod's
     *
     * @param string $path
     * @param int $mode
     * @return void
     */
    private function makeDir($path, $mode)
    {
        if (!is_dir($path)) {
            mkdir($path, $mode, true);
        }
    }
    
    /**
    * Generates the file subtree if required
    * @param integer $id
    * 
    * @return string
    */
    function generateSubTree ($id)
    {
    	if (!isset($this->options["subtree"])) {
            return $id;
    	}
    	
    	$i = 0;
    	$idLength = strlen($id);
    	$path = "";
    	
    	while ($i < $this->options["subtree"] && $i < $idLength)
    	{
            $path .= substr($id, $i, 1) . "/";
            $i++;
    	}
    	
    	// ensure that this path exists
    	$this->makeDir($path, $this->mode);
    	
    	$path .= $id;
    	
    	return $path;
    }
}
