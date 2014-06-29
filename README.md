# MicroDB

MicroDB is a minimalistic file-based JSON object database written in
PHP.

## Usage

```php
$db = new \MicroDB\Database('data'); // data directory

// create an item
// id is an auto incrementing integer
$id = $db->create(array(
	'type' => 'post',
	'title' => 'Lorem ipsum',
	'body' => 'At vero eos et accusam et justo duo dolores et ea rebum.'
));

// load an item
$post = $db->load($id);

// save an item
$post['tags'] = array('lorem', 'ipsum');
$db->save($id, $post);

// find items
$posts = $db->find(function($post) {
	return is_array(@$post['tags']) && in_array('ipsum', @$post['tags']);
});

foreach($posts as $id => $post) {
	print_r($post);
}

// delete an item
$db->delete($id);
```

## Features

- Stores JSON objects as plain files
- Arbitrary indices using custom key functions
- Listen to database operations through events
- Synchronize arbitrary operations

## Requirements

- PHP 5.3+


## Installation

The composer package name is `morris/microdb`. You can also download or
fork the repository.


## License

MicroDB is licensed under the MIT License. See `LICENSE.md` for details.


## Documentation

For more documentation, examples and API, see `doc/index.html`.
 
