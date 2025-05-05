<?php

define('APP_BASEDIR','/opt/thj-info');
#define('APP_BASEDIR','/home/u690380958/domains/thj.bytelligence.com');  ### Hostinger
define('APP_LIBDIR',APP_BASEDIR.'/lib');
define('APP_WEBDIR',APP_BASEDIR.'/www');
define('APP_CACHEDIR',APP_BASEDIR.'/cache');   // dynamic cache
define('APP_CONFIGDIR',APP_BASEDIR.'/etc');    // static configurations
define('APP_DATADIR',APP_BASEDIR.'/data');     // static private file data
define('APP_VARDIR',APP_BASEDIR.'/var');       // dynamic file data
define('APP_LOGDIR',APP_BASEDIR.'/log');       // logs
define('APP_LOCALDIR',APP_BASEDIR.'/local');   // scripts for cron, daemons, scheduler
define('API_V1_BASEDIR',APP_BASEDIR.'/api/v1');
define('API_V1_COREDIR',API_V1_BASEDIR.'/core');
define('API_V1_CONFIGDIR',API_V1_BASEDIR.'/etc');        // v1 static configurations
define('API_V1_TOKENDIR',API_V1_BASEDIR.'/tokens');  // v1 cached tokens
define('API_V1_LOGDIR',API_V1_BASEDIR.'/log');       // v1 logs
define('APP_VENDORDIR',APP_BASEDIR.'/vendor');   // composer vendor loads

set_include_path(get_include_path().PATH_SEPARATOR.APP_LIBDIR.PATH_SEPARATOR.APP_VENDORDIR);

?>