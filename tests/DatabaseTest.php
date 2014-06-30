<?php

require_once 'vendor/autoload.php';

class DatabaseTest extends PHPUnit_Framework_TestCase {
	
	private static $db;
	private static $guidIndex;
	
	static function setUpBeforeClass() {
		@mkdir('tests/data', 0644); // DON'T put this in create
		
		// create db
		self::$db = new \MicroDB\Database('tests/data');
		
		// create index
		self::$guidIndex = new \MicroDB\Index(self::$db, 'guid', 'guid');
		
		// random delay for concurrent testing
		usleep(rand(0, 1000000));
	}
	
	static function tearDownAfterClass() {
		// delay for concurrent testing
		sleep(1);
		
		// remove all data files
		$files = @scandir('tests/data');
		if($files) {
			$files = array_slice($files, 2);
			foreach($files as $file) {
				@unlink('tests/data/'.$file);
			}
			
			@rmdir('tests/data');
		}
	}
	
	static function guid() {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
	
	function testCreate() {
		self::$db->create();
	}
	
	function testBasics() {
		$g = self::guid();
		$t1 = array('guid' => $g, 'name' => 'foo');
		$t2 = array('guid' => $g, 'name' => 'bar');

		$id1 = self::$db->create();
		self::$db->save($id1, $t1);
		
		$id2 = self::$db->create($t2);
		
		$this->assertEquals($t1, self::$db->load($id1));
		$this->assertEquals($t2, self::$db->load($id2));
	}
	
	function testFind() {
		$g = self::guid();
		$t1 = array('guid' => $g, 'name' => 'foo');
		$t2 = array('guid' => $g, 'name' => 'bar');

		$id1 = self::$db->create($t1);
		$id2 = self::$db->create($t2);
		
		$a = self::$db->find(array('guid' => $g));
		$ex = array($id1 => $t1, $id2 => $t2);
		$this->assertEquals($ex, $a);
	}
	
	function testDelete() {
		$g = self::guid();
		$id = self::$db->create(array('guid' => $g));
		self::$db->delete($id);
		$this->assertEquals(null, self::$db->load($id));
	}
	
	function testIndex() {
		$g1 = self::guid();
		$g2 = self::guid();
		
		self::$db->create(array('guid' => $g1));
		self::$db->create(array('guid' => array($g1)));
		self::$db->create(array('guid' => $g2));
		self::$db->create(array('guid' => array($g2)));
		self::$db->create(array('guid' => array($g1, $g2)));
		
		$this->assertEquals(3, count(self::$guidIndex->find($g2)));
	}
	
	function testIndexSlice() {
		$g = self::guid();
		
		$tempIndex = new \MicroDB\Index(self::$db, $g, 'name');
		
		$id1 = self::$db->create(array('name' => 'foo'));
		$id2 = self::$db->create(array('name' => 'bar'));
		$id3 = self::$db->create(array('name' => 'baz'));
		
		$a = $tempIndex->loadSlice(1, 2);
		$ex = array(
			$id3 => array('name' => 'baz'),
			$id1 => array('name' => 'foo')
		);
		$this->assertEquals($ex, $a);
	}
	
	function testDeleteIndex() {
		$g = self::guid();
		$id = self::$db->create(array('guid' => $g));
		self::$db->delete($id);
		$a = self::$guidIndex->find($g);
		
		$this->assertTrue(empty($a));
	}
	
	function testRepair() {
		self::$db->repair();
	}
	
	function testEvents() {
		$a = array();
		
		$f = function($id, $data, $event = null) use (&$a) {
			if(!isset($event))
				$event = $data;
			$a[] = $event;
		};
		
		self::$db->on('beforeSave', $f);
		self::$db->on('saved', $f);
		self::$db->on(array('beforeLoad', 'loaded'), $f);
		self::$db->on('beforeDelete deleted', $f);
		
		self::$db->save('events', array('foo' => 'bar'));
		self::$db->load('events');
		self::$db->delete('events');
		
		$ex = array('beforeSave', 'saved', 'beforeLoad', 'loaded', 'beforeDelete', 'deleted');
		$this->assertEquals($ex, $a);
	}
	
	function testSynchronized() {
		$a = array();

		self::$db->synchronized('sync', function() use (&$a) {
			$a[] = 'called';
			
			self::$db->eachId(function($id) {
				if($id == '_sync_lock')
					$a[] = 'each';
			});
			
			$file = self::$db->getPath().'_sync_lock';
			$handle = fopen($file, 'w+');
			if ($handle && flock($handle, LOCK_EX|LOCK_NB, $wouldblock)) {
				flock($handle, LOCK_UN);
				fclose($handle);
			} else {
				$a[] = 'locked';
			}
			
			
			if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
				// this fails on windows 8
				// whereas LOCK_NB appears to be supported, at least by windows 8
				// $this->assertEquals(1, $wouldblock);
			} else {
				$this->assertEquals(1, $wouldblock);
			}
			
			self::$db->synchronized('sync', function() use (&$a) {
				$a[] = 'nested';
			});
		});
		
		$ex = array('called', 'locked', 'nested');
		$this->assertEquals($ex, $a);
	}
}
