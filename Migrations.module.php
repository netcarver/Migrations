<?php
/**
 * Migrations (0.0.1)
 * 
 * 
 * @author Benjamin Milde
 * 
 * ProcessWire 2.x
 * Copyright (C) 2011 by Ryan Cramer
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 * 
 */

class Migrations extends WireData implements Module {

	/**
	 * @var string The path the migration files are stored in
	 */
	protected $path;

	/**
	 * Ran by ProcessWire when starting the module
	 */
	public function init() {
		$this->path = $this->config->paths->root . "site/migrations/";
		include_once(__DIR__ . "/Migration.php");
		include_once(__DIR__ . "/FieldMigration.php");
		include_once(__DIR__ . "/TemplateMigration.php");
		include_once(__DIR__ . "/ModuleMigration.php");
	}

	/**
	 * Create a new 'empty' migration file
	 *
	 * @param null|string $desc Description to be added to the file
	 * @param string      $type Type of the migration
	 * @return string pathname
	 * @throws WireException
	 */
	public function createNew($desc = null, $type = 'default') {
		$base = __DIR__ . "/templates/$type.php.inc";

		if(!is_file($base))
			throw new WireException('Not a valid template for creation ' . $type);

		$timestamp = $this->getTime();
		$new = $this->path . date('Y-m-d_H-i-s', $timestamp) . '.php';

		if(is_file($new))
			throw new WireException('There\'s already a file existing for the current time.');

		$content = file_get_contents($base);
		$content = wirePopulateStringTags($content, array(
			'classname' => $this->filenameToClassname($new),
			'description' => addcslashes($desc, '"\\')
		));
		file_put_contents($new, $content);

		return $new;
	}

	/**
	 * Only for testing reasons -.-
	 */
	protected function getTime ()
	{
		return time();
	}

	/**
	 * Make sure the path is existing at all times.
	 */
	public function createPath()
	{
		if(!wireMkdir($this->path))
			throw new WireException('Could not create path');
	}

	/**
	 * Returns an array of filenames of all the migrations in $this->path
	 *
	 * @return array
	 */
	public function getMigrations() {
		$files = array();

		if(!is_dir($this->path)) $this->createPath();

		foreach (new DirectoryIterator($this->path) as $fileInfo) {
			if($fileInfo->isDot() || $fileInfo->isDir()) continue;
			if(substr($fileInfo->getBasename(), 0, 1) === '.') continue;

			$files[$fileInfo->getBasename(".php")] = $fileInfo->getPathname();
		}

		ksort($files);
		return $files;
	}

	/**
	 * Returns an array of classnames of all the migrations, which are currently migrated
	 *
	 * @return array
	 * @throws WireException
	 */
	public function getRunMigrations()
	{
		$classes = [];

		$stmt = $this->database->query("SELECT * FROM {$this->className}");
		$data = $stmt->fetchAll(PDO::FETCH_OBJ);
		foreach($data as $item) $classes[] = $item->class;

		return $classes;
	}

	/**
	 * Convert a filename to a classname
	 *
	 * @param string $path
	 * @return string
	 */
	public function filenameToClassname($path) {
		$classname = basename($path, '.php');
		$classname = str_replace("-", "_", $classname);
		return "Migration_" . $classname;
	}

	/**
	 * Convert a classname to a filename
	 *
	 * @param string $classname
	 * @return string
	 */
	public function classnameToFilename($classname) {
		$filename = str_replace('Migration_', '', $classname);
		$filename = str_replace('_', '-', $filename);
		$filename = preg_replace("/(\d{4}-\d{2}-\d{2})-(\d{2}-\d{2}(?:-\d{2})?)/", "$1_$2", $filename);
		return $filename . '.php';
	}

	/**
	 * Run a migration by filename
	 *
	 * @param string $file
	 */
	public function migrate($file) {
		$stmt = $this->database->prepare("INSERT INTO {$this->className} (class) VALUES(:class)");
		$this->runAction($file, 'update', $stmt);
	}

	/**
	 * Rollback a migration by filename
	 *
	 * @param string $file
	 */
	public function rollback($file) {
		$stmt = $this->database->prepare("DELETE FROM {$this->className} WHERE class=:class");
		$this->runAction($file, 'downgrade', $stmt);
	}

	/**
	 * Run a migration by it's classname
	 *
	 * @param string $classname
	 */
	public function migrateClass($classname) {
		$path = $this->path . $this->classnameToFilename($classname);
		$this->migrate($path);
	}

	/**
	 * Rollback a migration by it's classname
	 *
	 * @param string $classname
	 */
	public function rollbackClass($classname) {
		$path = $this->path . $this->classnameToFilename($classname);
		$this->rollback($path);
	}

	/**
	 * Check if a migration was already migrated
	 *
	 * @param string $file
	 * @return bool
	 */
	public function isMigrated($file)
	{
		return in_array($this->filenameToClassname($file), $this->getRunMigrations());
	}

	/**
	 * Add the path to the folder if the file doesn't have an directory separator
	 *
	 * @todo Make this more windows failsave
	 * @param string $file
	 * @return string
	 */
	protected function ensurePath($file){
		if(strpos($file, $this->path) === false) return $this->path . basename($file);
		return $file;
	}

	/**
	 * Get the static properties of a migration class to retrieve the description of of
	 *
	 * @param string $file
	 * @return array
	 */
	public function getStatics($file) {
		$file = $this->ensurePath($file);
		include_once($file);

		$className = $this->filenameToClassname($file);
		$class = new ReflectionClass($className);
		return $class->getStaticProperties();
	}

	/**
	 * Get the type of a migration file
	 *
	 * @param string $file
	 * @return string
	 */
	public function getType($file) {
		$file = $this->ensurePath($file);
		include_once($file);

		$className = $this->filenameToClassname($file);
		$class = new ReflectionClass($className);
		$type = $class->getParentClass()->getName();
		$type = $type === 'Migration' ? 'Default' : $type;
		return $type;
	}

	/**
	 * Returns an array of all the latest migration files, which are not migrated
	 * The filenames are sorted by their creation date
	 *
	 * @return array
	 */
	public function getLatestToMigrate()
	{
		$files = $this->getMigrations();	
		$toRun = array();

		foreach (array_reverse($files) as $file) {
			if($this->isMigrated($file)) break;
			$toRun[] = $file;
		}

		return array_reverse($toRun);
	}

	/**
	 * Run a migration/rollback action
	 *
	 * @param string $path
	 * @param string $function
	 * @param PDOStatement $stmt
	 */
	private function runAction($path, $function, $stmt) {
		include_once($path);
		$classname = $this->filenameToClassname($path);

		$migration = new $classname();
		$migration->$function();

		$stmt->bindParam(':class', $classname);
		$stmt->execute();
	}

	/**
	 * Create the database to store ran migration classes in
	 */
	public function ___install ()
	{
		$sql = <<< _END

		CREATE TABLE {$this->className} (
			id int unsigned NOT NULL auto_increment,
			class varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY(id),
			UNIQUE KEY(class)
		) ENGINE = MYISAM;

_END;

		$this->database->query($sql);
	}

	/**
	 * Delete the database on uninstall
	 */
	public function ___uninstall ()
	{
		$this->database->query("DROP TABLE {$this->className}");
	}

}