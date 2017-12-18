<?php

namespace Models;

class Query {

	public $_global   = [];
	public $_select   = [];
	public $_from     = [];
	public $_join     = [];
	public $_where    = [];
	public $_group_by = [];
	public $_having   = [];
	public $_order_by = [];
	public $_limit    = NULL;
	public $_offset   = NULL;

	public static function __callStatic($method, $arguments) {
		$new = new self;

		if (in_array($method, ['not', 'exists', 'cast', 'call', 'as'])) {
			if ($method === 'cast' || $method === 'as') {
				$arguments[1] = Table::delimiter . $arguments[1];
			}
			$new->_global []= ['method' => $method, 'arguments' => $arguments];
			return $new;

		} else if ($method === 'from') {
			$new->_from []= ['method' => 'from', 'arguments' => [Table::delimiter . (new $arguments[0])->path()]];
			return $new;
		}

		return $new->__call($method, $arguments);
	}

	public function __call($method, $arguments) {

		$new = clone $this;
		$value = ['method' => $method, 'arguments' => $arguments];

		$escape = function ($value, $key) {
			if (isset($value[$key])) {
				$value[$key] = Table::delimiter . $value[$key];
			}
			return $value;
		};

		if (in_array($method, ['select', 'count'])) {
			$new->_select []= $value;

		} else if (in_array($method, ['join','left_join','right_join','left_outer_join','right_outer_join','inner_join'])) {
			$value['arguments'] = $escape($value['arguments'], 2);
			$new->_join []= $value;

		} else if (in_array($method, ['where', 'where_or', 'where_and', 'having', 'having_or', 'having_and'])) {

			if (!preg_match('/_(or|and)$/i', $method)) {
				$method .= '_and';
			}
			if (isset($value['arguments'][1]) && preg_match('#^[-+*/=!<>~.]+$#', $value['arguments'][1])) {
				$value['arguments'] = $escape($value['arguments'], 1);
			}
			$new->{"_" . preg_replace('/_(or|and)$/i', '', $method)} []= $value;

		} else if (in_array($method, ['group_by', 'order_by'])) {
			if ($method === 'order_by') {
				$value['arguments'] = $escape($value['arguments'], 1);
			}
			$new->{"_{$method}"} []= $value;

		} else if (in_array($method, ['limit', 'offset'])) {
			$new->{"_{$method}"} = $arguments[0];

		} else {
			trigger_error('Uncaught Error: Call to undefined method '.get_class($this).'::'.$method.'()', E_USER_ERROR);
		}

		return $new;
	}

	public static function literal() {
		$value = Table::delimiter;
		foreach (func_get_args() as $argument) {
			$value .= ' ' . preg_replace('/^'.preg_quote(Table::delimiter).'/', '', $argument);
		}
		return $value;
	}

	public function run($database) {
		$this->compile();
	}

