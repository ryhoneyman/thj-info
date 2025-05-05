<?php

abstract class DefaultModel extends LWPLib\Base
{
   public    bool            $ready         = true;
   public    ?string         $error         = null;
   public    ?Main           $main          = null;
   public    ?APICore        $apicore       = null;
   public    ?MyAPI          $api           = null;
   
   public function __construct(?LWPLib\Debug $debug = null, ?Main $main = null)
   {
      parent::__construct($debug);

      if (!$main || !$main->obj('apicore')) { $this->notReady('Cannot locate main libraries'); return; }

      if (!$main->loadDefinesFromDB('MY_API_%')) { $this->notReady('Cannot load database defines'); return; };

      $apiOptions = ['baseUrl' => MY_API_URL, 'authToken' => MY_API_AUTH_TOKEN];

      if (!$main->buildClass('api','MyAPI',$apiOptions,'local/myapi.class.php')) { $this->notReady("API not available"); return; }

      if ($this->ready) {
         $this->main    = $main;
         $this->apicore = $main->obj('apicore');
         $this->api     = $main->obj('api');
      }
   }

   public function notReady($error)
   {
      $this->debug(9,'Model signaling not ready');

      $this->ready = false;
      $this->error = $error;

      return true;
   }
}
