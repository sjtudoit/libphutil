<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

define('__LIBPHUTIL__', true);

/**
 * @group library
 */
function phutil_require_module($library, $module) {
  PhutilBootloader::getInstance()->loadModule($library, $module);
}

/**
 * @group library
 */
function phutil_require_source($source) {
  PhutilBootloader::getInstance()->loadSource($source);
}

/**
 * @group library
 */
function phutil_register_library($library, $path) {
  PhutilBootloader::getInstance()->registerLibrary($library, $path);
}

/**
 * @group library
 */
function phutil_register_library_map(array $map) {
  PhutilBootloader::getInstance()->registerLibraryMap($map);
}

/**
 * @group library
 */
function phutil_load_library($path) {
  PhutilBootloader::getInstance()->loadLibrary($path);
}

/**
 * @group library
 */
function phutil_is_windows() {
  // We can also use PHP_OS, but that's kind of sketchy because it returns
  // "WINNT" for Windows 7 and "Darwin" for Mac OS X. Practically, testing for
  // DIRECTORY_SEPARATOR is more straightforward.
  return (DIRECTORY_SEPARATOR != '/');
}

/**
 * @group library
 */
function phutil_is_hiphop_runtime() {
  return (array_key_exists('HPHP', $_ENV) && $_ENV['HPHP'] === 1);
}

/**
 * @group library
 */
final class PhutilBootloader {

  private static $instance;

  private $registeredLibraries  = array();
  private $libraryMaps          = array();
  private $moduleStack          = array();
  private $currentLibrary       = null;
  private $classTree            = array();

  public static function getInstance() {
    if (!self::$instance) {
      self::$instance = new PhutilBootloader();
    }
    return self::$instance;
  }

  private function __construct() {
    // This method intentionally left blank.
  }

  public function getClassTree() {
    return $this->classTree;
  }

  public function registerLibrary($name, $path) {
    if (basename($path) != '__phutil_library_init__.php') {
      throw new PhutilBootloaderException(
        'Only directories with a __phutil_library_init__.php file may be '.
        'registered as libphutil libraries.');
    }

    $path = dirname($path);

    // Detect attempts to load the same library multiple times from different
    // locations. This might mean you're doing something silly like trying to
    // include two different versions of something, or it might mean you're
    // doing something subtle like running a different version of 'arc' on a
    // working copy of Arcanist.
    if (isset($this->registeredLibraries[$name])) {
      $old_path = $this->registeredLibraries[$name];
      if ($old_path != $path) {
        throw new PhutilLibraryConflictException($name, $old_path, $path);
      }
    }

    $this->registeredLibraries[$name] = $path;

    // TODO: Remove this once we drop libphutil v1 support.
    $version = $this->getLibraryFormatVersion($name);
    if ($version == 1) {
      return $this;
    }

    // For libphutil v2 libraries, load all functions when we load the library.

    if (!class_exists('PhutilSymbolLoader', false)) {
      $root = $this->getLibraryRoot('phutil');
      $this->executeInclude($root.'/symbols/PhutilSymbolLoader.php');
    }

    $loader = new PhutilSymbolLoader();
    $loader
      ->setLibrary($name)
      ->setType('function')
      ->selectAndLoadSymbols();

    return $this;
  }

  public function registerLibraryMap(array $map) {
    $this->libraryMaps[$this->currentLibrary] = $map;
    return $this;
  }

  public function getLibraryMap($name) {
    if (empty($this->libraryMaps[$name])) {
      $root = $this->getLibraryRoot($name);
      $this->currentLibrary = $name;
      $okay = include $root.'/__phutil_library_map__.php';
      if (!$okay) {
        throw new PhutilBootloaderException(
          "Include of '{$root}/__phutil_library_map__.php' failed!");
      }

      $map = $this->libraryMaps[$name];

      // NOTE: We can't use "idx()" here because it may not be loaded yet.
      $version = isset($map['__library_version__'])
        ? $map['__library_version__']
        : 1;

      switch ($version) {
        case 1:
          // NOTE: In the original version of the library, the map stored
          // separate 'requires_class' (always a string) and
          // 'requires_interface' keys (always an array). Load them into the
          // classtree.

          // TODO: Remove support once we drop libphutil v1 support.
          foreach ($map['requires_class'] as $child => $parent) {
            $this->classTree[$parent][] = $child;
          }
          foreach ($map['requires_interface'] as $child => $parents) {
            foreach ($parents as $parent) {
              $this->classTree[$parent][] = $child;
            }
          }
          break;
        case 2:
          // NOTE: In version 2 of the library format, all parents (both
          // classes and interfaces) are stored in the 'xmap'. The value is
          // either a string for a single parent (the common case) or an array
          // for multiple parents.
          foreach ($map['xmap'] as $child => $parents) {
            foreach ((array)$parents as $parent) {
              $this->classTree[$parent][] = $child;
            }
          }
          break;
        default:
          throw new Exception("Unsupported library version '{$version}'!");
      }

    }
    return $this->libraryMaps[$name];
  }

  public function getLibraryFormatVersion($name) {
    $map = $this->getLibraryMap($name);

    // NOTE: We can't use "idx()" here because it may not be loaded yet.
    $version = isset($map['__library_version__'])
      ? $map['__library_version__']
      : 1;

    return $version;
  }

  public function getLibraryRoot($name) {
    if (empty($this->registeredLibraries[$name])) {
      throw new PhutilBootloaderException(
        "The phutil library '{$name}' has not been loaded!");
    }
    return $this->registeredLibraries[$name];
  }

