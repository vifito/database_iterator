DatabaseIterator
=================

DatabaseIterator is a class that use new features of PHP5 (SPL, Iterators, magic methods, ...) to simplify process of iteration with a database. Using a DatabaseIterator, you can work with your database the same mode that you work with arrays.


Sample usage:

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




http://www.vifito.eu/database-transversal-api.html