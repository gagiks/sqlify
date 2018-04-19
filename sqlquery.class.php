<?php

class SQLQuery {
    static protected $_dbHandle;
	static protected $_tables = array();
	protected $_sqlify = null;
	
	protected $_result;
	protected $_query;
	protected $_table;
	protected $_fields = '*';
	protected $_sqlHelper;

	protected $_describe = array();
	protected $_primaryKey = 'id';

	protected $_pivotOn = array();
	protected $_pivotValues = array();
	protected $_pivotOrderBy;
	protected $_orderBy;
	protected $_groupBy;
	protected $_order;
	protected $_extraConditions;
	protected $_conditions = array();
	protected $_hO;
	protected $_hM;
	protected $_hMABTM;
	protected $_hierLoad;
	protected $_page;
	protected $_limit = null;

	/** Connects to database **/
	
    static function connect($address, $account, $pwd, $name) {
		if (self::$_dbHandle != 0) {
			return 1;
		}
		
        self::$_dbHandle = @mysql_connect($address, $account, $pwd);
        if (self::$_dbHandle != 0) {
            if (mysql_select_db($name, self::$_dbHandle)) {
                return 1;
            }
            else {
                return 0;
            }
        }
        else {
            return 0;
        }
    }
 
    /** Disconnects from database **/

    function disconnect() {
        if (@mysql_close(self::$_dbHandle) != 0) {
            return 1;
        }  else {
            return 0;
        }
    }
	
	function getTable() {
		return $this->_table;
	}
	
    /** Select Query **/
	function define_filter() {
		if (is_null($this->_sqlify)) {
			$this->_sqlify = new sqlify();
		}
		$list = func_get_args();
		call_user_func_array(array($this->_sqlify, 'add_filter'), $list);
	}
	
	function filter() {
		if (is_null($this->_sqlify)) {
			$this->_sqlify = new sqlify();
		}
		$list = func_get_args();
		$this->_extraConditions .= call_user_func_array(array($this->_sqlify, 'get_where'), $list).' AND ';
		
		return $this;
	}
	function where($field, $value = '' ) {
		if (is_array($field)){
			if (count($field) > 0) {
				$this->_extraConditions .= sqlify::where($field).' AND ';
			}
		} else {
			$this->_extraConditions .= '`'.$this->_model.'`.`'.$field.'` = \''.mysql_real_escape_string($value).'\' AND ';	
		}
		
		return $this;
	}
	
	function like($field, $value) {
		$this->_extraConditions .= '`'.$this->_model.'`.`'.$field.'` LIKE \'%'.mysql_real_escape_string($value).'%\' AND ';
		
		return $this;
	}

	function showHierarchy() {
		$this->_hierLoad = 1;
		return $this;
	}
	
	function showHasOne() {
		$this->_hO = 1;
	}

	function showHasMany() {
		$this->_hM = 1;
		return $this;
	}

	function showHMABTM() {
		$this->_hMABTM = 1;
		return $this;
	}

	function setLimit($limit) {
		$this->_limit = $limit;
		
		return $this;
	}
	
	function setFields($fields) {
		if (is_array($fields)) {
			$this->_fields = implode(', ', $fields);
		} else {
			$this->_fields = $fields;
		}
		
		return $this;
	}
	

	function setPage($page) {
		$this->_page = $page;
		
		return $this;
	}

	function orderBy($orderBy, $order = 'ASC') {
		$this->_orderBy = $orderBy;
		$this->_order = $order;
		
		return $this;
	}
	
	function groupBy ($groupBy) {
		if (is_array($groupBy)) {
			$this->_groupBy = '`'.implode('`, `', $groupBy).'`';
		} else {
			$tmp = explode('.', $groupBy);
			$this->_groupBy = '`'.implode('`.`',$tmp).'`';
		}
		
		return $this;
	} 

