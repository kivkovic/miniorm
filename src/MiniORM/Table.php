<?php

namespace MiniORM;

class Table {

	public static $___database;
	public $___table;
	public $___schema;

	public $___columns = [];
	public $___relations = [];
	public $___values = [];
	public $___write = [];

	const delimiter = "\0\0\0*\0\0\0";

	public function __construct($schema = NULL, $table = NULL) {

		$class = get_class($this);

		$this->___table = $table ?: constant("{$class}::table");

		$this->___schema = $schema ?: defined("{$class}::schema")
			? constant("{$class}::schema") : 'public';

		$this->___relations = defined("{$class}::relations")
			? constant("{$class}::relations") : [];

		$parent = get_parent_class($class);
		$reflection = new \ReflectionClass($class);
		$static_properties = $reflection->getStaticProperties();

		foreach ($static_properties as $property => $value) {
			if (stripos($property, '___') === 0) continue;

			if (!isset($class::$$property) && isset($parent::$$property)) { // inherited table attributes must always be *declared* in child classes
				$class::$$property = $parent::$$property;
			}

			$class::$$property = Table::delimiter."{$this->path()}.\"{$property}\"";
			$this->___columns[$property] = $value;
			$this->___values[$property] = NULL;
		}
	}

	public static function set_database($database) {
		self::$___database = $database;
	}

	protected static function get_database() {
		return (empty(self::$___database) && get_parent_class() !== FALSE)
			? parent::get_database()
			: self::$___database;
	}

	public static function load($query) {
		$results = [];
		$class = get_called_class();
		foreach ($query->get(self::get_database()) as $row) {
			$object = new $class;
			foreach ($row as $key => $value) {
				$object->___values[$key] = $value;
			}
			$results []= $object;
		}

		return $results;
	}

	public function __set($name, $value) {
		if (!array_key_exists($name, $this->___values)) {
			throw new UndefinedPropertyException('Undefined property: '.get_class($this).'::$'.$name);
		}
		if (!empty($this->___columns[$name]['read_only'])) {
			throw new ReadOnlyPropertyException('Read-only property: '.get_class($this).'::$'.$name);
		}
		$this->___write[$name] = $value;
	}

	public function __get($name) {
		if (!array_key_exists($name, $this->___values)) {
			throw new UndefinedPropertyException('Undefined property: '.get_class($this).'::$'.$name);
		}
		return $this->___values[$name];
	}

	public function path() {
		return "\"{$this->___schema}\".\"{$this->___table}\"";
	}
}

class UndefinedPropertyException extends \Exception {

}

class ReadOnlyPropertyException extends \Exception {

}
