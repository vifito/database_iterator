<?php
/**
 * DatabaseIterator, API to transversal manipulation of databases
 * 
 * @package DatabaseIterator
 * @version 0.1
 * @author Tomás Vilariño <vifito@vifito.eu>
 * @link http://vifito.eu
 * @copyright Copyright (c) 2009, vifito.eu
 */

/**
 * Class DatabaseIterator
 *
 * DatabaseIterator use TableIterator, RowIterator and ColumnIterator classes to
 * manipulate database using SPL features. This class implements Iterator and
 * ArrayAccess interfaces; and extends of ArrayObject
 * <code>
 * // $conn is an ADOConnection object
 * $dbIt = new DatabaseIterator($conn); 
 * foreach($dbIt as $table) { // loop tables
 *     foreach($table as $row) { // loop rows
 *         echo $row; // call to __toString() method
 *     }
 * }
 *
 * $dbIt['tablename']->select('field1, field2')->where('field1 LIKE "%number%"')->limit('0, 10');
 * foreach($dbIt['tablename'] as $row) {
 *    echo $row->field1;
 *    
 *    $row->field2 += 1;
 *    $row->update();
 * }
 * </code>
 * 
 * @package DatabaseIterator
 * @version 0.1
 * @see TableIterator
 * @see RowIterator
 * @see ColumnIterator
 */
class DatabaseIterator extends ArrayObject implements Iterator, ArrayAccess {
    /**
     * @var ADOConnection ADOdb connection instance
     * @access public
     */     
    public $conn = null;
    
    /**
     * @var string Database name
     * @access public
     */
    public $databasename = null;
    
    /**
     * @var array Internal array of TableIterator objects
     * @access private
     */ 
    private $_tables = null;
    
    /**
     * @param ADOConnection
     * @see DatabaseIterator::setConnection()
    */
    public function __construct($conn=null) {                
        if(!is_null($conn)) {
            $this->setConnection($conn);            
        }
    }
    
    /**
     * Set a internal connection instance
     *
     * @param ADOConnection $conn ADOConnection instance
    */ 
    public function setConnection($conn) {
        $this->databasename = $conn->database;
        
        $this->conn = $conn;        
        $this->conn->SetFetchMode(ADODB_FETCH_ASSOC);
        
        $this->loadTables();
        
        parent::__construct( $this->_tables );
    }
    
    /**
     * Set $_tables internal array with TableIterator objects
     * 
     * @see TableIterator
    */
    public function loadTables() {
        $tables = $this->conn->MetaTables('TABLES');
        
        $this->_tables = array();
        foreach($tables as $tablename) {
            $this->_tables[$tablename] = new TableIterator($tablename, $this);
        }
    }
    
    /**
     * Test if $_tables internal array has data, otherwise call to loadTables
     * 
     * @uses loadTables() 
    */
    protected function checkLoadTables() {
        if(is_null($this->_tables)) {
            $this->loadTables();
        }
    }
    
    /**
     * Select a table
     *
     * @return TableIterator Return a TableIterator object to method chaining
    */
    public function from($tablename) {
        $this->checkLoadTables();
        return isset($this->_tables[$tablename])? $this->_tables[$tablename]: null;
    }
    
    /**
     * Iterate across tables calling to $callback with table parameter
     * <code>
     * $foo = create_function('$table', 'echo $table->name;');
     * $dbIt->each($foo);
     * </code>
     *
     * @param string $callback Callback name or lambda function (create_function)
     * @link www.php.net/create_function
    */
    public function each($callback) {        
        $it = $this->getIterator();
        while($it->valid()) {
            $callback($it->current());
            $it->next();
        }
    }
    
    /* Implements Iterator */
    function rewind() {
        $this->checkLoadTables();
        reset($this->_tables);
    }

    function current() {
        $this->checkLoadTables();
        return current($this->_tables);
    }

    function key() {
        $this->checkLoadTables();
        return key($this->_tables);
    }

    function next() {
        $this->checkLoadTables();
        next($this->_tables);
    }

    function valid() {
        $this->checkLoadTables();
        return key($this->_tables) !== null;
    }
    