	function _build_conditions($validate = false) {
		$conditions = '\'1\'=\'1\' AND ';
		$pKey = $this->_primaryKey;
		
		if ($this->$pKey) {
			$conditions .= '`'.$this->_model.'`.`'.$pKey.'` = \''.mysql_real_escape_string($this->$pKey).'\' AND ';
		}

		if ($this->_extraConditions) {
			$conditions .= $this->_extraConditions;
		}

		$conditions = substr($conditions,0,-4);
		
		if ($validate && $conditions == '\'1\'=\'1\' ') {
			throw new Exception('Empthy conditions are passed');
		}
		
		if (count($this->_pivotOn)>0 && count($this->_pivotValues) > 0) {
			$this->groupBy($this->_pivotOn);
		}
		
		if(isset($this->_groupBy)) {
			$conditions .= ' GROUP BY '.$this->_groupBy.' ';
		}
		
		if (isset ($this->_pivotOrderBy)) {
			$conditions .= ' ORDER BY '.$this->_pivotOrderBy;
		} elseif (isset($this->_orderBy)) {
			$conditions .= ' ORDER BY `'.$this->_model.'`.`'.$this->_orderBy.'` '.$this->_order;
		}

		if (isset($this->_page)) {
			$offset = ($this->_page-1)*$this->_limit;
			$conditions .= ' LIMIT '.$this->_limit.' OFFSET '.$offset;
		} elseif(!is_null($this->_limit)) {
			$conditions .= ' LIMIT '.$this->_limit;
		}
		return $conditions;
	}
	
	function _build_query () {
		global $inflect;
		$pKey = $this->_primaryKey;
		
		foreach($this->_pivotValues as $n => $fd) {
			$this->_fields .= ", ".$fd['select'];
		}
		
		$from = '`'.$this->_table.'` as `'.$this->_model.'` ';
		$fromChild = '';

		if ($this->_hO == 1 && isset($this->hasOne)) {
			foreach ($this->hasOne as $model => $alias) {
				$modelObj = new $model();
				$table = $modelObj->_table;
				$key = $modelObj->_primaryKey;
				$singularAlias = strtolower($alias);
				$from .= 'LEFT JOIN `'.$table.'` as `'.$model.'` ';
				$from .= 'ON `'.$this->_model.'`.`'.$alias.'` = `'.$model.'`.`'.$key.'`  ';
				
				unset($modelObj);
			}
		}
		
		$conditions = $this->_build_conditions();
		
		return $this->_query = 'SELECT '.$this->_fields.' FROM '.$from.' WHERE '.$conditions;
	}
	
	function search($queryObjType = "object") {
		global $inflect;
		$pKey = $this->_primaryKey;
		$this->_build_query();
		
		// dump($this->_query);
		// exit();
		$this->_result = mysql_query($this->_query, self::$_dbHandle);
		
		$result = array();
		$table = array();
		$field = array();
		$numOfFields = mysql_num_fields($this->_result);
		for ($i = 0; $i < $numOfFields; ++$i) {
		    array_push($table,mysql_field_table($this->_result, $i));
		    array_push($field,mysql_field_name($this->_result, $i));
		}
		
		if (mysql_num_rows($this->_result) > 0 ) {
			while ($row = mysql_fetch_row($this->_result)) {
				$tempResults = array();	
				
				for ($i = 0;$i < $numOfFields; ++$i) {
					$tempResults[$table[$i]][$field[$i]] = $row[$i];
				}
				
				$rowKey = $tempResults[$this->_model][$pKey];
				
				if ($queryObjType == 'object') {
					foreach($tempResults as $tbl => $data) {
						$tempResults[$tbl] = $this->_array_to_model($tbl, $data);
					}
				} 
				
				if ($this->_hM == 1 && isset($this->hasMany)) {
					foreach ($this->hasMany as  $modelChild => $aliasChild) {
						$childModel = new $modelChild();
						if ($this->_hierLoad == 1) {
							$childModel->showHasOne();
							$childModel->showHasMany();
						}
						
						$childModel->where($aliasChild, $rowKey);
						$tempResults[$modelChild] = $childModel->search();
						unset($childModel );
					}
				}
				
				// if ($this->_hMABTM == 1 && isset($this->hasManyAndBelongsToMany)) {
					// TODO
				// }

				
				
				array_push($result,$tempResults);
				
			}

			if (mysql_num_rows($this->_result) == 1 && $this->$pKey != null) {
				mysql_free_result($this->_result);
				$this->clear();
				return($result[0]);
			} else {
				mysql_free_result($this->_result);
				$this->clear();
				return($result);
			}
		} else {
			mysql_free_result($this->_result);
			$this->clear();
			return $result;
		}

	}

	function pivotOn() {
		if (func_num_args() == -1) {
			throw new Exception("Need to have fields for pivoting");
		}
		
		$this->_pivotOn = func_get_args();
		if (is_array($this->_pivotOn[0])) {
			$this->_pivotOn = $this->_pivotOn[0];
		}
		// $this->setFields($this->_pivotOn);
// 		$this->groupBy($this->_pivotOn);
		
		return $this;
	}
	
