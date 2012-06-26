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


/**
 * Base class for locks, like file locks.
 *
 * libphutil provides a concrete lock in @{class:PhutilFileLock}.
 *
 *   $lock->lock();
 *     do_contentious_things();
 *   $lock->unlock();
 *
 * If the lock can't be acquired because it is already held,
 * @{class:PhutilLockException} is thrown. Other exceptions indicate
 * permanent failure unrelated to locking.
 *
 * When extending this class, you should call @{method:getLock} to look up
 * an existing lock object, and @{method:registerLock} when objects are
 * constructed to register for automatic unlock on shutdown.
 *
 * @task  impl        Lock Implementation
 * @task  registry    Lock Registry
 * @task  construct   Constructing Locks
 * @task  status      Determining Lock Status
 * @task  lock        Locking
 * @task  internal    Internals
 *
 * @group filesystem
 */
abstract class PhutilLock {

  private static $registeredShutdownFunction = false;
  private static $locks = array();

  private $locked = false;
  private $profilerID;
  private $name;

/* -(  Constructing Locks  )------------------------------------------------- */


  /**
   * Build a new lock, given a lock name. The name should be globally unique
   * across all locks.
   *
   * @param string Globally unique lock name.
   * @task construct
   */
  protected function __construct($name) {
    $this->name = $name;
  }


/* -(  Lock Implementation  )------------------------------------------------ */


  /**
   * Acquires the lock, or throws @{class:PhutilLockException} if it fails.
   *
   * @return void
   * @task impl
   */
  abstract protected function doLock();


  /**
   * Releases the lock.
   *
   * @return void
   * @task impl
   */
  abstract protected function doUnlock();


/* -(  Lock Registry  )------------------------------------------------------ */


  /**
   * Returns a globally unique name for this lock.
   *
   * @return string Globally unique lock name, across all locks.
   * @task registry
   */
  final public function getName() {
    return $this->name;
  }


  /**
   * Get a named lock, if it has been registered.
   *
   * @param string Lock name.
   * @task registry
   */
  protected static function getLock($name) {
    return idx(self::$locks, $name);
  }


  /**
   * Register a lock for cleanup when the process exits.
   *
   * @param PhutilLock Lock to register.
   * @task registry
   */
  protected static function registerLock(PhutilLock $lock) {
    if (!self::$registeredShutdownFunction) {
      register_shutdown_function(array('PhutilLock', 'unlockAll'));
      self::$registeredShutdownFunction = true;
    }

    $name = $lock->getName();
    if (self::getLock($name)) {
      throw new Exception("Lock '{$name}' is already registered!");
    }

    self::$locks[$name] = $lock;
  }


/* -(  Determining Lock Status  )-------------------------------------------- */


  /**
   * Determine if the lock is currently held.
   *
   * @return bool True if the lock is held.
   *
   * @task status
   */
  final public function isLocked() {
    return $this->locked;
  }


/* -(  Locking  )------------------------------------------------------------ */


  /**
   * Acquire the lock. If lock acquisition fails because the lock is held by
   * another process, throws @{class:PhutilLockException}. Other exceptions
   * indicate that lock acquisition has failed for reasons unrelated to locking.
   *
   * If the lock is already held, this method throws. You can test the lock
   * status with @{method:isLocked}.
   *
   * @return void
   *
   * @task lock
   */
  final public function lock() {
    if ($this->locked) {
      $name = $this->getName();
      throw new Exception(
        "Lock '{$name}' has already been locked by this process.");
    }

    $profiler = PhutilServiceProfiler::getInstance();
    $profiler_id = $profiler->beginServiceCall(
      array(
        'type'  => 'lock',
        'name'  => $this->getName(),
      ));

    try {
      $this->doLock();
    } catch (Exception $ex) {
      $profiler->endServiceCall(
        $profiler_id,
        array(
          'lock' => false,
        ));
      throw $ex;
    }

    $this->profilerID = $profiler_id;
    $this->locked = true;
  }


  /**
   * Release the lock. Throws an exception on failure, e.g. if the lock is not
   * currently held.
   *
   * @return void
   *
   * @task lock
   */
  final public function unlock() {
    if (!$this->locked) {
      $name = $this->getName();
      throw new Exception(
        "Lock '{$name} is not locked by this process!");
    }

    $this->doUnlock();

    $profiler = PhutilServiceProfiler::getInstance();
    $profiler->endServiceCall(
      $this->profilerID,
      array(
        'lock' => true,
      ));

    $this->profilerID = null;
    $this->locked = false;
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * On shutdown, we release all the locks. You should not call this method
   * directly. Use @{method:unlock} to release individual locks.
   *
   * @return void
   *
   * @task internal
   */
  public static function unlockAll() {
    foreach (self::$locks as $key => $lock) {
      if ($lock->locked) {
        $lock->unlock();
      }
    }
  }

}
