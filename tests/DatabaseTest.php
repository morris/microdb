<?php

require_once 'vendor/autoload.php';

class DatabaseTest extends PHPUnit_Framework_TestCase {
	
	private static $db;
	private static $typeIndex;
	private static $titleIndex;
	private static $tagsIndex;
	
	static function create() {
		// create db
		self::$db = new \MicroDB\Database('tests/data');
		
		// create indices
		self::$typeIndex = new \MicroDB\Index(self::$db, 'type', 'type');
		
		self::$titleIndex = new \MicroDB\Index(self::$db, 'title', 'title');
		
		self::$tagsIndex = new \MicroDB\Index(
			self::$db,
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
			self::$db->save('user'.$i, array(
				'id' => $i,
				'name' => 'User '.$i,
				'type' => 'user'
			));
		}
		
		// create posts
		for($i = 1; $i <= 12; ++$i) {
			self::$db->save('post'.$i, array(
				'id' => $i,
				'title' => 'Lorem ipsum ' . ($i % 4),
				'tags' => array_rand($tags, rand(2, count($tags) - 1)),
				'type' => 'post',
				'author' => 'user'.rand(1, 3)
			));
		}
	}
	
	static function destroy() {
		// remove all data files
		$files = array_slice(scandir('tests/data'), 2);
		foreach($files as $file) {
			unlink('tests/data/'.$file);
		}
	}
	
	static function reset() {
		self::destroy();
		self::create();
	}
	
	static function setUpBeforeClass() {
		mkdir('tests/data', 0644); // DON'T put this in create
		self::create();
	}
	
	static function tearDownAfterClass() {
		self::destroy();
		rmdir('tests/data'); // DON'T put this in destroy
	}
	
	function testLoad() {
		$t = self::$db->load('user1');
		$this->assertEquals('user', $t['type']);
    }
    
    function testFind() {
		$users = self::$db->find(array('type' => 'user'));
		$this->assertEquals(4, count($users));
	}
	
	function testDelete() {
		self::$db->delete('post2');
		$post = self::$db->load('post2');
		
		$this->assertEquals(null, $post);
		
		self::reset();
	}
	
	function testIndex() {
		$a = self::$typeIndex->find('post');
		$b = self::$typeIndex->find('user');
		
		$this->assertEquals(12, count($a));
		$this->assertEquals(4, count($b));
	}
	
	function testIndex2() {
		$a = self::$titleIndex->find(function($title) {
			return substr($title, -1) === '0';
		});
		
		$this->assertEquals(3, count($a));
	}
	
	function testIndexSlice() {
		$a = self::$titleIndex->slice(3, 3);
		
		$ex = array('post3', 'post7', 'post11');
		$this->assertEquals($ex, $a);
	}
	
	function testDeleteIndex() {
		self::$db->delete('post2');
		$posts = self::$typeIndex->find('post');
		
		$this->assertEquals(false, isset($posts['post2']));
		
		self::reset();
	}
	
	function testRepair() {
		self::$db->repair();
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
		
		self::$db->on('beforeSave', $f('beforeSave'));
		self::$db->on('saved', $f('saved'));
		self::$db->on('beforeLoad', $f('beforeLoad'));
		self::$db->on('loaded', $f('loaded'));
		self::$db->on('beforeDelete', $f('beforeDelete'));
		self::$db->on('deleted', $f('deleted'));
		
		self::$db->save('events', array('foo' => 'bar'));
		self::$db->load('events');
		self::$db->delete('events');
		
		$ex = array('beforeSave', 'saved', 'beforeLoad', 'loaded', 'beforeDelete', 'deleted');
		$this->assertEquals($ex, $a);
	}
	
	function offtestNextFile() {
		touch('tests/data/foo1');
		touch('tests/data/foo2');
		$this->assertEquals('foo3', self::$db->nextFile('foo?'));
		unlink('tests/data/foo1');
		unlink('tests/data/foo2');
	}
}
