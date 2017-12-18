<?php

namespace MiniORM;

class Table {

	public static $___database;
	public static $___columns = [];
	public static $___initialized = FALSE;
	public static $___table;
	public static $___schema;
	public static $___relations = [];

	public $___values = [];
	public $___write = [];

	const delimiter = "\0\0\0*\0\0\0";

	public function __construct($schema = NULL, $table = NULL) {
		self::initialize_columns();

		$class = get_class($this);
		$parent = get_parent_class($class);
		$reflection = new \ReflectionClass($class);
		$static_properties = $reflection->getStaticProperties();

		foreach ($static_properties as $property => $value) {
			if (stripos($property, '___') === 0) continue;
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

	public function save() {
		$result = NULL;
		if (empty($this->___write)) {
			return $this;
		}

		foreach (self::$___columns as $key => $column) {
			if(!empty($column['primary_key']) && !empty($this->___values[$key])) {
				$result = Query::update($this->path(), $this->___write, [$key => $this->___values[$key]], self::get_database());
				break;
			}
		}

		if ($result === NULL) {
			$result = Query::insert($this->path(), $this->___write, self::get_database());
		}

		$class = get_called_class();
		$object = new $class;
		foreach ($result as $key => $value) {
			$object->___values[$key] = $value;
		}

		return $object;
	}

	public function __set($name, $value) {
		if (!array_key_exists($name, $this->___values)) {
			throw new UndefinedPropertyException('Undefined property: '.get_class($this).'::$'.$name);
		}
		if (!empty(self::$___columns[$name]['read_only'])) {
			throw new ReadOnlyPropertyException('Read-only property: '.get_class($this).'::$'.$name);
		}
		$this->___write[$name] = $value;
		$this->___values[$name] = $value;
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

	public static function initialize_columns() {
		$models = array_filter(get_declared_classes(), function ($class) {
			return defined("{$class}::table") && is_subclass_of($class, self::class) && !$class::$___initialized;
		});

		foreach ($models as $model) {
			$model::$___schema = defined("{$model}::schema") ? constant("{$model}::schema") : 'public';
			$model::$___table = constant("{$model}::table");
			$model::$___relations = defined("{$model}::relations") ? constant("{$model}::relations") : [];
			$model::$___initialized = TRUE;

			$reflection = new \ReflectionClass($model);
			$static_properties = $reflection->getStaticProperties();

			foreach ($static_properties as $property => $value) {
				if (stripos($property, '___') === 0) continue;
				$value['name'] = '"' . $model::$___schema . '"."' . $model::$___table . '"."' . $property . '"';
				$model::$$property = new Column($value);
			}
		}
	}
}

class UndefinedPropertyException extends \Exception {

}

class ReadOnlyPropertyException extends \Exception {

}
