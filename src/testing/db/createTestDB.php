#!/usr/bin/php
<?php
/*
 Copyright (C) 2011 Hewlett-Packard Development Company, L.P.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 version 2 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

/**
 * \brief Create a fossology test database, test configuration directory and
 * test repository.
 *
 * The database name will be unique. The program will print to standard out the
 * path to the fossology test configuration directory where the Db.conf
 * file will be that contains the name of the DB.  The program will create a
 * DB user called fossy with password fossy.
 *
 * The name of the testrepo will be in the fossology.conf file in the test
 * configuration directory.
 *
 * This program can be called to drop the DB and clean up.
 *
 * @version "$Id$"
 *
 * Created on Sep 14, 2011 by Mark Donohoe
 */


require_once(__DIR__ . '/../../lib/php/bootstrap.php');
require_once(__DIR__ . '/../lib/libTestDB.php');

$Options = getopt('c:d:sh');
$usage = $argv[0] . ": [-h] -c path [-d name] [-s]\n" .
  "-c path:  The path to the fossology system configuration directory\n" .
  "-d name:  Drop the named data base.\n" .
  "-h:       This message (Usage)\n" .
  "-s:       Start the scheduler with the new sysconfig directory\n" .
  "Examples: create a test DB: createTestDb.php \n" .
  "          Drop the database fosstest1537938: createTestDb.php -d fosstest1537938\n";

if(array_key_exists('h',$Options))
{
  print "$usage\n";
  exit(0);
}
$sysconfig = NULL;
// use the passed in sysconfdir to start with
if(array_key_exists('c', $Options))
{
  $sysconfig = $Options['c'];
  if(empty($sysconfig))
  {
    echo $usage;
    exit(1);
  }
}
/*
 * Drop DataBase
 * @todo make this code also clean up the conf dir and repo
 */
if(array_key_exists('d', $Options))
{
  $dropName = $Options['d'];
  if(empty($dropName))
  {
    echo $usage;
    exit(1);
  }
  // check that postgresql is running
  $ckCmd = "sudo su postgres -c 'echo \\\q | psql'";
  $lastCmd = exec($ckCmd, $ckOut, $ckRtn);
  if($ckRtn != 0)
  {
    echo "ERROR: postgresql isn't running, not deleting database $name\n";
    exit(1);
  }
  $existCmd = "sudo su postgres -c 'psql -l' |grep -q $dropName";
  $lastExist = exec($existCmd, $existkOut, $existRtn);
  if($existRtn == 0)
  {
    // drop the db
    # stop all users of the fossology db
    $pkillCmd ="pkill -f -u postgres fossy || true";
    $lKill = exec($pkillCmd, $killOut, $killRtn);
    $dropCmd = "sudo su postgres -c 'echo \"drop database $dropName;\"|psql'";
    $lastDrop = exec($dropCmd, $dropOut, $dropRtn);
    if($dropRtn != 0 )
    {
      echo "ERROR: failed to delete database $dropName\n";
      exit(1);
    }
  }
  else
  {
    echo "NOTE: database $dropName does not exist, nothing to delete\n";
  }
  exit(0);
}
$startSched = FALSE;
if(array_key_exists('s', $Options))
{
  $startSched = TRUE;
}

// If not passed in, in see if we can get SYSCONFDIR from the environment,
// if not, stop
if(empty($sysconfig))
{
  $sysconfig = getenv('SYSCONFDIR');
  if(empty($sysconfig))
  {
    echo "FATAL!, no SYSCONFDIR defined\n";
    echo "either export SYSCONFDIR path and rerun or use -c <sysconfdirpath>\n";
    flush();
    exit(1);
  }
}
//echo "DB: sysconfig is:$sysconfig\n";

putenv("SYSCONFDIR=$sysconfig");
$SysConf = bootstrap();
//echo "DB: Sys Config vars are:\n";print_r($SysConf) . "\n";

$unique = mt_rand();
$DbName = 'fosstest' . $unique;
//echo "DbName is:$DbName\n";