    /* Implements ArrayAccess */
    public function offsetSet($offset, $value) {
        $this->checkLoadTables();
        // Value must be an instance of TableIterator class
        $this->_tables[$offset] = $value;        
    }
    
    public function offsetExists($offset) {
        $this->checkLoadTables();
        return isset($this->_tables[$offset]);
    }
    
    public function offsetUnset($offset) {
        $this->checkLoadTables();
        unset($this->_tables[$offset]);
    }
    
    public function offsetGet($offset) {
        $this->checkLoadTables();
        return isset($this->_tables[$offset]) ? $this->_tables[$offset] : null;
    }    
    
}

/**
 * Class TableIterator
 *
 * DatabaseIterator use TableIterator, RowIterator and ColumnIterator classes to
 * manipulate database using SPL features. This class implements Iterator and
 * ArrayAccess interfaces; and extends of ArrayObject
 * <code>
 * // $conn is an ADOConnection object
 * $dbIt = new DatabaseIterator($conn);
 * $table = $dbIt['tablename']; // TableIterator
 * echo $table[0]->field1; // Access to field1 column of the first row
 * </code>
 * 
 * @package DatabaseIterator
 * @version 0.1
 * @see DatabaseIterator
 */
class TableIterator implements Iterator, ArrayAccess {
    /**
     * @var string
    */
    public $name = null;
    
    /**
     * @var ADOConnection
    */
    public $db   = null;
    
    /**
     * @var array
    */ 
    private $_rows = null;
    
    /**
     * @var array
    */ 
    private $_cols = null;    
        
    /**#@+
     * SQL params
     * 
     * @access private
     * @var string
     */
    private $_select   = null;
    private $_where    = null;
    private $_limit    = null;
    private $_order_by = null;
    /**#@-*/
    
    /**
     * Constructor
     *
     * @param string $name Name of table
     * @param ADOConnection $db ADOConnection instance
    */
    public function __construct($name, $db) {
        $this->name = $name; // tablename
        
        $this->setDatabase($db);
        
        // After set database object it's possible get columns
        $this->loadCols();
    }
    
    /**
     * Reset internal array of rows $_rows and reset conditions to SQL execution
     * if $conditions is true
     *
     * @param boolean $conditions If $conditions is true then reset SQL internal query
    */
    public function init($conditions=false) {
        if($conditions) {
            $this->_select   = '*';
            $this->_where    = null;
            $this->_limit    = null;
            $this->_order_by = null;
        }
        
        $this->_rows = null;
    }
    
    /**
     * Set database object (ADOConnection)
     *
     * @param ADOConnection $db 
    */
    public function setDatabase($db) {
        $this->db = $db;
    }
    
    /**
     * Iterate across rows calling to $callback with row parameter
     * <code>
     * $foo = create_function('$row', 'echo $row->field1;');
     * $dbIt['tablename']->each($foo);
     * </code>
     *
     * @param string $callback Callback name or lambda function (create_function)
     * @link www.php.net/create_function
    */
    public function each($callback) {        
        $it = $this->getIterator();
        
        while($it->valid()) {
            $callback($it->current());
            $it->next();
        }
    }
    
    /**
     * Return the number of rows in this table. This method don't use "where"
     * conditions to recover total rows number.
     *
     * @uses TableIterator::$db
     * @return int Total rows for table
     */
    public function total() {
        $sql = 'SELECT COUNT(*) FROM `'.$this->name.'`';
        $total = $this->db->conn->GetOne($sql);
        
        return intval($total);
    }
    
    /**
     * Set select parameter to internal SQL
     * <code>
     * $rows = $dbIt['tablename']->select('pk_field, content')->where('content LIKE "Nothing"')->execute();
     * </code>
     *
     * @param string $cols Select sentence to SQL query
     * @return RowIterator Return $this reference to perform chaining method
    */
    public function select($cols='*') {
        $this->_select = $cols;
        return $this;
    }

    /**
     * Set where parameter to internal SQL
     *
     * @param string $where Where sentence to SQL query
     * @return RowIterator Return $this reference to perform chaining method
    */    
    public function where($where) {
        $this->_where = $where;
        return $this;
    }
    
