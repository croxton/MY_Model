<?php
/**
 * My_Model
 *
 * Base CRUD pattern for CodeIgniter
 *
 * @package		CodeIgniter
 * @license 	Creative Commons Attribution-Share Alike 3.0 Unported
 * @author  	Mark Croxton, mcroxton@hallmark-design.co.uk
 * @copyright  	Hallmark Design, www.hallmark-design.co.uk
 * @version 	1.0.5 (20 December 2010)
 */

class Table {
	
	/**
	 * The name of the table in the database
	 *
	 * @var string
	 */
	public $table;
	
	/**
	 * The table alias
	 *
	 * @var string
	 */
	public $alias;
	
	/**
	 * An array of fields in the table
	 *
	 * @var array
	 */
	public $fields = array();
	
	/**
	 * The primary key identifier for this table
	 *
	 * @var string
	 */
	public $pk;
	
	/**
	 * an array of tables with cardinality x-to-one or x-to-many with respect to $_table
	 *
	 * @var array
	 */
	private $_related = array();
	
	/**
	 * Constructor
	 *
	 * @param string 
	 * @param string
	 */
	public function __construct($table='', $alias='')
	{
		$this->table = $table;
		$this->alias = empty($alias) ? $table : $alias;
	}
	
	/**
	 * return table alias when object is accessed as a string
	 *
	 * @return string
	 */
	public function __toString() 
	{
		return $this->alias;
	}
	
	/**
	 * Set/get table relationships
	 *
	 * @param string related table name
	 * @param array linked keys: key (primary table) => key (related table)
	 * @return array
	 */
	public function related()
	{
		$args =& func_get_args();
		
		if (count($args)>0)
		{
			$related = $args[0];
			$on = isset($args[1]) ? $args[1] : array();	
			$this->_related[$related] = $on;
		}
		else
		{
			return $this->_related;
		}
	}
}

class MY_Model extends Model {
	
	/**
 	 * The name of the *primary* associated table name of the Model object
	 *
 	 * @var string
 	 */
	private $_table = null;
	
	/**
	 * Id of record inserted by last query
	 *
	 * @var unknown_type
	 */
	private $_insert_id = null;
	
	/**
	 * The number of records affected by the last query
	 *
	 * @var int
	 */
	private $_affected_rows = null;
	
	/**
	 * The number of records returned by last call to this->get()
	 *
	 * @var int
	 */
	private $_num_rows = null;
	
	/**
	 * The number of records returned; set explicitly by model methods
	 *
	 * @var array
	 */
	private $_count = array();
	
	/**
	 * Array of tables selected or referenced by a where condition in the current query
	 *
	 * @var array
	 */
	protected $tables_used = array();
	
	/**
	 * Array of tables joined by the current query
	 *
	 * @var array
	 */
	protected $tables_joined = array();
	
	/**
	 * Array of cached fields used by this model
	 *
	 * @var array
	 */
	protected $cached_fields = array();

	/**
	 * Constructor
	 */
	public function __construct()
	{
		parent::__construct();
		$this->load->helper('inflector');
	}
	
	/**
	 * pass unknown method calls to the $db object
	 * return $this where appropriate so that methods can still be chained...
	 *
	 * @return object
	 */
	public function __call($function, $args)
	{
		/* debug
		$backtrace = debug_backtrace();
		log_message('debug', 'intercepted by __call() in MY_Model: '.$backtrace[1]['function']);
		*/

		$return = call_user_func_array(array($this->db, $function), $args);
		if (is_object($return))
		{
			return $this;
		}
		else return $return;
	}
	
	/**
	 * set the primary table
	 *
	 * @param bool $init initialise the query
	 * @return object
	 */
	public function with($table, $init=true)
	{
		// initialise
		if ($init) $this->init();
		
		// the primary table we're operating on
		if ($table) $this->_table = $table;
		else $this->_table = null;
		
		return $this;
	}
	
	/**
	 * initialise object for new query
	 *
	 * @return object
	 */
	public function init()
	{
		$this->tables_used 		= array();
		$this->tables_joined 	= array();
		$this->_insert_id 		= null;
		$this->_num_rows 		= null;
		$this->_affected_rows 	= null;
		return $this;
	}
	
	/**
	 * Get/set table structure from cache
	 *
	 * @param array  $tables the tables columns to describe
	 * @param object $cache the cache object instance
	 * @param string $key the cache key
	 * @return array
	 */
	protected function get_table_fields($tables, $cache, $key)
	{
		// get cached table structure for this model
		$fields = $cache->get('table_structure/'.$key);

		if ($fields === false)
		{
			foreach ($tables as $table)
			{
		    	$fields[$table] = $this->db->list_fields($table);
			}
		    $cache->write($fields, 'table_structure/'.$key);
		}
		return $fields;
	}
	
