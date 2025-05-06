<?php

class APICore extends LWPLib\Base
{
   protected $version = 1.0;

   public $tokenDir   = null;
   public $logDir     = null;
   public $db         = null;
   public $cacheFiles = null;

   public function __construct($debug = null, $db = null)
   {
       parent::__construct($debug);

       $this->db = $db;

       $this->cacheFiles = array('api_key_id','token','role_access','limit_rate','limit_concurrent',
                                 'ut_expires','ut_expire_rate','ut_expire_concurrent');
   }

   public function getPrivilegeList($userRoles = null)
   {
      $this->debug(7,'method called');

      $rbacList = array();
      $return   = array();

      //if (!is_null($roles)) { return $return; }

      $roleList = $this->buildAssignedRoleList($this->db->query("select name, access from api_role"),$userRoles);

      foreach ($roleList as $roleInfo) {
         if (!is_array($roleInfo)) { continue; }
         
         $privController = strtolower($roleInfo['controller']);                  // controller name specified in privilege
         $privFunction   = strtolower($roleInfo['function']);                    // function name specified in privilege
         $privMethod     = ($roleInfo['method']) ? $roleInfo['method'] : 'any';  // method name specified in privilege, if none then any

         $this->debug(9,"Found ACL($privController,$privFunction,$privMethod)");

         $anyController = (preg_match('/^any$/i',$privController)) ? true : false;
         $anyFunction   = (preg_match('/^any$/i',$privFunction)) ? true : false;
         $anyMethod     = (preg_match('/^any$/i',$privMethod)) ? true : false;

         // If we have a global wildcard allow, then other privileges won't matter
         if ($anyController && $anyFunction && $anyMethod) { return array("any:any:any"); }

         // Full wildcard controllers get priority in the list, followed by wildcard methods
         $priority = ($anyController) ? 1 : (($anyFunction) ? 2 : 3);

         $rbacList[$priority]["$privController:$privFunction:$privMethod"] = true; 
      }

      ksort($rbacList);

      foreach ($rbacList as $priority => $privileges) {
         $return = array_merge($return,array_keys($privileges));
      }
 
      return $return;
   }

   private function buildAssignedRoleList($roleData, $userRoles)
   {
      $this->debug(7,'method called');

      $return    = array();
      $groupList = array();

      // roleInfo is an array of key 'roles' (an array of child roles) or 'privilege' (an array of controller/function/method acls)

      foreach ($roleData as $roleName => $roleInfo) {
         $accessInfo = @json_decode($roleInfo['access'],true); 

         if (!is_array($accessInfo)) { continue; }

         $groupList[$roleName] = $accessInfo;
      } 

      foreach ($userRoles as $userRole) {
         $allRoles = $this->resolveGroupDependencies($userRole,$groupList,'roles');
         foreach ($allRoles as $roleName) {
            $return = array_merge($return,$groupList[$roleName]['privilege']);
         }
      }

      $this->debug(9,"assigned role list:".json_encode($return));

      return $return;
   }

   private function resolveGroupDependencies($needle, $haystack, $groupkey = 'roles')
   {
      $needles = array();
   
      if (!$haystack[$needle]) { return ""; }
      else if (!isset($haystack[$needle][$groupkey])) { return array($needle); }
      else {
         foreach ($haystack[$needle][$groupkey] as $newneedle) {
            $result = $this->resolveGroupDependencies($newneedle,$haystack,$groupkey);
            if ($result) { $needles = array_merge($needles,$result); }
         }
      }
   
      return $needles;
   }