    /**
     * Set limit parameter to internal SQL
     *
     * @param string $limit Limit sentence to SQL query
     * @return RowIterator Return $this reference to perform chaining method
    */    
    public function limit($limit) {
        $this->_limit = $limit;
        return $this;
    }
    
    /**
     * Set "order by" parameter to internal SQL
     *
     * @param string $order_by "Order by" sentence to SQL query
     * @return RowIterator Return $this reference to perform chaining method
    */    
    public function order_by($order_by) {
        $this->_order_by = $order_by;
        return $this;
    }
    
    /**
     * Count internal rows
     *
     * @return integer Return total rows into $_rows internal array
    */
    public function length() {
        return count($this->_rows);
    }
    
    /**
     * Perform SQL query and return a Iterator object
     *
     * @uses TableIterator::getIterator()
     * @return ArrayIterator
    */
    public function execute() {
        $sql = $this->_buildSQL();
        
        $rs = $this->db->conn->Execute($sql);
        $this->_rows = array();
        if($rs !== false) {
            while(!$rs->EOF) {
                // FIXME: dependency injection
                $rowIt = new RowIterator($this);
                $rowIt->load($rs->fields);
                
                $this->_rows[] = $rowIt;
                
                $rs->MoveNext();
            }
        }
                
        return( $this->getIterator() );
    }
    
    /**
     * Make a ArrayObject with internal array of rows and return the
     * ArrayIterator 
     *
     * @see TableIterator::execute()
     * @return ArrayIterator
    */
    public function getIterator() {
        if(is_null($this->_rows)) {
            $this->loadRows();
        }
        
        $obj = new ArrayObject($this->_rows);
        return $obj->getIterator();
    }
    
    /**
     * Load rows, perform a call to execute method
     *
     * @uses TableIterator::execute()
    */
    public function loadRows() {
        $this->execute();                
    }
    
    /**
     * Test if rows was loaded, otherwise try load rows
     * 
     * @uses TableIterator::loadRows()
    */
    private function checkLoadRows() {
        if(is_null($this->_rows)) {
            $this->loadRows();
        }
    }
    
    /**
     * Load columns into $_cols internal array
     *
     * @see TableIterator::getColumns()
    */
    private function loadCols() {
        $cols = $this->db->conn->MetaColumns( $this->name );
        foreach($cols as $col) {
            $colIt = new ColumnIterator($this);
            $colIt->load( $col );
            
            $this->_cols[ $colIt->name ] = $colIt;
        }
    }
    
    /**
     * Return columns for this table
     *
     * @return array Return an array of ColumnIterator
    */
    public function getColumns() {
        return $this->_cols;
    }
    
    /**
     * Get primary keys for this table
     *
     * @return array Return array with primary keys ('columnName' => ColumnIterator object)
    */
    public function getPrimaryKeys() {
        if(is_null($this->_cols)) {            
            return null;
        }
        
        $pk = array();
        foreach($this->_cols as $col) {
            if( $col->isPK() ) {
                $pk[ $col->name ] = $col;
            }
        }
        
        return $pk;
    }
    
    /**
     * Get SQL syntax of CREATE TABLE sentence for this table
     *
     * @return string Return the CREATE TABLE sql syntax
    */
    public function getCreateTable() {
        $sql = 'SHOW CREATE TABLE `' . $this->name . '`';
        $rs = $this->db->conn->GetRow($sql);
        if($rs===false) {
            throw new Exception('getCreateTable method throw exception over table: ' . $this->name);
        }
        
        return $rs['Create Table'];
    }
    
    /**
     * Return a empty row to process a insert operation
     * 
     * @return RowIterator Return a new RowIterator object
    */
    public function newRow() {
        return new RowIterator($this);
    }
    
    /**
     * Create a new RowIterator object an insert $data into database.
     *
     * The internal array ($_rows) will be empty to perform a new query to
     * database and to retrieve fresh data.
     * <code>
     * for($i=0; $i<10; $i++) {
     *     $std = new stdClass();
     *     $std->pk_field = ($i+1);
     *     $std->integer = 0;
     *     $std->text = 'Testing '.$i;
     *     
     *     $dbIt['tablename']->insert($std);
     * }
     * </code>
     *
     * @uses TableIterator::newRow()
     * @uses TableIterator::init()
     * @see RowIterator::insert()
     */
    public function insert($data) {
        $row = $this->newRow();
        $row->insert($data);

        // reset internal data
        $this->init();
    }
    
