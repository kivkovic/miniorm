<?php

namespace Models;

class Query {

	public $_select   = [];
	public $_join     = [];
	public $_where    = [];
	public $_group_by = [];
	public $_having   = [];
	public $_order_by = [];
	public $_limit    = NULL;
	public $_offset   = NULL;

	public static function __callStatic($method, $arguments) {

		if ($method === 'from') {
			$new = new $arguments[0];
		} else {
			$new = new self;
		}

		return $new->__call($method, $arguments);
	}

	public function path() {
		if (!defined("{$this}::table")) {
			trigger_error('Uncaught Error: Table not defined');
		}
		return (defined("{$this}::schema") ? constant("{$this}::schema") : 'public') . '.' . $this::table;
	}

	public function __call($method, $arguments) {

		$new = clone $this;
		$value = ['method' => $method, 'arguments' => $arguments];

		if (in_array($method, ['select', 'count'])) {
			$new->_select []= $value;

		} else if (in_array($method, ['join','left_join','right_join','left_outer_join','right_outer_join','inner_join'])) {
			if ($method === 'join') $value['method'] = 'left_join';
			$new->_join []= $value;

		} else if (in_array($method, ['where', 'where_or', 'where_and'])) {
			if ($method === 'where') $value['method'] = 'where_and';
			$new->_where []= $value;

		} else if (in_array($method, ['group_by', 'order_by'])) {
			$new->{"_{$method}"} []= $value;

		} else if (in_array($method, ['having', 'having_or', 'having_and'])) {
			if ($method === 'having') $value['method'] = 'having_and';
			$new->_having []= $value;

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
}

class Expression extends Query {
	public $_global = [];

	public static function __callStatic($method, $arguments) {
		$new = new Expression();

		if (in_array($method, ['not', 'exists', 'cast', 'call'])) {
			$new->_global []= ['method' => $method, 'arguments' => $arguments];
			return $new;

		} else {
			return $new->__call($method, $arguments);
		}
	}

	public function run($database) {
		$query = [];
		$parameters = [];

		//TODO
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