	/**
	 * Load the requested database tables.
	 *
	 * @param string $table the table name
	 * @param string $alias the table alias to use in queries (defaults to $table)
	 * @param array  $fields an array of fields for this table
	 * @param string $pk the primary key of the table (defaults to first column in table)
	 * @return void
	 */
	protected function load_table($table, $alias='', $fields=array(), $pk="")
	{
		$alias 						= !empty($alias) ? $alias : $table;
		$this->$alias  				= new Table($table, $alias);
		$this->$alias->fields 		= (!empty($fields)) ? $fields : $this->db->list_fields($table);
		$this->$alias->pk 			= (!empty($pk)) ? $pk : $this->$alias->fields[0];
		
		// make the first table loaded the default primary table for this model
		if ($this->_table == null)
		{
			$this->_table = $alias; 
		}
	}
	
	/* utility functions for constructing SQL in models */
	
	/**
	 * From table part of query
	 *
	 * @param string $tables
	 * @param array $use_alias use table alias instead of table name?
	 * @return void
	 */
	protected function from($from='', $use_alias=true)
	{
		if (empty($from)) $table = $this->_table;
		else $table = $from;
		
		// don't add from condition if table has already been joined/referenced
		if (!in_array($table, $this->tables_joined))
		{
			if (empty($from)) {	// check if original argument was empty, if so it's safe to add alias if required
					
				if ($use_alias) 
				{
					$this->db->from($table.' '.$this->$table->alias);
				} 
				else
				{
					$this->db->from($table);
				}
			}
			else
			{
				$this->db->from($table);
			}
			
			$this->tables_joined[] = $table;
			
			if (!in_array($table, $this->tables_used))
			{
				$this->tables_used[] = $table;
			}
		}				
		return $this;
	}
	
	/**
	 * Select one or more fields in one or more tables
	 *
	 * @param array $fields
	 * @param boolean $escape_field_names
	 * @param array $tables
	 * @return object
	 */
	protected function select($fields=array(), $escape_field_names=true, $tables=array())
	{
		// use already joined tables if tables is empty
		if (empty($tables)) $tables = $this->tables_joined;
			
		// make sure we have an array
		$tables  = is_array($tables) ? $tables : array($tables);
		
		// make sure primary table is in the array
		if (!in_array($this->_table, $tables))
		{
			array_unshift($tables, $this->_table);
		}
		
		if (is_array($fields))
		{
			foreach($fields as $key => $value)
			{
				if (strpos($value, '.') !== FALSE)
				{
					// table alias has been hardcoded, e.g. 'table.field'
					$this->db->select($value, FALSE);
					
					// extract the table from the string and add to tables used
					$value = explode('.', $value);
					if (!in_array($value[0], $this->tables_used))
					{
						$this->tables_used[] = $value[0];
					}	
				} 
				else
				{
					// check requested field really exists
					foreach($tables as $table)
					{
						// use flipped array and isset() instead of array_search() for speed!
						$flipped = array_flip($this->$table->fields);
						
						if ( isset($flipped[$value]) )
						{
							$this->db->select($this->$table->alias.'.'.$value, $escape_field_names);
						
							// add to tables used
							if (!in_array($table, $this->tables_used))
							{
								$this->tables_used[] = $table;
							}
							break;
						}
					}
				}
			}
		}
		else
		{
			// hardcoded select
			$this->db->select($fields, $escape_field_names);
		}
		
		return $this;	
	}

