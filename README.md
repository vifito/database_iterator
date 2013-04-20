DatabaseIterator
=================

DatabaseIterator is a class that use new features of PHP5 (SPL, Iterators, magic methods, ...) to simplify process of iteration with a database. Using a DatabaseIterator, you can work with your database the same mode that you work with arrays.


Quick sample usage:

```php
$dbIt = new DatabaseIterator($conn);

foreach($dbIt as $table) { // loop tables
    $columns = $table->getColumns(); // get columns

    foreach($table as $row) { // loop rows
        echo $row; // call to toString() magic method
 
        // or loop columns
        foreach($columns as $col) {
            echo $row->{$col->name} . PHP_EOL;
        }
    }
}
```

More detailed documentation:


Create an ADOdb connection (ADOConnection) and bind to a DatabaseIterator object to list all tables:

```php
require('../libs/adodb5/adodb.inc.php');

$conn = ADONewConnection('mysql');
$conn->PConnect('localhost', 'root', 'pass', 'database');

$databaseIt = new DatabaseIterator($conn);
 
foreach($databaseIt as $table) {
    echo $table->name . PHP_EOL;
}
```

---


Retrieving the syntax for CREATE:

```php
echo $databaseIt['contents']->getCreateTable();
echo $databaseIt->from('contents')->getCreateTable();
```


Inserting data:

```php
for($i=0; $i<10; $i++) {
    $std = new stdClass();
    $std->id = ($i+1);
    $std->title = 'Title ' . $i;
    $std->description = 'Lorem ipsum dolor sit amet, consectetur adipisicing elit.';

    $databaseIt['events']->insert($std);
}

// other syntax
$row = $databaseIt['events']->newRow();
$row->insert($std);
```


Updating/deleting a row:

```php
$databaseIt['events'][0]->summary = 'Hello';
$databaseIt['events'][0]->update();
$databaseIt['events'][1]->delete();
```


Set a internal SQL and list rows using a each loop:

```php
$databaseIt['contents']->select('pk_content, title')
                       ->where('title REGEXP ""');
                       
$foo = create_function('$item', 'echo $item->title . PHP_EOL;');
$databaseIt['contents']->each($foo);
```


Use ADOdb transactions to rollback or commit operations:

```php
$conn->BeginTrans();

foreach($rows as $row) {
    $row->permalink = preg_replace('/[^a-zA-Z0-9_\-\/\.]/', '', $row->permalink);
    $row->update();
}

$conn->RollbackTrans(); // rollback changes
//or $conn->CommitTrans(); if all OK
```

More documentation:
- [Docs on vifito.eu](http://www.vifito.eu/database-transversal-api.html)
- [ADOdb](http://adodb.sourceforge.net/)
- [Sample usage](http://www.vifito.eu/codigo-fonte/2-php/6-correxindo-as-slashes-inseridas-por-magicquote-con-databaseiterator.html)