    /**
     * Build SQL
     *
     * @uses TableIterator::$_select
     * @uses TableIterator::$_where
     * @uses TableIterator::$_limit
     * @uses TableIterator::$_order_by
     * @see execute()
     * @return string Return SQL
    */
    private function _buildSQL() {
        $sql = 'SELECT ';
        
        if(is_null($this->_select)) {
            $this->_select = '*';
        }
        $sql .= $this->_select . ' ';
        
        $sql .= 'FROM `' . $this->name . '`';
        
        if(!is_null($this->_where)) {
            $sql .= ' WHERE ' . $this->_where;
        }
        
        if(!is_null($this->_order_by)) {
            $sql .= ' ORDER BY ' . $this->_order_by;
        }
        
        if(!is_null($this->_limit)) {
            $sql .= ' LIMIT ' . $this->_limit;
        }
        
        return( $sql );
    }    
    
    /* Implements Iterator */
    function rewind() {
        $this->checkLoadRows();
        reset($this->_rows);
    }

    function current() {
        $this->checkLoadRows();
        return current($this->_rows);
    }

    function key() {
        $this->checkLoadRows();
        return key($this->_rows);
    }

    function next() {
        $this->checkLoadRows();
        next($this->_rows);
    }

    function valid() {
        $this->checkLoadRows();
        return key($this->_rows) !== null;
    }
    
    /* Implements ArrayAccess */
    public function offsetSet($offset, $value) {
        $this->checkLoadRows();
        $this->_rows[$offset] = $value;        
    }
    
    public function offsetExists($offset) {
        $this->checkLoadRows();
        return isset($this->_rows[$offset]);
    }
    
    public function offsetUnset($offset) {
        $this->checkLoadRows();
        unset($this->_rows[$offset]);
    }
    
    public function offsetGet($offset) {
        $this->checkLoadRows();
        return isset($this->_rows[$offset]) ? $this->_rows[$offset] : null;
    }
}

/**
 * Class RowIterator
 *
 * DatabaseIterator use TableIterator, RowIterator and ColumnIterator classes to
 * manipulate database using SPL features. This class implements Iterator and
 * ArrayAccess interfaces; and extends of ArrayObject
 * <code>
 * // $conn is an ADOConnection object
 * $dbIt = new DatabaseIterator($conn);
 * $table = $dbIt['tablename']; // TableIterator
 * $table[0]; // This object is a RowIterator
 * 
 * $table[0]->field1 = 'Other string';
 * $table[0]->update(); // Update into database
 *
 * $table[0]->delete(); // Delete value
 * </code>
 * 
 * @package DatabaseIterator
 * @version 0.1
 * @see TableIterator
 */
class RowIterator {
    /**
     * @var string
    */ 
    public $tablename = null;
    
    /**
     * @var TableIterator
    */ 
    public $table = null;
    
    /**
     * @var array
    */ 
    private $_internalData = null;
    
    /**
     * Constructor
     *
     * @param TableIterator $table Table that contains rows
     * @uses RowIterator::setTable()
     * @uses RowIterator::$tablename
    */
    public function __construct($table) {
        $this->tablename = $table->name;
                
        $this->setTable($table);
    }
    
    /**
     * Set TableIterator object reference
     *
     * @param TableIterator $table
    */
    public function setTable($table) {
        $this->table = $table;
    }       
    
    /**
     * Load properties into internal array
     *
     * @param array $properties
    */
    public function load($properties) {
        foreach($properties as $k => $value) {
            if(!is_numeric($k)) {
                $this->_internalData[$k] = $value;
            }
        }
    }        
    