   public function updateToken($keydata, $tokenExpireSecs = null)
   {
      $this->debug(7,'method called');

      if (!is_array($keydata) || !$keydata['token'] || !$keydata['api_key_id'] || !preg_match('/\s*$/',$this->tokenDir)) { return false; }

      if ($tokenExpireSecs === 0 || $tokenExpireSecs === '0') { $tokenExpireSecs = pow(2,32)-1; }

      $this->debug(9,"tokenExpireSecs: $tokenExpireSecs");

      // Fall through to 1 hour tokens if no defaults are set and no expiry is sent to use
      if (!$tokenExpireSecs) { $tokenExpireSecs = 3600; }

      $findsql = "select token from api_token where api_key_id = %d";
      $current = $this->db->query(sprintf($findsql,$keydata['api_key_id']),array('single' => true));

      $sql = "insert into api_token (api_key_id,token,role_access,limit_rate,count_rate,expire_rate,".
                                    "limit_concurrent,count_concurrent,expire_concurrent,last_used,".
                                    "created,expires) ".
             "values (%d,'%s','%s',%d,0,now(),%d,0,now(),now(),now(),now() + interval %d second) ".
             "on duplicate key update token = values(token), role_access = values(role_access), ".
             "limit_rate = values(limit_rate), limit_concurrent = values(limit_concurrent), ".
             "last_used = values(last_used), created = values(created), expires = values(expires)";

      $keydata['role_access']          = json_encode($keydata['role_access']);
      $keydata['ut_expires']           = time() + $tokenExpireSecs;
      $keydata['ut_expire_rate']       = 0;
      $keydata['ut_expire_concurrent'] = 0;

      $insert = sprintf($sql,$keydata['api_key_id'],$this->db->escapeString($keydata['token']),
                             $this->db->escapeString($keydata['role_access']),$keydata['limit_rate'],
                             $keydata['limit_concurrent'],$tokenExpireSecs);

      $dbrc = $this->db->execute($insert);

      if (!$dbrc) { 
         $this->debug(9,"could not insert into database: $insert"); 
         return false; 
      }

      // If we found a previous non-blank token, remove that token directory and it's files
      if (!preg_match('/^\s*$/',$current['token'])) {
         $oldApiTokenDir = $this->tokenDir.'/'.preg_replace('/\W/','',$current['token']);
         if (is_dir($oldApiTokenDir)) { 
            $this->debug(1,"removing $oldApiTokenDir");
            array_map('unlink', glob("$oldApiTokenDir/*"));
            rmdir($oldApiTokenDir);
         }
      }

      $newApiTokenDir = $this->tokenDir.'/'.preg_replace('/\W/','',$keydata['token']);

      if (!mkdir($newApiTokenDir,0700)) { 
         $this->debug(9,"could not make dir: $newApiTokenDir"); 
         return false; 
      }

      foreach ($this->cacheFiles as $file) { file_put_contents($newApiTokenDir.'/'.$file,$keydata[$file],LOCK_EX); }

      return true;
   }

   public function getApiKeyId($keyData)
   {
      return $keyData['api_key_id'];
   }

   public function getKeyData($clientId, $clientSecret)
   {
      $this->debug(7,'method called');

      $sql    = "select * from api_key where client_id = '%s' and client_secret = '%s'";
      $query  = sprintf($sql,$this->db->escapeString($clientId),$this->db->escapeString($clientSecret));
      $result = $this->db->query($query,array('single' => true));

      if ($result['roles']) {
         $this->debug(9,"roles detected:".$result['roles']);
         $roleList = @json_decode($result['roles'],true);
         if (is_array($roleList)) { $result['role_access'] = $this->getPrivilegeList($roleList); } 
         $this->debug(9,"roles assigned:".json_encode($result['role_access']));
      }

      $result['api_key_id'] = $result['id'];

      return $result;
   }

   public function updateKeyDataToken($keyData, $token)
   {
      $keyData['token'] = $token;
   
      return $keyData; 
   }