	function pivotValues($field, $type = 'count', $condition = '1=1', $name = null ) {
		
		if (is_null($name)) {
			$name = "${field}_${type}";
		}
		
		$select = strtoupper($type)."($field) as $name ";
		if (!is_null($condition)) {
			$select = "SUM(CASE WHEN $condition THEN ".($type == "count" ? 1 : $field)." ELSE 0 END) as $name";
		}
		
		$this->_pivotValues[$name] = array(
					'field' => $field,
					'type' => $type,
					'select' => $select
				);
		
		return $this;
	}
	
	function pivotOrderBy($field, $type = 'asc') {
		if ($this->_pivotOrderBy) {
			$this->_pivotOrderBy .= ", `$field` $type";
		} else {
			$this->_pivotOrderBy .= " `$field` $type";
		}
		
		return $this;
	}
	
	function fetch($type = null, $query = null) {
		
		global $inflect;
		
		if (is_null($query)) {
			$this->_build_query();
		} else {
			$this->_query = $query;
		}
		
		$this->_result = mysql_query($this->_query, self::$_dbHandle);
		// dump($this->_query);
		$result = array();
		while ($row = mysql_fetch_array($this->_result, MYSQL_ASSOC)) {
			if (!is_null($type)) {
				$result[$row[$type]] = $row[$type];
			} else {				
				$tmp = '$result';
				
				foreach($this->_pivotOn as $t) {
					$tmp .= "['".$row[$t]."']";
				}
				
				if (count($this->_pivotValues) > 0) {
					foreach($this->_pivotValues as $name => $t) {
						// print  $name. "<br>";
						if ($name !== "grouped") {
							$tmpp = $tmp."['".$name."']";
						} else {
							$tmpp = $tmp;
						}
						
						$tmpp .= " = \$row['".$name."']; \n";
						
						eval("$tmpp");
					}
				} else {
					$tmp .= "['".$row[$this->_primaryKey]."']";
					$tmp .= " = \$row;\n";
					eval("$tmp");
				}
				
				
				
			}
        }
		
		$this->clear();
		mysql_free_result($this->_result);
		return($result);
	}
	
    /** Custom SQL Query **/

	function custom($query) {

		global $inflect;

		$this->_result = mysql_query($query, self::$_dbHandle);

		$result = array();
		$table = array();
		$field = array();
		$tempResults = array();
		
		
		if(substr_count(strtoupper($query),"SELECT")>0) {
			if (mysql_num_rows($this->_result) > 0) {
				$numOfFields = mysql_num_fields($this->_result);
				for ($i = 0; $i < $numOfFields; ++$i) {
					array_push($table,mysql_field_table($this->_result, $i));
					array_push($field,mysql_field_name($this->_result, $i));
				}
					while ($row = mysql_fetch_row($this->_result)) {
						for ($i = 0;$i < $numOfFields; ++$i) {
							$table[$i] = ucfirst($inflect->singularize($table[$i]));
							$tempResults[$table[$i]][$field[$i]] = $row[$i];
						}
						array_push($result,$tempResults);
					}
			}
			mysql_free_result($this->_result);
		}	
		$this->clear();
		return($result);
	}

	protected function _array_to_model($model, &$array) {
		$obj = new $model();
		foreach($array as $key => $value) {
			$obj->$key = $value;
		}
		return $obj;
	}
	
	
	public function fields () {
		return $this->_describe;
	}
	
    /** Describes a Table **/

	protected function _describe() {
		
		if (isset(self::$_tables[$this->_table])) {
			$this->_describe = self::$_tables[$this->_table];
		
		} else {
			global $cache;
		
			$this->_describe = @$cache->retrieve('describe'.$this->_table);

			if (!$this->_describe) {
				$this->_describe = array();
				$query = 'DESCRIBE '.$this->_table;
				
				$this->_result = mysql_query($query, self::$_dbHandle);
				
				while ($row = mysql_fetch_row($this->_result)) {
					 array_push($this->_describe,$row[0]);
				}

				mysql_free_result($this->_result);
				$cache->store('describe'.$this->_table,$this->_describe);
			}
			
			self::$_tables[$this->_table] = $this->_describe;
		}
		
		

		foreach ($this->_describe as $field) {
			$this->$field = null;
		}
		
	}

	function getPK() {
		return $this->_primaryKey;
	}
	
	function primaryKey($val = null) {
		$pKey = $this->_primaryKey;
		if (! is_null($val) ) {
			$this->$pKey = $val;
		}
		return $this->$pKey;
	}
	
