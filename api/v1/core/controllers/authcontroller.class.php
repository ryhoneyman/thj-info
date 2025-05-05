<?php

class AuthController extends DefaultController
{
   private $defaultTokenLifetime = null;

   public function __construct($debug = null, $main = null)
   {
      parent::__construct($debug,$main);
      $this->debug(5,'AuthController class instantiated');

      $this->defaultTokenLifetime = $main->var('apiSettings')['defaultTokenLifetime'];

      // This controller needs the database, so bring it online if it's not attached (we prepared it on startup)
      $this->main->attachDatabase();
   }

   public function authOAuth($request)
   {
      $this->debug(7,'method called');

      if (!preg_match('/^client_credentials$/i',$request->parameters['grant_type'])) {
         $this->statusCode       = 400;
         $this->statusMessage    = 'Bad Request';
         $this->content['error'] = 'unsupported_grant_type';
         return true;
      }

      // Decode the BASE64 credentials from the Authorization Basic header
      list($clientId,$clientSecret) = $this->apicore->decodeBasicAuth($request->auth);

      if (!$this->main->isDatabaseConnected()) {
         $this->statusCode       = 500;
         $this->statusMessage    = 'Server Error';
         $this->content['error'] = 'internal_error';
         return true;
      }

      $keydata = $this->apicore->getKeyData($clientId,$clientSecret);

      $request->keyId = $this->apicore->getApiKeyId($keydata);

      if (!$request->keyId) { 
         $this->statusCode       = 401;
         $this->statusMessage    = 'Unauthorized';
         $this->content['error'] = 'invalid_client';
         return true;
      }

      $request->token = $this->apicore->generateToken($request->keyId);
      $keydata        = $this->apicore->updateKeyDataToken($keydata,$request->token);  // place the new token back into the keydata object

      $this->debug(9,"new token: ".$request->token);

      $success = $this->apicore->updateToken($keydata,$keydata['token_lifetime'] ?? $this->defaultTokenLifetime);

      if (!$success) {
         $this->statusCode       = 500;
         $this->statusMessage    = 'Server Error';
         $this->content['error'] = 'internal_error';
         return true;
      }

      $this->statusCode              = 200;
      $this->statusMessage           = 'OK';
      $this->headers                 = array('Cache-Control: no-store','Pragma: no-cache');
      $this->content['access_token'] = $request->token;
      $this->content['token_type']   = 'bearer';
      $this->content['expires_in']   = $this->defaultTokenLifetime;

      return true;
   }

   public function authToken($request)
   {
      $this->debug(7,'method called');

      if (!$this->main->isDatabaseConnected()) {
         $this->statusCode       = 500;
         $this->statusMessage    = 'Database Error';
         $this->content['error'] = 'Database Connection Error';
         return true;
      }

      $keydata = $this->apicore->getKeyData($request->parameters['client_id'],$request->parameters['client_secret']);

      $this->debug(9,"keydata: ".json_encode($keydata));

      $request->keyId = $this->apicore->getApiKeyId($keydata);

      if (!$request->keyId) { 
         $this->statusCode       = 401;
         $this->statusMessage    = 'Unauthorized';
         $this->content['error'] = 'User Not Authorized';
         return true;
      }

      // We don't allow the keyId of 1 to be reset because it's our internal superuser
      if ($request->keyId == 1) {
         $this->statusCode       = 403;
         $this->statusMessage    = 'Forbidden';
         $this->content['error'] = 'Request Not Allowed';
         return true;
      }

      $request->token = $this->apicore->generateToken($request->keyId);
      $keydata        = $this->apicore->updateKeyDataToken($keydata,$request->token);  // place the new token back into the keydata object

      $this->debug(9,"new token: ".$request->token);

      $success = $this->apicore->updateToken($keydata,$keydata['token_lifetime'] ?? $this->defaultTokenLifetime);

      if (!$success) {
         $this->statusCode       = 500;
         $this->statusMessage    = 'Server Error';
         $this->content['error'] = 'Token Update Error';
         return true;
      }
      
      $this->statusCode       = 200;
      $this->statusMessage    = 'OK';
      $this->content['token'] = $request->token;

      return true;
   }

   public function authBasic($request)
   {
      $this->debug(7,'method called');

      if (!$this->main->isDatabaseConnected()) {
         $this->statusCode       = 500;
         $this->statusMessage    = 'Database Error';
         $this->content['error'] = 'Database Connection Error';
         return true;
      }

      // Decode the BASE64 credentials from the Authorization Basic header
      list($clientUser,$clientPass) = $this->apicore->decodeBasicAuth($request->auth);

      $keydata = $this->apicore->getKeyData($clientUser,$clientPass);

      $request->keyId = $this->apicore->getApiKeyId($keydata);

      if (!$request->keyId) { 
         $this->statusCode       = 401;
         $this->statusMessage    = 'Unauthorized';
         $this->content['error'] = 'User Not Authorized';
         return true;
      }

      $request->token = $this->apicore->generateToken($request->keyId);
      $keydata        = $this->apicore->updateKeyDataToken($keydata,$request->token);  // place the new token back into the keydata object

      $this->debug(9,"new token: ".$request->token);

      $success = $this->apicore->updateToken($keydata,$this->defaultTokenLifetime);

      if (!$success) {
         $this->statusCode       = 500;
         $this->statusMessage    = 'Server Error';
         $this->content['error'] = 'Token Update Error';
         return true;
      }

      $this->statusCode       = 200;
      $this->statusMessage    = 'OK';
      $this->content['token'] = $request->token;

      return true;
   }
}