   public function getTokenData($token)
   {
      $this->debug(7,'method called');

      $apiTokenDir = $this->tokenDir.'/'.$token;

      $this->debug(7,"checking token directory");

      // If token is blank or the token directory doesn't exist, there's nothing to read
      if (preg_match('/^\s*$/',$token) || !is_dir($apiTokenDir)) { return array(); }

      $result = array('token' => $token);
   
      foreach ($this->cacheFiles as $basefile) {
         $fp = fopen($apiTokenDir.'/'.$basefile,'r+');
         if (flock($fp,LOCK_SH)) {
            $result[$basefile] = trim(fgets($fp));
            flock($fp,LOCK_UN);
         }
         else {
            $this->debug(0,"cant lock");
         }
         fclose($fp);
      }

      $statfiles = array('ratestart','concurrentstart','concurrentfinish');
      $stats     = array();

      foreach ($statfiles as $file) {
         $stats[$file] = @stat($apiTokenDir.'/'.$file);
      }

      $countRateStart        = isset($stats['ratestart'][7]) ? $stats['ratestart'][7] : 0;
      $countConcurrentStart  = isset($stats['concurrentstart'][7]) ? $stats['concurrentstart'][7] : 0;
      $countConcurrentFinish = isset($stats['concurrentfinish'][7]) ? $stats['concurrentfinish'][7] : 0;

      $result['count_rate'] = $countRateStart;
      $result['count_concurrent'] = $countConcurrentStart - $countConcurrentFinish;

      return $result;
   }

   public function getTokenDataDatabase($token)
   {
      $this->debug(7,'method called');

      $sql    = "select *, unix_timestamp(expires) as ut_expires, ".
                       "unix_timestamp(expire_rate) as ut_expire_rate, ".
                       "unix_timestamp(expire_concurrent) as ut_expire_concurrent ".
                "from api_token where apitoken = '%s'";
      $query  = sprintf($sql,$this->db->escapeString($token));
      $result = $this->db->query($query,array('single' => true));

      return $result;
   }

   public function getTokenFromKey($keyId, $validate = 1)
   {
      $this->debug(7,'method called');

      $sql    = "select apitoken from api_token where api_key_id = %d ".
                (($validate) ? "and expires >= now()" : '');
      $query  = sprintf($sql,$keyId);
      $result = $this->db->query($query,array('single' => true));

      return $result['apitoken'];
   }

   public function addTokenCounter($token)
   {
      $this->debug(7,'method called');

      $changes = array();

      if ($token->rateReset) {
         @file_put_contents($this->tokenDir.'/'.$token->value.'/ratestart','',LOCK_EX);
         @file_put_contents($this->tokenDir.'/'.$token->value.'/ut_expire_rate',$token->now + $token->rateInterval,LOCK_EX);
      }
      else if (!$token->rateExceeds) {
         @file_put_contents($this->tokenDir.'/'.$token->value.'/ratestart','.',FILE_APPEND|LOCK_EX);
      }

      if ($token->concurrentReset) {
         @file_put_contents($this->tokenDir.'/'.$token->value.'/concurrentstart','',LOCK_EX);
         @file_put_contents($this->tokenDir.'/'.$token->value.'/concurrentfinish','',LOCK_EX);
         @file_put_contents($this->tokenDir.'/'.$token->value.'/ut_expire_concurrent',$token->now + $token->concurrentInterval,LOCK_EX);
      }
      else if (!$token->rateExceeds) {
         @file_put_contents($this->tokenDir.'/'.$token->value.'/concurrentstart','.',FILE_APPEND|LOCK_EX);
      }

      return true;
   }

   public function addTokenCounterDatabase($token)
   {
      $this->debug(7,'method called');

      $changes = array();
  
      if ($token->rateReset) {
         $changes[] = sprintf("count_rate = 0, expire_rate = now() + interval %d second",$token->rateInterval);
      }
      else if (!$token->rateExceeds) {
         $changes[] = "count_rate = count_rate + 1";
      }

      if ($token->concurrentReset) {
         $changes[] = sprintf("count_concurrent = 0, expire_concurrent = now() + interval %d second",$token->concurrentInterval);
      }
      else if (!$token->rateExceeds) {
         $changes[] = "count_concurrent = count_concurrent + 1";
      }

      if (empty($changes)) { return true; } 

      $sql = "update api_token set ".implode(', ',$changes).", lastused = now() ".
             "where api_key_d = %d";

      $query   = sprintf($sql,$token->keyId);
      $success = $this->db->execute($query);

      $this->debug(9,"sql: [rc:$success] $query");

      return (($success) ? true : false);
   }

