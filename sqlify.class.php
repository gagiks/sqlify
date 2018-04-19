<?php

/**
 * Simple SQL condition converter class
 * 
 * @author Gagik Sukiasyan
 * @since 22.12.2012
 * @version 1.3
 * @license BSD http://www.opensource.org/licenses/bsd-license.php
 */

class sqlify {
	protected $debug = 0;
	
	
	/**
	* Variable which stores all custom filters defied by $this->add_filterfunction
	*/
	protected $_queries = array();
	
	public function _test() {
		
		$this->debug = 1;
		$f=array(
			'product2_txt,product3_txt,product4_txt' => 'Layout',
			'aa' => '>AA<',
			'aa1' => '! >AA<',
			'aa2' => '! AA',
			'aa3' => '=AA',
			'aa4' => '!=AA',
			'aa5' => '<AA',
			'aa6' => '> AA',
			'aa7' => '>= AA',
			'aa8' => '! "AA"',
			'aa9' => ' "AA"',
			'aa10' => '!>AA',
			
			'bb1' => ' "AA" || <a && >=asd',
			
			);
	
		echo "<h4>TEST 1</h4>";
	
		dump($this->get_where($f));
		
		echo "<h4>TEST 2</h4>";
		
		$this->add_filter('AAA', array('a' => '>dd<'));
		$this->add_filter('BBB', array('a' => '>55<', 'b' => 'AA'));
		
		dump($this->get_where('AAA', $f, 'BBB'));
		
	}
	
	
	/**
	* Root function which makes SQL condition from provided string
	* For example if provided condition like 'XX || YY' it will convert to:
	* `field` LIKE '%XX%' or `field` LIKE '%YY%'
	*
	* @param string $str   : condirion in C style
	* @param string $field : SQL field name
	* @return string: the converted sql condition
	*/
	protected function _makesql ($str, $filed) {
		
		$sign  = "LIKE";
		$matchType = "partial";
		$isNegative = "";
		$val = "";
		
		if (preg_match("/^\s*(\!?)\s*[>\"](.*)[\"<]\s*$/", $str, $match)) {
//			Cases of exact match
			list($as, $isNegative, $val) = $match;
			$matchType = "exact";
			$sign = ($isNegative == '!') ? "<>" : '=';			
		} elseif (preg_match("/^\s*(\!?)\s*([><=]=?)(.+)$/", $str, $match)) {
			$matchType = "exact";
			list($as, $isNegative, $sign, $val) = $match;
			
			$sign = ($isNegative == '!' && $sign=="=" ) ? "<>" : $sign;		
			
		} elseif (preg_match("/^\s*(\!?)\s*(.+)$/", $str, $match)) {
//			Cases of partial  match
			$matchType = "partial";
			list($as, $isNegative,$val) = $match;
			$sign = ($isNegative == '!') ? "NOT LIKE" : 'LIKE';
		}
		
		$val = trim($val);
		if ($matchType == 'partial') {
			$val = "%$val%";
		}
		
		
		# This is for cases when value passed with SQL function, most used is COALESCE()
		if (strpos($filed, '(') !== false) {
			return	"$filed $sign '$val'";
		} 
		
		$filed = preg_replace('/^\s*`|`\s*$/','',$filed);
		$res = "";
		
		foreach (explode(",", $filed) as $f )  {
			if (strpos($f, '(')===false) {
				$ff = explode(".", $f);
				$f = '`'.implode("`.`", $ff).'`';	
			} 
			
			if ($this->debug) {
				echo "<h3>".$f." (".htmlspecialchars($str).") ==> $matchType $sign  '$val'  </h3>";	
			}
			
			if ($res == "") {
				$res .= " $f $sign '$val' ";
			} else {
				$res .= " or $f $sign '$val'";
			}
		}
		return $res;
	}
	
	public function exact($n) {return ">$n<";}
	
