<?php

namespace Models;

class Query {

	public $_select = [];
	public $_from;
	public $_query = [];
	public $_compiled;
	public $_parameters = [];
	public $_return_type;

	public static function __callStatic($method, $arguments) {

		if ($method == 'from') {
			$new = new $arguments[0];
		} else {
			$new = new self;
		}

		return $new->__call($method, $arguments);
	}

	public function __call($method, $arguments) {
		$new = clone $this;

		switch ($method) {

			case 'join':
				if ($arguments[0] instanceof Literal) {
					$query = $arguments[0]->contents;
					$return_type = $this->_return_type;

				} else if ($arguments[0] instanceof Query and ! $arguments[0] instanceof Table) {
					$query = $arguments[0]->compile(TRUE);
					$return_type = $this->_return_type;

				} else {
					$table = new $arguments[0];
					list($return_type, $query) = call_user_func_array([$this, $new->_relations[$arguments[0]]['type']], [$table]);
					$query = preg_replace('/\s+/', ' ', $query);
				}

				$new->_query = $new->insert_at_correct_position($method, $query);
				$new->_return_type = $return_type;
				break;

			case 'count':
				$select = array_map(function ($argument) use ($new) {
					if ($argument == '*') {
						return "{$new->_from}.*";
					} else if (is_string($argument)) {
						return preg_replace('/^'.preg_quote(Table::delimiter).'/', '', $argument);
					}
					return $argument;
				}, $arguments);

				array_unshift($select, '(');
				array_unshift($select, 'COUNT');
				array_push($select, ')');

				$new->_select []= $select;
				break;

			case 'select':
				$select = array_map(function ($argument) {
					if (is_string($argument)) {
						return preg_replace('/^'.preg_quote(Table::delimiter).'/', '', $argument);
					}
					return $argument;
				}, $arguments);

				if (empty($arguments)) {
					$new->_select = [];
				} else {
					if ($arguments[0] == '*') {
						$select = ["{$new->_from}.*"];
					}

					$new->_select []= $select;
				}
				break;

			case 'from':
				$new->_from = (new $arguments[0])->path();
				break;

			case 'exists':
				array_splice($new->_select, 0, 0, [['EXISTS']]);
				$new->_return_type = 'boolean';
				break;

			case 'and_where':
			case 'and_having':
			case 'or_where':
			case 'or_having':
			case 'where':
			case 'having':
				$parametrized_arguments = [strtoupper(str_replace('_', ' ', $method))]; // WHERE or HAVING

				for ($i = 0; $i < count($arguments); $i++) {
					$argument = $arguments[$i];

					if (is_string($argument) && stripos($argument, Table::delimiter) === 0) {
						$argument = preg_replace('/^'.preg_quote(Table::delimiter).'/', '', $argument);

					} else if ($i >= 2) {
						$argument = ":param" . count($new->_parameters);
						$new->_parameters[$argument] = $arguments[$i];
					}

					$parametrized_arguments []= $argument;
				}

				$new->_query = $new->insert_at_correct_position($method, $parametrized_arguments);
				break;

			case 'order':
			case 'group':
				$new->_query = $new->insert_at_correct_position($method, array_merge([strtoupper($method).' BY'], array_map(function ($argument) {
					if (is_string($argument)) {
						return preg_replace('/^'.preg_quote(Table::delimiter).'/', '', $argument);
					}
					return $argument;
				}, $arguments)));
				break;

			case 'page':
				if (!isset($arguments[1])) {
					$arguments []= 0;
				}
				$new->_query = $new->insert_at_correct_position($method, ["LIMIT", $arguments[0], "OFFSET", $arguments[1]]);
				break;

			default:
				trigger_error('Uncaught Error: Call to undefined method '.get_class($this).'::'.$method.'()', E_USER_ERROR);
		}

		return $new;
	}

	public static function literal() {
		return new Literal(...func_get_args());
	}

	public function insert_at_correct_position($type, $arguments) {
		$query = $this->_query;
		$i = $this->get_precedence($type);
		array_splice($query, $i, 0, [$arguments]);
		return $query;
	}

	protected function get_precedence($type) {
		$equal_or_greater = [];

		$equal_or_greater['join']       = array_merge([], ['LEFT JOIN', 'RIGHT JOIN', 'FULL JOIN', 'INNER JOIN', 'CROSS JOIN', 'JOIN']);
		$equal_or_greater['where']      = array_merge($equal_or_greater['join'], ['WHERE', 'AND WHERE', 'OR WHERE']);
		$equal_or_greater['and_where']  = $equal_or_greater['where'];
		$equal_or_greater['or_where']   = $equal_or_greater['where'];
		$equal_or_greater['group']      = array_merge($equal_or_greater['where'], ['GROUP BY']);
		$equal_or_greater['having']     = array_merge($equal_or_greater['group'], ['HAVING', 'AND HAVING', 'OR HAVING']);
		$equal_or_greater['and_having'] = $equal_or_greater['having'];
		$equal_or_greater['or_having']  = $equal_or_greater['having'];
		$equal_or_greater['order']      = array_merge($equal_or_greater['having'], ['ORDER BY']);
		$equal_or_greater['page']       = array_merge($equal_or_greater['order'], ['LIMIT', 'OFFSET']);

		if (!count($this->_query)) {
			return 0;
		}

		for ($i = count($this->_query) - 1; $i >= 0; $i--) {

			if (! $this->_query[$i] instanceof Query && in_array($this->_query[$i][0], $equal_or_greater[$type])) {
				$i++;
				break;
			}
			if (!$i) { // wtf
				break;
			}
		}

		return $i;
	}

	public function compile($clear = FALSE, $nested = FALSE) {

		$select = array_map(function ($term) {
			$term = array_map(function ($elem) {
				return $elem instanceof Query ? $elem->compile() : $elem;
			}, $term);

			return implode(' ', $term);
		}, $this->_select);

		$query = array_map(function ($term) use ($clear) {
			if (!is_array($term)) return $term; /////////

			$term = array_map(function ($elem) use ($clear) {
				return $elem instanceof Query ? "{$elem->compile($clear, TRUE)->_compiled}" : $elem;
			}, $term);

			if (in_array($term[0], ['AND WHERE', 'OR WHERE', 'AND HAVING', 'OR HAVING'])) {
				$name = explode(' ', $term[0]);
				$term[0] = $name[0];
			}

			return implode(' ', $term);
		}, $this->_query);

		$nested = $nested && !empty($select);
		if (!empty($select)) {
			$this->_compiled = '';
			if ($select[0] == 'EXISTS') {
				$this->_compiled .= 'EXISTS ';
				array_shift($select);
			}
			$this->_compiled .= "SELECT ".implode(', ', $select) . "\nFROM {$this->_from}\n" . implode("\n", $query);
			if ($nested) {
				$this->_compiled = "({$this->_compiled})";
			}

		} else {
			$this->_compiled = implode("\n", $query);
		}

		if ($clear) {
			$this->_query = NULL;
		}

		return $this;
	}

	public function get() {
		$new = clone $this;
		$new = $new->compile();
		return [$new->_compiled, $new->_parameters];
	}
}

class Literal {
	public $contents;
	public function __construct() {
		$this->contents = array_reduce(func_get_args(), function ($carry, $item) {
			$item = is_subclass_of($item, 'Models\\Table') ? $item::table : $item;
			return $carry . $item;
		}, '');
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

		$this->_return_type = $class;

		$this->_select []= ["{$this->path()}.*"];
		$this->_from = $this->path();
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