   public function removeTokenCounter($token)
   {
      $this->debug(7,'method called');

      $changes = array();

      if ($token->concurrentCount <= 0) { return true; }

      $result = @file_put_contents($this->tokenDir.'/'.$token->value.'/concurrentfinish','.',FILE_APPEND|LOCK_EX);

      return (($result !== false) ? true : false);
   }

   public function removeTokenCounterDatabase($token)
   {
      $this->debug(7,'method called');

      $changes = array();

      if ($token->concurrentCount <= 0) { return true; }

      $sql = "update api_token set count_concurrent = count_concurrent - 1, lastused = now() ".
             "where api_key_id = %d";

      $query   = sprintf($sql,$this->db->escapeString($token->keyId));
      $success = $this->db->execute($query);

      $this->debug(9,"sql: [rc:$success] $query");

      return (($success) ? true : false);
   }

   public function logApiRequestFile($token, $request, $response, $elapsedtime)
   {
      $this->debug(7,'method called');

      $format = "('%s','%s',%d,'%s','%s',%d,'%s',%1.5f,%d)\n";
      $entry  = sprintf($format,gmdate('Y-m-d H:i:s'),$request->clientIp,$token->keyId,$request->pathInfo,$request->method,$response->statusCode,
                                $response->statusMessage,$elapsedtime,$response->contentLength);

      $result = file_put_contents($this->logDir.'/api.request.log',$entry,FILE_APPEND|LOCK_EX);

      return (($result !== false) ? true : false);
   }

   public function logApiRequest($token, $request, $response, $elapsedtime)
   {
      $this->debug(7,'method called');

      $sql = "insert into api_log (accessed,client_ip,api_key_id,request,method,status_code,status_mesg,elapsed_sec,bytes) ".
             "values (now(),%d,'%s','%s',%d,'%s',%1.5f,%d)";

      // Need to add client IP address to the log table

      $insert = sprintf($sql,$this->db->escapeString($request->clientIp),$token->keyId,
                             $this->db->escapeString($request->pathinfo),$this->db->escapeString($request->method),
                             $response->statusCode,$this->db->escapeString($response->statusMessage),
                             $elapsedtime,$response->contentLength);

      $success = $this->db->execute($insert);

      return (($success) ? true : false);
   }

   public function authorize($accessList, $controllerName, $functionName, $method)
   {
      $this->debug(7,'method called');

      if (!is_array($accessList)) { return false; }

      $this->debug(9,"loaded access controls: ".count($accessList)." found");

      // Linear match works faster than constructed hash lookup or multi-regex pattern
      // when element list is smaller, under hundreds of items.  Consider optimizing
      // this if element list is in the tens of thousands.
      foreach ($accessList as $accessItem) {
         list($accessController,$accessFunction,$accessMethod) = explode(':',$accessItem);

         $this->debug(9,"ACL($accessController,$accessFunction,$accessMethod) User($controllerName,$functionName,$method)");

         $matchController = (preg_match("~^($controllerName|any)$~i",$accessController)) ? true : false;
         $matchFunction   = (preg_match("~^($functionName|any)$~i",$accessFunction)) ? true : false;
         $matchMethod     = (preg_match("~^($method|any)$~i",$accessMethod)) ? true : false;

         $this->debug(9,"ACL match: controller($matchController) function($matchFunction) method($matchMethod)");

         if ($matchController && $matchFunction && $matchMethod) { return true; }
      }

      $this->debug(9,"no match for access");

      return false;
   }

   public function generateKey($unique)
   {
      $this->debug(7,'method called');

      $apikey = hash('sha1',microtime().$unique,false);

      $this->debug(9,"generated key: $apikey");

      return $apikey;
   }

   public function generateToken($keyId)
   {
      $this->debug(7,'method called');

      $apitoken = hash('sha256',microtime().$keyId,false);

      $this->debug(9,"generated token: $apitoken");

      return $apitoken;
   }

   public function decodeBasicAuth($requestAuth)
   {
      return explode(':',base64_decode(preg_replace('/^basic\s+/i','',$requestAuth)));
   }

   public function isAuthRequest($routerCategory)
   {
      return ($routerCategory && preg_match('/^authentication$/i',$routerCategory)) ? true : false;
   }

}