	/**
	 * Narrow query by one or more where or where_in conditions
	 * Supports conditional logic for where options, eg "id >=" => 33
	 *
	 * @param array $fields
	 * @param array $tables
	 * @param boolean $use_alias use table aliases for where statements?
	 * @return object
	 */
	protected function where($options, $tables=array(), $use_alias=true)
	{		
		if (is_array($options))
		{	
			// use already joined tables if tables is empty
			if (empty($tables)) $tables = $this->tables_joined;
				
			// make sure we have an array
			$tables  = is_array($tables) ? $tables : array($tables);
		
			// make sure primary table is in the array
			if (!in_array($this->_table, $tables))
			{
				array_unshift($tables, $this->_table);
			}
		
			foreach($options as $field => $value)
	    	{
				if (strpos($field, '.') !== FALSE)
				{
					// table alias has been hardcoded, e.g. 'table.field'
					if (is_array($value) && !empty($value)) 
					{
						$this->db->where_in($field, $value);
					}
					else
					{
						$this->db->where($field, $value);
					}
				
					// extract the table from the string and add to tables used
					$field = explode('.', $field);
					if (!in_array($field[0], $this->tables_used))
					{
						$this->tables_used[] = $field[0];
					}	
				} 
				else
				{
					// check requested field really exists
					$key = preg_replace("/[^a-zA-Z0-9_\-]/", '', $field);
			
					foreach($tables as $table)
					{
						// flip for speed
						$flipped = array_flip($this->$table->fields);
				
						// determine table reference type to use
						if ($use_alias) $table_name = $this->$table->alias;
						else $table_name = $this->$table->table;
						
						if ( isset($flipped[$key]) )
						{
							if (is_array($value) && !empty($value)) 
							{
								$this->db->where_in($table_name.'.'.$field, $value);
							}
							else
							{
								$this->db->where($table_name.'.'.$field, $value);
							}
							// add to tables used
							if (!in_array($table, $this->tables_used))
							{
								$this->tables_used[] = $table;
							}
							break;
						}
					}
				}
	    	}
		}
		else
		{
			// hardcoded string, pass to $this->db->where
			if (!empty($tables)) $this->db->where($options, $tables);
			else $this->db->where($options);
		}
		return $this;
	}

	
	/**
	 * Join two tables
	 *
	 * @param string  $table the table to be joined
	 * @param string  $type  the join type
	 * @param boolean $with  use the joined table as the primary table for this model?
	 * @return object (success), bool (failure)
	 */
	protected function join($table=null, $type='left', $with=false)
	{
		if (is_null($table))
		{
			if (count($this->tables_used) > 0)
			{	
				foreach($this->tables_used as $table)
				{
					// join table (recursive)
					$this->join($table, $type);
				}
				// reset the used table array
				$this->tables_used = array();
				
				return $this;
			} 
			else return false;
		}
		else if (!in_array($table, $this->tables_joined))
		{
				
			$table1 = $this->_table;
			$table2 = $table;
		
			$related = $this->$table1->related();

			if (array_key_exists($table2, $related))
			{	
				// get the keys as specified in the relationship
				$fk = $related[$table2][0];
				$pk = $related[$table2][1];
			
				// Work out default key values if keys are empty
				// Assumptions: foreign key column is named after the concatenation of the 
		 		// singular of the joined table, underspace '_', and the joined table's primary key.
		 		// Eg table 1 with column: office.country_iso ; table 2 with primary key: countries.iso
				if ($fk=='') $fk = singular($this->$table2->table).'_'.$this->$table2->pk;
				if ($pk=='') $pk = $this->$table2->pk;
			
				// do the join
				$this->db->join(
	  				$this->$table2->table.' '.$this->$table2->alias, 
	  				$this->$table1->alias.'.'.$fk.' = '.$this->$table2->alias.'.'.$pk,
	  				$type
	  			);
	
				// add to array of joined tables
				$this->tables_joined[] = $table2;
				
				// set joined table as primary table for this model (note: do not inititalise)
				if ($with) $this->with($table2, false);

				return $this;
			}
	
			else return false;
		}
		else
		{
			// attempt to join an already joined table - fail gracefully
			return $this;
		}	
	}

   /**
	* _required method returns false if the $data array does not contain all of the keys assigned by the $required array.
	*
	* @param array $required
	* @param array $data
	* @return bool
	*/
	protected function _required($required, $data)
	{
	    foreach($required as $field) 
		{
			if(!isset($data[$field])) return false;
		}
	    return true;
	}
	
	/**
	* _default method combines the options array with a set of defaults giving the values in the options array priority.
	*
	* @param array $defaults
	* @param array $options
	* @return array
	*/
	protected function _default($defaults, $options)
	{
	    return array_merge($defaults, $options);
	}

	/* public model functions for use in models and controllers */
	
	/**
	 * Inserts a new record in the database
	 *
	 * @param array $data
	 * @param string $table
	 * @return boolean
	 */
	public function insert($data=null, $table=null)
	{
		if ($data == null) return FALSE;
		if ($table == null) $table = $this->_table;
		
		// reset query values and set our primary table
		$this->with($table);
		
		foreach ($data as $key => $value)
		{
			if (array_search($key, $this->$table->fields) === FALSE)
			{
				unset($data[$key]);
			}
		}
		
		// do insert, use table name
		if ($this->db->insert($this->$table->table, $data))
		{
			$this->_insert_id = $this->db->insert_id();
			return true;
		}
		else return false;
	}
	
