<?php

require_once 'vendor/autoload.php';

class DatabaseTest extends PHPUnit_Framework_TestCase {
	
	private static function randomString($length = 10) {
		return substr(md5(rand()), 0, $length);
	}
	
	private $db;
	private $typeIndex;
	private $titleIndex;
	private $tagsIndex;

	static function setUpBeforeClass() {
		mkdir('tests/data', 0644, true);

	}
	
	static function tearDownAfterClass() {
		rmdir('tests/data');
	}
	
	function setUp() {
		// create db
		$this->db = new \MicroDB\Database('tests/data');
		
		// create indices
		$this->typeIndex = new \MicroDB\Index($this->db, 'type', 'type');
		
		$this->titleIndex = new \MicroDB\Index($this->db, 'title', 'title');
		
		$this->tagsIndex = new \MicroDB\Index(
			$this->db,
			'tags',
			function($data) {
				if(@$data['type'] === 'post')
					return $data['tags'];
			}
		);
		
		// some tags
		$tags = array(
			'news' => 1,
			'weather' => 1,
			'programming' => 1,
			'php' => 1,
			'javascript' => 1,
			'microdb' => 1
		);
		
		// create users
		for($i = 1; $i <= 4; ++$i) {
			$this->db->save('user'.$i, array(
				'id' => $i,
				'name' => 'User '.$i,
				'type' => 'user'
			));
		}
		
		// create posts
		for($i = 1; $i <= 12; ++$i) {
			$this->db->save('post'.$i, array(
				'id' => $i,
				'title' => 'Lorem ipsum ' . ($i % 4),
				'tags' => array_rand($tags, rand(2, count($tags) - 1)),
				'type' => 'post',
				'author' => 'user'.rand(1, 3)
			));
		}
	}
	
	function tearDown() {
		// remove all data files
		$files = array_slice(scandir('tests/data'), 2);
		foreach($files as $file) {
			unlink('tests/data/'.$file);
		}
	}
	
	function testLoad() {
		$t = $this->db->load('user1');
		$this->assertEquals('user', $t['type']);
    }
    
    function testFind() {
		$users = $this->db->find(array('type' => 'user'));
		$this->assertEquals(4, count($users));
	}
	
	function testDelete() {
		$this->db->delete('post2');
		$post = $this->db->load('post2');
		
		$this->assertEquals(null, $post);
	}
	
	function testIndex() {
		$posts = $this->typeIndex->find('post');
		$users = $this->typeIndex->find('user');
		
		$this->assertEquals(12, count($posts));
		$this->assertEquals(4, count($users));
	}
	
	function testIndex2() {
		$zeroes = $this->titleIndex->find(function($title) {
			return substr($title, -1) === '0';
		});
		
		$this->assertEquals(3, count($zeroes));
	}
	
	function testDeleteIndex() {
		$this->db->delete('post2');
		$posts = $this->typeIndex->find('post');
		
		$this->assertEquals(false, isset($posts['post2']));
	}
	
	function testRepair() {
		$this->db->repair();
		$this->testIndex();
	}
	
	function testEvents() {
		$a = array();
		
		$f = function($event) use (&$a) {
			return function($id, $data = null) use ($event, &$a) {
				if($id === 'events')
					$a[] = $event;
			};
		};
		
		$this->db->on('beforeSave', $f('beforeSave'));
		$this->db->on('saved', $f('saved'));
		$this->db->on('beforeLoad', $f('beforeLoad'));
		$this->db->on('loaded', $f('loaded'));
		$this->db->on('beforeDelete', $f('beforeDelete'));
		$this->db->on('deleted', $f('deleted'));
		
		$this->db->save('events', array('foo' => 'bar'));
		$this->db->load('events');
		$this->db->delete('events');
		
		$ex = array('beforeSave', 'saved', 'beforeLoad', 'loaded', 'beforeDelete', 'deleted');
		$this->assertEquals($ex, $a);
	}
	
	function offtestNextFile() {
		touch('tests/data/foo1');
		touch('tests/data/foo2');
		$this->assertEquals('foo3', $this->db->nextFile('foo?'));
		unlink('tests/data/foo1');
		unlink('tests/data/foo2');
	}
}