    /**
     * Dump internal data, for this row, to database
     *
     * @return mixed Return result set for this operation
    */
    public function update() {
        $sql  = 'UPDATE `' . $this->tablename . '` SET ';
        
        $data = $values = array();
        
        $cols = $this->table->getColumns();
        foreach($cols as $col) {
            if(!$col->isPK() && !is_null($this->{$col->name})) {
                $data[] = '`' . $col->name . '`=?';
                $values[] = $this->{$col->name};
            }
        }
        
        $sql .= implode(', ', $data);        
        $sql .= ' WHERE ' . $this->_buildWhereCondition();
        
        return $this->execute($sql, $values);
    }
    
    /**
     * Perform a insert operation into database with $data array. $data must
     * have exact fields to perform operation
     *
     * @param array $data Associative array with exact properties for this row
     * @return mixed Return result set for this operation
    */
    public function insert($data) {                
        // Check $data
        $iFields = array_keys($this->table->getColumns());
        
        if(is_object($data)) {
            $data = get_object_vars($data);
        }
        $eFields = array_keys($data);
        
        if(count(array_diff($iFields, $eFields)) != 0) {
            throw new Exception('Insert data don\'t match', 1);
        }
        
        $sql  = 'INSERT INTO `' . $this->tablename . '` (';
        $sql .= '`' . implode('`, `', $iFields) . '`) VALUES (';
        
        $values = array();
        $cols = $this->table->getColumns();
        foreach($cols as $col) {
            $values[] = $this->table->db->conn->qstr(
                        $data[ $col->name ], get_magic_quotes_gpc()
                      );
        }
        
        $sql .= implode(', ', $values) . ')';
        
        return $this->execute($sql);
    }
    
    /**
     * Remove current row of database
     * 
     * @return mixed Return result set for this operation
    */
    public function delete() {
        $sql  = 'DELETE FROM `' . $this->tablename . '` ';
        $sql .= 'WHERE ' . $this->_buildWhereCondition();
        
        return $this->execute($sql);
    }
    
    /**
     * Perform a SQL query against database
     *
     * @param string $sql
     * @param array $values Array of values to sql data binding
    */
    public function execute($sql, $values=array()) {
        $rs = $this->table->db->conn->Execute($sql, $values);
        if($rs === false) {
            throw new Exception('Error executing SQL sentence: ' . $sql, 2);
        }
        
        // reset internal data
        $this->table->init();
        
        return $this->table->db->conn->Affected_Rows();
    }
    
    /**
     * Build SQL sentence to perform a query
     *
     * @return string
    */
    private function _buildWhereCondition() {
        $pks = $this->table->getPrimaryKeys();
        
        $conditions = array();
        if( count($pks)>0 ) {
            foreach($pks as $pk) {
                $conditions[] = "`" . $pk->name . "`=" .
                    $this->table->db->conn->qstr($this->{$pk->name}, get_magic_quotes_gpc());
            }
        } else {
            throw new Exception('Primary key not found', 3);
        }
        
        return implode(' AND ', $conditions);
    }

    /**
     * Magic method __get
     * 
     * @param string $name
     * @return string Return data from row for this property
    */ 
    public function __get($name) {
        return isset($this->_internalData[$name])? $this->_internalData[$name]: null;
    }
    
    /**
     * Magic method __set
     * 
     * @param string $name
     * @param string $value
    */ 
    public function __set($name, $value) {
        if(isset($this->_internalData[$name])) {
            $this->_internalData[$name] = $value;
        }
    }
    
    /**
     * Magic method __toString
    */ 
    public function __toString() {
        $html = '<dl>';
        foreach($this->_internalData as $key => $value) {
            $html .= '<dt>' . $key . '</dt>';
            $html .= '<dd>' . $value . '</dd>';
        }
        $html .= '</dl>';
        
        return $html;
    }    
    
    /* Implements Iterator */
    function rewind() {
        reset($this->_internalData);
    }

    function current() {
        return current($this->_internalData);
    }

    function key() {
        return key($this->_internalData);
    }

    function next() {
        next($this->_internalData);
    }

    function valid() {
        return key($this->_internalData) !== null;
    }
    
    /* Implements ArrayAccess */
    public function offsetSet($offset, $value) {
        // Check that $offset isn't a primary key
        $this->_internalData[$offset] = $value;        
    }
    