	public function compile() {
		$query = [];
		$parameters = [];

		$flatten = function ($expression, $override = []) use (&$parameters) {

			foreach ($expression['arguments'] as $key => $value) {

				if ($value instanceof Query) {
					list($subquery, $subparameters) = $value->compile();
					$parameters = array_merge($parameters, $subparameters);
					$expression['arguments'][$key] = $subquery;

				} else if (strpos($value, Table::delimiter) === 0) {
					$expression['arguments'][$key] = substr($expression['arguments'][$key], strlen(Table::delimiter));

				} else if ($key >= 1 && !in_array($value, $override)) {

					$parameters []= $value;
					$expression['arguments'][$key] = '$'.count($parameters);
				}
			}
			return $expression;
		};

		$select_from = function ($query, $this_select, $verb) use (&$flatten) {
			if (count($this_select)) {
				$select = [$verb];

				foreach ($this_select as $expression) {
					$expression = $flatten($expression, ['*']);
					$select = array_merge($select, $expression['method'] === 'count' ? ['COUNT'] : [], ['('], $expression['arguments'], [')'], [',']);
				}
				array_pop($select);
				$query = array_merge($query, $select);
			}
			return $query;
		};

		$join = function($query, $this_join) use (&$flatten) {
			foreach ($this_join as $expression) {
				$table =& $expression['arguments'][0];

				if (stripos($table, 'Models\\') === 0) {
					$table = Table::delimiter . (new $table)->path();
				}

				$expression = $flatten($expression);
				$on = array_slice($expression['arguments'], 1);

				$query = array_merge($query, [strtoupper(str_replace('_', ' ', $expression['method']))], ['(', $table, ')'], ['ON', '('], $on, [')']);
			}
			return $query;
		};

		$where_having = function($query, $this_where, $verb) use (&$flatten) {
			if (count($this_where)) $query []= $verb.' TRUE';
			foreach ($this_where as $expression) {
				$expression = $flatten($expression);
				$query = array_merge($query, [stripos($expression['method'], '_or') !== FALSE ? 'OR' : 'AND'], ['('], $expression['arguments'], [')']);
			}
			return $query;
		};

		$simple = function ($query, $expressions) use (&$flatten) {
			$add_commas = function($array) {
				$result = [];
				foreach ($array as $value) {
					$result []= $value;
					$result []= ',';
				}
				array_pop($result);
				return $result;
			};

			foreach ($expressions as $expression) {
				$expression = $flatten($expression);

				if ($expression['method'] === 'call') {
					$query = array_merge($query, [strtoupper($expression['arguments'][0])], ['('], $add_commas(array_slice($expression['arguments'], 1)), [')']);

				} else if ($expression['method'] === 'cast') {
					$query = array_merge($query, [strtoupper($expression['method'])], ['('], [$expression['arguments'][0], 'AS', $expression['arguments'][1]], [')']);

				} else {
					$query = array_merge($query, [strtoupper($expression['method'])], ['('], $expression['arguments'], [')']);
				}
			}
			return $query;
		};

		$query = $simple($query, $this->_global);
		$query = $select_from($query, $this->_select, 'SELECT');
		$query = $select_from($query, $this->_from, 'FROM');
		$query = $join($query, $this->_join);
		$query = $where_having($query, $this->_where, 'WHERE');
		$query = $select_from($query, $this->_group_by, 'GROUP BY');
		$query = $where_having($query, $this->_having, 'HAVING');
		$query = $select_from($query, $this->_order_by, 'ORDER BY');
		if (isset($this->_limit))  $query []= 'LIMIT '  . $this->_limit;
		if (isset($this->_offset)) $query []= 'OFFSET ' . $this->_offset;

		return [implode(' ', $query), $parameters];
	}
}

class Table extends Query {

	public $_table;
	public $_schema;

	public $_relations = [];
	public $_values = [];
	public $_write = [];

	const delimiter = "\0\0\0*\0\0\0";

	public function __construct($schema = NULL, $table = NULL) {

		$class = get_class($this);

		$this->_table = $table ?: constant("{$class}::table");

		$this->_schema = $schema ?: defined("{$class}::schema")
			? constant("{$class}::schema") : 'public';

		$this->_relations = defined("{$class}::relations")
			? constant("{$class}::relations") : [];

	    $parent = get_parent_class($class);
		$reflection = new \ReflectionClass($class);
		$static_properties = $reflection->getStaticProperties();

		foreach ($static_properties as $property => $value) {

	        if (!isset($class::$$property) && isset($parent::$$property)) { // inherited table attributes must always be *declared* in child classes
	        	$class::$$property = $parent::$$property;
	        }

		    $class::$$property = Table::delimiter."{$this->path()}.\"{$property}\"";
		    $this->_values[$property] = NULL;
		}
	}

	public function __set($name, $value) {
    	if (!array_key_exists($name, $this->_values)) {
    		trigger_error('Undefined property: '.get_class($this).'::$'.$name, E_USER_NOTICE);
    		return;
    	}
		$this->_write[$name] = $value;
    }

    public function __get($name) {
    	if (!array_key_exists($name, $this->_values)) {
    		trigger_error('Undefined property: '.get_class($this).'::$'.$name, E_USER_NOTICE);
    		return NULL;
    	}
    	return $this->_values[$name];
    }

	public function path() {
		return "\"{$this->_schema}\".\"{$this->_table}\"";
	}

	protected function one_to_many(Table $table) {
		return ['array', ['LEFT JOIN', "{$table->path()} ON
			{$table->path()}.\"{$this->_table}_id\" = {$this->path()}.\"id\""]];
	}

	protected function many_to_one(Table $table) {
		return [get_class($table), ['LEFT JOIN', "{$table->path()} ON
			{$this->path()}.\"{$table->_table}_id\" = {$table->path()}.\"id\""]];
	}

	protected function many_to_many(Table $table) {
		$middle_table = (new $this->_relations[get_class($table)]->target)->path();
		return ['array', ['LEFT JOIN', "{$middle_table} ON
			{$middle_table}.\"{$this->_table}_id\" = {$this->path()}.\"id\"
			LEFT JOIN {$table->path()} ON 
			{$middle_table}.\"{$table->_table}_id\" = {$table->path()}.\"id\""]];
	}	
}
