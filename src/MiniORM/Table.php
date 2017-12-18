<?php

namespace MiniORM;

class Table {

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
			throw new UndefinedPropertyException('Undefined property: '.get_class($this).'::$'.$name);
		}
		$this->_write[$name] = $value;
	}

	public function __get($name) {
		if (!array_key_exists($name, $this->_values)) {
			throw new UndefinedPropertyException('Undefined property: '.get_class($this).'::$'.$name);
		}
		return $this->_values[$name];
	}

	public function path() {
		return "\"{$this->_schema}\".\"{$this->_table}\"";
	}
}

class UndefinedPropertyException extends \Exception {

}