	/**
	 * Saves model data to the database
	 *
	 * @param array $data
	 * @param array $where
	 * @param string $table
	 * @return boolean
	 */
	public function update($data=null, $where=null, $table=null)
	{
		if ($data == null || $where == null) return FALSE;	
		if ($table == null) $table = $this->_table;
		
		// reset query values and set our primary table
		$this->with($table);
		
		// assume primary key id of record has been passed
		if (!is_array($where)) $where = array($this->$table->pk => $where);
		
		// make where conditions, force use of the table name
		$this->where($where, $table, false);

		foreach ($data as $key => $value)
		{
			if (array_search($key, $this->$table->fields) === FALSE)
			{
				unset($data[$key]);
			}
		}
		
		// do update		
		if ($this->db->update($this->$table->table, $data))
		{
			$this->_affected_rows = $this->db->affected_rows();
			return true;
		}
		else return false;
	}
	
	/**
	 * Removes record(s)
	 *
	 * @param mixed $where
	 * @param string $table
	 * @param integer $limit
	 * @return boolean
	 */
	public function delete($where=null, $table=null, $limit=null)
	{
		if ($where == null) return FALSE;
		if ($table == null) $table = $this->_table;
		
		// reset query values and set our primary table
		$this->with($table);

		// assume primary key id of record has been passed
		if (!is_array($where)) $where = array($this->$table->pk => $where);
		
		// force use of real table name for where conditions
		$this->where($where, $table, false);
		
		// do delete - note that CI does not support aliases in delete() :(
		if ( $this->db->delete($this->$table->table, '', $limit) )
		{
			$this->_affected_rows = $this->db->affected_rows();
			return true;
		}
		else return false;
	}
	
   /**
	* Gets the record count
	*
	* @param array $where an array of conditions to search for
	* @param array $table The table to search
	* @param bool $init reset the query object
	* @return integer
	*/
	public function count($where, $table=null, $init=true)
	{
		if ($where == null) return FALSE;
		if ($table == null) $table = $this->_table;		
		
		// reset query values and set our primary table
		$this->with($table, $init);
		
		// get related tables for the primary table
		$tables = array_keys($this->$table->related());
		
		// add any tables that have *already* been joined
		$tables = array_merge($tables, $this->tables_joined);

		// make where condition(s)
		$this->where($where, $tables);
		
		// do simple joins
		$this->join();	
		
		// use alias for joined table
		$table_name = $this->$table->table.' '.$this->$table->alias;

		return $this->db->count_all_results($table_name);
	}

   /**
	* Get all records in the table
	*
	* @param array $table The table to search
	* @return integer
	*/
	public function count_all($table=null)
	{
		if ($table == null) $table = $this->_table;
		
		// reset query values and set our primary table
		$this->with($table);
		
		return $this->db->count_all($this->$table->table);
	}
	
	/**
	 * Returns a resultset array with specified fields from database matching given conditions.
	 *
	 * Option: Values
	 * --------------
	 * [column name] 	mixed	 one or more where conditions to match ([column name] = [value])
	 * fields			array 	 array of fields to return			
	 * limit         	integer  limits the number of returned records
	 * offset        	integer  how many records to bypass before returning a record (limit required)
	 * order_by      	string   determines which column the sort takes place
	 * sort     		string	 (asc, desc) sort ascending or descending (order_by required)
	 *
	 * @param array $options an array of query options (see above)
	 * @param array $table 	 the primary table to search
	 * @param bool $init 	 reset the query object
	 * @return array
	 */
	public function get()
	{	
		$args =& func_get_args();
		
		// if there are arguments then build the query
		if (count($args)>0)
		{
			$options = is_array($args[0]) ? $args[0] : array($args[0]);
			$table 	 = isset($args[1]) ? $args[1] : null;
			$init	 = isset($args[2]) ? $args[2] : true;
		
			if ($table == null) $table = $this->_table;
		
			// reset query values and our primary table, optionally initilasing the query
			$this->with($table, $init);
		
			// get related tables for the primary table
			$tables = array_keys($this->$table->related());
			
			// add any tables that have *already* been joined
			$tables = array_merge($tables, $this->tables_joined);

		    // default values
		    $options = $this->_default(
				array(
					'fields' 	=> array($this->$table->pk),
					'sort' 		=> 'asc', 
					'order_by' 	=> $this->$table->alias.'.'.$this->$table->pk,
					'offset'	=> 0,
					'distinct'	=> true,
					'join'		=> 'left'
				), $options);
			
			if ($options['fields'] == "*")
			{
				$options['fields'] = $this->$table->fields;
			} 
		
			$this->from();
		
			if ($options['distinct'])
			{
				$this->db->distinct();
			}
		
			// where conditions
			$this->where($options, $tables);
	
			// which fields do we want to retrieve?
			if (is_array($options['fields']))
			{
				$this->select($options['fields'], true, $tables);
			}
			else
			{
				// hardcoded select - don't escape field/table names
				$this->db->select($options['fields'], FALSE);
			}
		
			// do simple joins, if value is not FALSE
			if (!!$options['join'])
			{
				$this->join(null, $options['join']);
			}	

		    // limit and offset
		    if(isset($options['limit'])) 
			{
				$this->db->limit($options['limit'], $options['offset']);
			}
   
		    // order by and sort direction
			$this->db->order_by($options['order_by'], $options['sort']);
		}
   		
		// run the query
	    $query = $this->db->get();
		$this->_num_rows = $query->num_rows();
	
		if ($query->num_rows() == 0) 
		{
			return false;
		}

		// otherwise, return an array of objects
		return $query->result_array();
	}
	
