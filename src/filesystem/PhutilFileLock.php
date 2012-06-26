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
 * Wrapper around `flock()` for advisory filesystem locking. Usage is
 * straightforward:
 *
 *   $path = '/path/to/lock.file';
 *   $lock = new PhutilFileLock($path);
 *   $lock->lock();
 *
 *     do_contentious_things();
 *
 *   $lock->unlock();
 *
 * For more information on locks, see @{class:PhutilLock}.
 *
 * @task  construct   Constructing Locks
 * @task  impl        Implementation
 *
 * @group filesystem
 */
final class PhutilFileLock extends PhutilLock {

  private $lockfile;
  private $handle;


/* -(  Constructing Locks  )------------------------------------------------- */


  /**
   * Create a new lock on a lockfile. The file need not exist yet.
   *
   * @param   string          The lockfile to use.
   * @return  PhutilFileLock  New lock object.
   *
   * @task construct
   */
  public static function newForPath($lockfile) {
    $lockfile = Filesystem::resolvePath($lockfile);

    $name = 'file:'.$lockfile;
    $lock = self::getLock($name);
    if (!$lock) {
      $lock = new PhutilFileLock($name);
      $lock->lockfile = $lockfile;
      self::registerLock($lock);
    }

    return $lock;
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
  protected function doLock() {
    $path = $this->lockfile;

    $handle = @fopen($path, 'a+');
    if (!$handle) {
      throw new FilesystemException(
        $path,
        "Unable to open lock '{$path}' for writing!");
    }

    $would_block = null;
    $ok = flock($handle, LOCK_EX | LOCK_NB, $would_block);

    if (!$ok) {
      fclose($handle);
      throw new PhutilLockException($this->getName());
    }

    $this->handle = $handle;
  }


  /**
   * Release the lock. Throws an exception on failure, e.g. if the lock is not
   * currently held.
   *
   * @return void
   *
   * @task lock
   */
  protected function doUnlock() {
    $ok = flock($this->handle, LOCK_UN | LOCK_NB);
    if (!$ok) {
      throw new Exception("Unable to unlock file!");
    }

    $ok = fclose($this->handle);
    if (!$ok) {
      throw new Exception("Unable to close file!");
    }

    $this->handle = null;
  }
}