// create the db
$newDB = CreateTestDB($DbName);
if($newDB != NULL)
{
  echo "ERROR, could not create database $name\n";
  echo $newDB;
  exit(1);
}

$confName = 'testDbConf' . $unique;
$confPath = "/srv/fossology/$confName";
$repoName = 'testDbRepo' . $unique;
$repoPath = "/srv/fossology/$repoName";

// sysconf and repo's always go in /srv/fossology to ensure enough room.
// perms are 755
if(mkdir($confPath) === FALSE)
{
  echo "FATAL! Cannot create test sysconf at:$confPath\n" .
  __FILE__ . " at line " . __LINE__ . "\n";
  exit(1);
}
if(chmod($confPath, 0755) === FALSE )
{
  echo "ERROR: Cannot set mode to 755 on $confPath\n" .
  __FILE__ . " at line " . __LINE__ . "\n";
}
if(mkdir($repoPath) === FALSE)
{
  echo "FATAL! Cannot create test repository at:$repoPath\n" .
  __FILE__ . " at line " . __LINE__ . "\n";
  exit(1);
}
if(chmod($repoPath, 0755) === FALSE )
{
  echo "ERROR: Cannot set mode to 755 on $repoPath\n" .
  __FILE__ . " at line " . __LINE__ . "\n";
}
//create Db.conf file
// Should the host be what's in fossology.conf?
$conf = "dbname=$DbName;\n" .
  "host=localhost;\n" .
  "user=fossy;\n" .
  "password=fossy;\n";

if(file_put_contents($confPath . "/Db.conf", $conf) === FALSE)
{
  echo "FATAL! Could not create Db.conf file at:$confPath\n";
  exit(1);
}

// copy and modify fossology.conf
$fossConf = $sysconfig . '/fossology.conf';
$myConf  = $confPath . '/fossology.conf';

if(file_exists($fossConf))
{
  if(copy($fossConf, $myConf) === FALSE)
  {
    echo "FATAL! cannot copy $fossConf to $myConf\n";
    exit(1);
  }
}
if(setRepo($confPath, $repoPath) === FALSE)
{
  echo "ERROR!, could not change $sysconfig/fossology.conf, please change by " .
    "hand before running tests\n";
  exit(1);
}

// copy mods-enabled from real sysconf.
$modConf = $sysconfig . '/mods-enabled';
$cmd = "cp -RP $modConf $confPath";
if(system($cmd) === FALSE)
{
  echo "DB: Cannot copy diretory $modConf to $confPath\n";
  exit(1);
}

// copy VERSION from real sysconf.
$VERSION = $sysconfig . '/VERSION';
$myVERSION = $confPath . '/VERSION';
if(copy($VERSION, $myVERSION) === FALSE)
{
  echo "FATAL! Cannot copy $VERSION to $myVERSION\n";
  exit(1);
}

// load the schema
$loaded = TestDBInit(NULL, $DbName, $confPath);
if($loaded !== NULL)
{
  echo "ERROR, could not load schema\n";
  echo $loaded;
  exit(1);
}

// export to environment the new sysconf dir
// The update has to happen before schema-update gets called or schema-update
// will not end up with the correct sysconf

putenv("SYSCONFDIR=$confPath");
$_ENV['SYSCONFDIR'] = $confPath;
$GLOBALS['SYSCONFDIR'] = $confPath;

// scheduler should be in $MODDIR/scheduler/agent/fo_scheduler
if($startSched)
{
  $skedOut = array();
  $cmd = "sudo $MODDIR/scheduler/agent/fo_scheduler -d -c $confPath";
  $skedLast = exec($cmd, $skedOut, $skedRtn);
  if($skedRtn != 0)
  {
    echo "FATAL! could not start scheduler with -d -c $confPath\n";
    echo implode("\n", $skedOut) . "\n";
    exit(1);
  }
}
echo $confPath . "\n";
exit(0);
?>