	/**
	 * Return a single row as a resultset array with specified fields from database matching given conditions.
	 *
	 * @param array $options an array of query options (@see get())
	 * @param array $table the primary table to search
	 * @param bool $init reset the query object
	 * @return single row either in array or in object based on model config
	 */
	public function get_one($options=array(), $table=null, $init=true)
	{
		$options['limit'] = 1;
		$result = $this->get($options, $table, $init);
		return $result[0];
	}
	
	/**
	 * Returns the value of a single field
	 *
	 * @param string $field field to retrieve
	 * @param array $options an array of query options (@see get())
	 * @param array $table the primary table to search
	 * @param bool $init reset the query object
	 * @return mixed
	 */
	public function get_field($field, $options=array(), $table=null, $init=true)
	{
		$options['fields'] = array($field);
		$result = $this->get_one($options, $table, $init);
		return $result[$field];
	}
	
	/**
	 * Returns a key value pair array from database matching given conditions.
	 * eg Returns: array('UK' => 'United Kingdom', 'US' => 'United states')
	 *
	 * @param string $key
	 * @param string $value
	 * @param array $options an array of query options (@see get())
	 * @param array $table the primary table to search
	 * @param bool $init reset the query object
	 * @return array a list of key value pairs given criteria
	 */
	public function get_list($key, $value, $options=array(), $table=null, $init=true)
	{
		$options['fields'] = array($key, $value);	
		$list = array();
			
		if ($result = $this->get($options, $table, $init))
		{	
			foreach ($result as $row)
			{
				$list[$row[$key]] = $row[$value];
			}
		}
		else
		{
			return false;
		}
		return $list; 
	}
	
	/**
	 * Returns an array of the values of a specific column from database matching given conditions.
	 * eg Returns: array('1' => 'mark', '2' => 'croxton')
	 *
	 * @param string $column
	 * @param array $options an array of query options (@see get())
	 * @param array $table the primary table to search
	 * @param bool $init reset the query object
	 * @return array a numeric indexed array of column values
	 */
	public function get_column($column, $options=array(), $table=null, $init=true)
	{
		$options['fields'] = array($column);	
		$list = array();
			
		if ($result = $this->get($options, $table, $init))
		{
			foreach ($result as $row)
			{
				$list[] = $row[$column];
			}
		}
		else
		{
			return false;
		}
		return $list; 
	}
	
	/**
	 * Returns the current record's ID
	 *
	 * @return integer The ID of the current record
	 */
	public function get_insert_id()
	{
		return $this->_insert_id;
	}

	/**
	 * Returns the number of rows returned from the last query
	 *
	 * @return int
	 */
	public function get_num_rows()
	{
		return $this->_num_rows;
	}

	/**
	 * Returns the number of rows affected by the last query
	 *
	 * @return int
	 */
	public function get_affected_rows()
	{
		return $this->_affected_rows;
	}
	
	/**
	 * Saves the row count for a query, optionally identified by $key
	 *
	 * @return integer
	 * @return string
	 * @return void
	 */
	public function set_count($count=null, $key=null)
	{
		if ($count == null) $count = $this->_num_rows;
		
		if ($key == null)
		{
			$this->_count['_default'] = $count;
		}
		else
		{
			$this->_count[$key] = $count;
		}
	}
	
	/**
	 * Gets the saved row count for a query, optionally identified by $key
	 *
	 * @return string
	 * @return integer
	 */
	public function get_count($key='_default')
	{
		if (isset($this->_count[$key]))
		{
			return $this->_count[$key];
		}
		else return false;
	}
}