	private function _convert_macro_conditions ($str, &$field) {
		
		$str2 ="";
		
		while (preg_match('/@(\S+)\s+(((?!\|\||&&).)+)/i', $str, $matches) ) {
			$type = trim($matches[1]);
			$query = trim($matches[2]);
			$value = "";
			$str2 = $type;
			if (preg_match('/^M(GR)?/i', $type)) {
				$value = implode (" || ", array_map(array($this, "exact"), User::get_dir_reports($query)));
			} elseif (preg_match('/^T(op)?M(GR)?/i', $type)) {
				$value = implode (" || ", array_map(array($this, "exact"), User::get_all_reports($query)));
			} elseif (preg_match('/^D(ATE)?/i', $type)) {
				$value = date('Y-m-d', strtotime($query, time()));
			} elseif (preg_match('/^s(tatus)?/i', $type)) {
				if ($query == "open" || $query == "todo") {
					$value = '! >Fixed< && ! >Fix Validated< && ! >Closed<';
				} elseif ($query == "done") {
					$value = '>Fixed< || >Fix Validated< || >Closed<';
				} elseif ($query == 'close' || $query == 'closed') {
					$value = '>Fix Validated< || >Closed<';
				} 
			} elseif ($type == "is") {
				$value = ">$query<";
			}
			
			$str =  preg_replace('/@'.$type.'\s+((?!\|\||&&).)+/i', $value, $str, 1);
		}
		
		while (preg_match('/@(\S+)/i', $str, $matches) ) {
			$type = trim($matches[1]);
			$value = "";
			$str2 = $type;
			if ($type == "empty") {
				$value = "><";
				$field = "COALESCE($field, '')";
				// $str2 = $value;
			}
			
			$str =  preg_replace('/@'.$type.'/i', $value, $str, 1);
		}
		
		// echo "<tr><td>".$str2."</td>/<tr>";
		return $str;
	}
			
	private function _matches ($str, $filed) {
		$str = trim($str);
		$str = strtolower($str);
		$filed = strtolower($filed);
		
		if (preg_match("/^\s*(\!?)\s*>(.*)<\s*$/", $str, $match)) {
		
			list($as, $is_not, $val) = $match;

			if ($is_not == "!") {
				if($filed != trim($val)) {
					return 1;
				}
				return 0;
			}
			if ($filed == trim($val)) {
				return 1;
			}
			return 0;
	
		} elseif (preg_match("/^\s*([><]=?)(.+)$/", $str, $match)) {
			list($as, $sign, $val) = $match;
			return eval ("if (\"$filed\" $sign \"".trim($val)."\") {return 1;} else {return 0;}");
		} elseif (preg_match("/^\s*<(.+)$/", $str, $match)) {
			list($as, $val) = $match;
			return " $filed<'".trim($val)."' ";
		} elseif (preg_match("/^\s*!(.+)/", $str, $match)) {
			list($as, $val) = $match;
			if (! preg_match("/$val/", trim($filed))) {
				return 1;
			}
			return 0;
		} else {
			if (preg_match("/$str/", trim($filed))) {
				return 1;
			}
			return 0;
		}
	}
	