    public function offsetExists($offset) {
        return isset($this->_internalData[$offset]);
    }
    
    public function offsetUnset($offset) {
        unset($this->_internalData[$offset]);
    }
    
    public function offsetGet($offset) {
        return isset($this->_internalData[$offset]) ? $this->_internalData[$offset] : null;
    }    
}

/**
 * Class ColumnIterator
 *
 * This class hold information of table columns
 * 
 * @package DatabaseIterator
 * @version 0.1
 * @see TableIterator
 */
class ColumnIterator {

    /**#@+
     * @access public
     * @var string
     */
    public $name     = null;
    public $type     = null;
    public $not_null = null;
    public $max_length     = null;
    public $auto_increment = null;    
    public $primary_key    = false;
    /**#@-*/
    
    /**
     * @var TableIterator
     * @access private
    */ 
    private $table = null;
    
    /**
     * Constructor
     *
     * @param TableIterator $table
     * @see setTable()
    */
    public function __construct($table) {
        $this->setTable($table);
    }
    
    /**
     * load
     *
     * Load an array or an object properties
     *
     * @param array|object $properties
    */
    function load($properties) {
        if(is_array($properties)) {
            foreach($properties as $k => $v) {
                if( !is_numeric($k) ) {
                    $this->{$k} = $v;
                }
            }
        }elseif(is_object($properties)) {
            $properties = get_object_vars($properties);
            foreach($properties as $k => $v) {
                if( !is_numeric($k) ) {
                    $this->{$k} = $v;
                }
            }
        }      
    }
    
    /**
     * setTable
     *
     * Set $table, instance of TableIterator class
     *
     * @param TableIterator $table
    */
    public function setTable($table) {
        $this->table = $table;
    }
    
    /**
     * isPK
     *
     * Check if this column is a primary key
     *
     * @return boolean True if it's a primary key
    */
    public function isPK() {
        return $this->primary_key;
    }
    
    /**
     * __get
     *
     * Magic method
     * 
     * @link http://es2.php.net/manual/en/language.oop5.overloading.php#language.oop5.overloading.members
     * @param string $name Name of property
     * @return mixed Value of property, otherwise null
    */ 
    public function __get($name) {
        return isset($this->$name)? $this->$name: null;
    }
}

/// USAGE:

# Create an ADOConnection
//require('../libs/adodb5/adodb.inc.php');
//$conn = &ADONewConnection('mysql');
//$conn->PConnect('localhost', 'root', 'pass', 'database');

# Create a DatabaseIterator instance
//$databaseIt = new DatabaseIterator($conn);

# List tables
//foreach($databaseIt as $table) {
//    echo $table->name . PHP_EOL;
//}

# Set an internal SQL and list rows using RowIterator::each() method
//$databaseIt['contents']->select('pk_content, title')
//    ->where('title REGEXP "[[.backslash.]]"');
//$foo = create_function('$item', 'echo $item->title."<br />";');
//$databaseIt['contents']->each($foo);

# Get CREATE TABLE syntax for current table
//echo $databaseIt['contents']->getCreateTable();
//echo $databaseIt->from('contents')->getCreateTable();

# Insert new data
//for($i=0; $i<10; $i++) {
//    $std = new stdClass();
//    $std->pk_evento = ($i+1);
//    $std->fk_content_categories = 0;
//    $std->summary = 'Testing '.$i;
//    $std->body = 'Proba '.$i;
//    $std->img = 0;
//    
//    $databaseIt['events']->insert($std);
//}

# Alternative syntax
//$row = $databaseIt['events']->newRow();
//$row->insert($std);
//o
//$databaseIt['events']->insert($std);

# Update/delete a row
//$databaseIt['events'][0]->summary = 'Hello';
//$databaseIt['events'][0]->update();
//
//$databaseIt['events'][0]->delete();

# Using adodb capabilities (transactions)
//$conn->BeginTrans();
//foreach($rows as $row) {
//    $row->permalink = preg_replace('/[^a-zA-Z0-9_\-\/\.]/', '', $row->permalink);
//    $row->update();
//}
//echo('Done.');
//
//$conn->RollbackTrans(); // or $conn->CommitTrans();