  public function getAllLibraries() {
    return array_keys($this->registeredLibraries);
  }

  private function pushModuleStack($library, $module) {
    array_push($this->moduleStack, $this->getLibraryRoot($library).'/'.$module);
    return $this;
  }

  private function popModuleStack() {
    array_pop($this->moduleStack);
  }

  private function peekModuleStack() {
    return end($this->moduleStack);
  }

  public function loadLibrary($path) {
    $root = null;
    if (!empty($_SERVER['PHUTIL_LIBRARY_ROOT'])) {
      if ($path[0] != '/') {
        $root = $_SERVER['PHUTIL_LIBRARY_ROOT'];
      }
    }
    $okay = $this->executeInclude($root.$path.'/__phutil_library_init__.php');
    if (!$okay) {
      throw new PhutilBootloaderException(
        "Include of '{$path}/__phutil_library_init__.php' failed!");
    }
  }

  public function loadModule($library, $module) {
    $version = $this->getLibraryFormatVersion($library);
    if ($version == 2) {
      // If a v1 library has a "phutil_require_module(...)" for a v2 library,
      // ignore it. We load functions on library registration and autoload
      // classes.
      return;
    }

    $this->pushModuleStack($library, $module);
    phutil_require_source('__init__.php');
    $this->popModuleStack();
  }

  public function loadLibrarySource($library, $source) {
    $path = $this->getLibraryRoot($library).'/'.$source;
    $okay = $this->executeInclude($path);
    if (!$okay) {
      throw new PhutilBootloaderException("Include of '{$path}' failed!");
    }
  }

  public function loadSource($source) {
    $base = $this->peekModuleStack();
    $okay = $this->executeInclude($base.'/'.$source);
    if (!$okay) {
      throw new PhutilBootloaderException(
        "Include of '{$base}/{$source}' failed!");
    }
  }

  public function moduleExists($library, $module) {
    $path = $this->getLibraryRoot($library);
    return @file_exists($path.'/'.$module.'/__init__.php');
  }

  private function executeInclude($path) {
    // Suppress warning spew if the file does not exist; we'll throw an
    // exception instead. We still emit error text in the case of syntax errors.
    $old = error_reporting(E_ALL & ~E_WARNING);
    $okay = include_once $path;
    error_reporting($old);

    return $okay;
  }

}

/**
 * @group library
 */
final class PhutilBootloaderException extends Exception { }


/**
 * Thrown when you attempt to load two different copies of a library with the
 * same name. Trying to load the second copy of the library will trigger this,
 * and the library will not be loaded.
 *
 * This means you've either done something silly (like tried to explicitly load
 * two different versions of the same library into the same program -- this
 * won't work because they'll have namespace conflicts), or your configuration
 * might have some problems which caused two parts of your program to try to
 * load the same library but end up loading different copies of it, or there
 * may be some subtle issue like running 'arc' in a different Arcanist working
 * directory. (Some bootstrapping workflows like that which run low-level
 * library components on other copies of themselves are expected to fail.)
 *
 * To resolve this, you need to make sure your program loads no more than one
 * copy of each libphutil library, but exactly how you approach this depends on
 * why it's happening in the first place.
 *
 * @task info Getting Exception Information
 * @task construct Creating Library Conflict Exceptions
 * @group library
 */
final class PhutilLibraryConflictException extends Exception {

  private $library;
  private $oldPath;
  private $newPath;

  /**
   * Create a new library conflict exception.
   *
   * @param string The name of the library which conflicts with an existing
   *               library.
   * @param string The path of the already-loaded library.
   * @param string The path of the attempting-to-load library.
   *
   * @task construct
   */
  public function __construct($library, $old_path, $new_path) {
    $this->library = $library;
    $this->oldPath = $old_path;
    $this->newPath = $new_path;

    $message = "Library conflict! The library '{$library}' has already been ".
               "loaded (from '{$old_path}') but is now being loaded again ".
               "from a new location ('{$new_path}'). You can not load ".
               "multiple copies of the same library into a program.";

    parent::__construct($message);
  }

  /**
   * Retrieve the name of the library in conflict.
   *
   * @return string The name of the library which conflicts with an existing
   *                library.
   * @task info
   */
  public function getLibrary() {
    return $this->library;
  }


  /**
   * Get the path to the library which has already been loaded earlier in the
   * program's execution.
   *
   * @return string The path of the already-loaded library.
   * @task info
   */
  public function getOldPath() {
    return $this->oldPath;
  }

  /**
   * Get the path to the library which is causing this conflict.
   *
   * @return string The path of the attempting-to-load library.
   * @task info
   */
  public function getNewPath() {
    return $this->newPath;
  }
}

phutil_register_library('phutil', __FILE__);

phutil_require_module('phutil', 'symbols');

/**
 * @group library
 */
function __phutil_autoload($class) {
  try {
    PhutilSymbolLoader::loadClass($class);
  } catch (PhutilMissingSymbolException $ex) {
    // If there are other SPL autoloaders installed, we need to give them a
    // chance to load the class. Throw the exception if we're the last
    // autoloader; if not, swallow it and let them take a shot.
    $autoloaders = spl_autoload_functions();
    $last = end($autoloaders);
    if ($last == '__phutil_autoload') {
      throw $ex;
    }
  }
}

spl_autoload_register('__phutil_autoload', $throw = true);
