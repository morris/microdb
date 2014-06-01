# MicroDB

MicroDB is a file-based JSON object database, written in PHP.

## Usage

```php
$db = new \MicroDB\Database('data'); // data directory

// save an item
$db->save('user1', array(
	'id' => 1,
	'type' => 'user',
	'name' => 'Foo Bar',
	'email' => 'foo@bar.de'
));

// load an item
$user = $db->load('user1');

// find items
$users = $db->find(array('type' => 'user'));

// delete an item
$db->delete('user1');
```

## Features

- Stores JSON objects as plain files
- Arbitrary indices using custom key functions
- Listen to database operations through events

## Requirements

- PHP 5.3+


## Installation

The composer package name is `morris/microdb`. You can also download or fork the repository.


## Documentation

See `doc/index.html`.


## Tests

Before running the tests you must run `composer update` in the microdb
directory. This will install development dependencies like PHPUnit. Run
the tests with `vendor/bin/phpunit tests`.


## License

MicroDB is licensed under the MIT License. See `LICENSE.md` for details.
