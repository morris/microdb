<?php

require_once 'vendor/autoload.php';

class DatabaseTest extends PHPUnit_Framework_TestCase {
	
	private static function randomString($length = 10) {
		return substr(md5(rand()), 0, $length);
	}
	
	static $db;
	static $typeIndex;
	static $nameIndex;

	static function setUpBeforeClass() {
		@mkdir('tests/data', 0644, true);
		
		self::$db = new \MicroDB\Database('tests/data');
		
		self::$typeIndex = new \MicroDB\Index(self::$db, 'type', 'type');
		
		self::$nameIndex = new \MicroDB\Index(
			self::$db,
			'name',
			function($data) {
				if(@$data['type'] === 'person')
					return $data['name'];
			}
		);
		
		for($i = 0; $i < 100; ++$i) {
			self::$db->save($i, array(
				'name' => self::randomString() . ($i % 2),
				'type' => 'person'
			));
		}
		
		for($i = 0; $i < 100; ++$i) {
			self::$db->save('a'.$i, array(
				'name' => self::randomString(),
				'type' => 'alien'
			));
		}
	}
	
	static function tearDownAfterClass() {
		// remove all data files
		$files = self::$db->scandir('tests/data');
		foreach($files as $file) {
			unlink('tests/data/'.$file);
		}
		rmdir('tests/data');
	}
	
	function setUp() {
		
	}
	
	function tearDown() {
		
	}
	
	function testBasic() {
		$type = self::$db->load('index_type');
		$name = self::$db->load('index_name');
		
		$this->assertEquals('type', $type['name']);
		$this->assertEquals('name', $name['name']);
    }
    
    function testFind() {
		$people = self::$db->find(array('type' => 'person'));
		
		$this->assertEquals(100, count($people));
	}
	
	function testIndex() {
		$people = self::$typeIndex->find('person');
		$indices = self::$typeIndex->find('index');
		
		$this->assertEquals(100, count($people));
		$this->assertEquals(2, count($indices));
	}
	
	function testIndex2() {
		$zeroes = self::$nameIndex->find(function($name) {
			return substr($name, -1) === '0';
		});
		
		$this->assertEquals(50, count($zeroes));
	}
	
	function offtestNextFile() {
		touch('tests/data/foo1');
		touch('tests/data/foo2');
		$this->assertEquals('foo3', self::$db->nextFile('foo?'));
		unlink('tests/data/foo1');
		unlink('tests/data/foo2');
	}
}