	public function convert_condition ($str, $field) {
		
		$str = $this->_convert_macro_conditions($str, $field);

		$tmp = preg_split("/(\|\||&&)/", $str, -1, PREG_SPLIT_DELIM_CAPTURE);
		$i = 0;
		$delim = array ("||", "&&");
		$sql = "";
		foreach ($tmp as $val) {
			if (in_array($val, $delim)) {
				$i ++;
				continue;
			}
			
			if ($i == 0) {
				$sql .= $this->_makesql($val, $field);
			} else {
				$condition = ($tmp[$i-1] == "||") ? "or" : "and";
				$sql .= $condition.$this->_makesql($val, $field);
			}
			$i++;
		}
		
		return $sql;
	}
	
	
	public function init () {
		$list = func_get_args();
		if (count($list) > 0) {
			$this->filters = call_user_func_array(array($this, 'get_filters'), $list);
		}
		return $this->filters;
	}

	
	public function is_match ($str, $field) {
		$str = $this->_convert_macro_conditions($str, $field);
		$tmp = preg_split("/(\|\||&&)/", $str, -1, PREG_SPLIT_DELIM_CAPTURE);
		$i = 0;
		$delim = array ("||", "&&");
		$condination = "";
		foreach ($tmp as $val) {
			if (in_array($val, $delim)) {
				$i ++;
				continue;
			}
			
			if ($i == 0) {
				$condination .= $this->_matches($val, $field);
			} else {
				$condination .= " ".$tmp[$i-1]." ".$this->_matches($val, $field);
			}
			$i++;
		}
//		echo "$str, $field | (".$condination.") => ".eval ("if (".$condination.") {return 1;} else {return 0;}")."<br>";
		return eval ("if (".$condination.") {return 1;} else {return 0;}");
	}
	
	static public function matches($str, $field) {
		$sqlify = new sqlify();
		return $sqlify->is_match($str, $field);
	}
	
	public function get_where() {
		$list = func_get_args();
		call_user_func_array(array($this, 'init'), $list);
		
		$where = '';
		if (is_array($this->filters) and count($this->filters) >0) {
			$filter_array = array();
			
			foreach ($this->filters as $field => $value) {
				if (trim($value) != '') {
					$value = str_replace("*", "%", $value);
					$filter_array[] = " ( ".$this->convert_condition($value, $field)." ) ";
				}
			}
			
			$w = implode (" and ", $filter_array);
			
			if ($w != '') { 
				if (preg_match('/^\s*$/i', $where)) {
					$where = ' ';
				} else {
					$where .= ' and ';
				}
				$where .= " $w ";
			}
		}
		
		return $where;
	}
	
	static public function where() {
		$sqlify = new sqlify();
		$list = func_get_args();
		call_user_func_array(array($sqlify, 'init'), $list);
		return $sqlify->get_where();
	}
	
	public function get_url() {
		//filter value should be:
		//		field=value1||value2&&value3;;field=value1||value2&&value3;;...
		$list = func_get_args();
		call_user_func_array(array($this, 'init'), $list);
		
		$urls = array();
		$url_params = array();
		if (is_array($this->filters) and !empty($this->filters)) {
			$filter_array = array();
			
			foreach ($this->filters as $field => $value) {
				if (trim($value) != '') {
					$urls[] = "%27$field%27:%27".urlencode($value)."%27";
					$url_params[] = $field.'='.urlencode($value);
				}
			}
		}
		if (isset($this->url_by_params)) {
			return implode('&', $url_params);
		}
		return "filters=".implode(',',$urls);
	}
	
	static public function url() {
		$sqlify = new sqlify();
		$list = func_get_args();
		call_user_func_array(array($sqlify, 'init'), $list);
		return $sqlify->get_url();	
	}
	
	static public function url_params() {
		$sqlify = new sqlify();
		$list = func_get_args();
		call_user_func_array(array($sqlify, 'init'), $list);
		$sqlify->url_by_params = true;
		return $sqlify->get_url();	
	}
	
	/**
	* Meges and returns all custom queries 
	*/
	public function get_filters() {
		$list = func_get_args();
		
		$res = array();
		foreach($list as $name) {
			if (is_array($name)) {
				$res = array_merge($res, $name);
			} elseif (isset($this->_queries[$name])) {
				$res = array_merge($res, $this->_queries[$name]);
			}
		}
		return $res;
	}
	
	
	/**
	* Define custom filter which can be used later 
	*/
	public function add_filter() {
		$list = func_get_args();
		$name = $list[0];
		$list = array_slice($list, 1);
		$res = call_user_func_array(array($this, 'get_filters'), $list);
		$this->_queries[$name] = $res;
	}
	
}

?>