    /** Delete an Object **/

	function deleteAll($cond, $clear=true) {
		$this->where($cond);
		$pKey = $this->_primaryKey;
		$conditions = $this->_build_conditions(true);
		
		$query = "DELETE FROM `".$this->_table."` WHERE ".$conditions;
		// dump($query);
		$this->_result = mysql_query($query, self::$_dbHandle);	
		
		if($clear) {
			$this->clear(false);
		}
		
		return $this;
	}
	
	function delete($cond = null) {
		if (!is_null($cond)) {
			$this->where($cond);
		}
		
		$pKey = $this->_primaryKey;
		$id = $this->exists();
		
		$query = 'DELETE FROM '.$this->_table.' WHERE `'.$pKey.'`=\''.mysql_real_escape_string($id).'\'';		
		$this->_result = mysql_query($query, self::$_dbHandle);
		$this->clear(false);
		if ($this->_result == 0) {
			/** Error Generation **/
			return -1;
		}
		
		return 1;
	}

	function exists($cond = null) {
		$clear = false;
		if (!is_null($cond)) {
			$clear = true;
			$this->where($cond);
		}
		$pKey = $this->_primaryKey;
		$conditions = $this->_build_conditions(true);
		
		$query = "SELECT ".$pKey." FROM `".$this->_table."` as ".$this->_model." WHERE ".$conditions;
		$this->_result = mysql_query($query, self::$_dbHandle);
			
		$row = mysql_fetch_array($this->_result);
		
		if($clear) {
			$this->clear(false);
		}
		
		if (isset($row[$pKey])) {
			return $row[$pKey];
		}
		
		return false;
	}
	
    /** Saves an Object i.e. Updates/Inserts Query **/
	protected function __escape_string($string) {
		return mysql_real_escape_string($string);
	}

	function save($clear = false) {
		$query = '';
		$pKey = $this->_primaryKey;
		
		$fields = '';
		$values = '';
		$updates = '';
		
		foreach ($this->_describe as $field) {
			if (!is_null($this->$field)) {
				$value = $this->__escape_string($this->$field);
				$fields .= '`'.$field.'`,';
				$updates .= '`'.$field.'` = \''.$value.'\',';
				$values .= '\''.$value.'\',';
			}
		}
		$values = substr($values,0,-1);
		$fields = substr($fields,0,-1);
		$updates = substr($updates,0,-1);

		$query = 'INSERT INTO '.$this->_table.' ('.$fields.') VALUES ('.$values.')';
		$query .= ' ON DUPLICATE KEY UPDATE '.$updates.'';
		
		
		$this->_result = mysql_query($query, self::$_dbHandle);
		// dump($query);
		if($clear) {
			$this->clear();
		}
		
		if ($this->_result == 0) {
            /** Error Generation **/
			throw new Exception("Failed to save");
			return -1;
        }
	}
 
	/** Clear All Variables **/

	function clear($fields = true) {
		if ($fields) {
			foreach($this->_describe as $field) {
				$this->$field = null;
			}	
		}

		$this->_pivotOn = array();
		$this->_orderby = null;
		$this->_groupBy = null;
		$this->_extraConditions = null;
		$this->_conditions = array();
		$this->_hO = null;
		$this->_hM = null;
		$this->_hMABTM = null;
		$this->_page = null;
		$this->_order = null;
	}

	/** Pagination Count **/

	function count() {
		$this->_build_query();
		
		if ($this->_query) {
			if (isset($this->_page) && $this->_limit) {
				$pattern = '/SELECT (.*?) FROM (.*)LIMIT(.*)/i';
			} else {
				$pattern = '/SELECT (.*?) FROM (.*)/i';
			}
			$replacement = 'SELECT COUNT(*) FROM $2';
			$countQuery = preg_replace($pattern, $replacement, $this->_query);
			$this->_result = mysql_query($countQuery, self::$_dbHandle);
			$count = mysql_fetch_row($this->_result);
			return $count[0];
		} else {
			/* Error Generation Code Here */
			return 0;
		}
	}
	
	function totalPages() {
		if ($this->_query && $this->_limit) {
			
			$count = $this->count();
			$totalPages = ceil($count/$this->_limit);
			return $totalPages;
		} else {
			/* Error Generation Code Here */
			return -1;
		}
	}

    /** Get error string **/

    function getError() {
        return mysql_error(self::$_dbHandle);
    }
}
