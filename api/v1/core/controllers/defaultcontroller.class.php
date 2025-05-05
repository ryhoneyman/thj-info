<?php

abstract class DefaultController extends LWPLib\Base
{
   public $statusCode    = 405;
   public $statusMessage = 'Method Not Defined';
   public $headers       = array();
   public $content       = array();
   public ?Main $main    = null;
   public $apicore       = null;
   public $ready         = true;
   
   /**
    * __construct
    *
    * @param  LWPLib\Debug|null $debug
    * @param  Main|null $main
    * @return void
    */
   public function __construct($debug = null, $main = null)
   {
      parent::__construct($debug);

      if (!$main || !$main->obj('apicore')) {
         $this->notReady('Cannot locate main libraries',null,null);
      }

      if ($this->ready) {
         $this->main    = $main;
         $this->apicore = $main->obj('apicore');
      }
   }

   public function notReady($contentError = null, $code = null, $statusMessage = null)
   {
      $this->ready = false;

      $this->debug(5,sprintf("Controller signaling not ready: code(%s) status(%s) error(%s)",$code,$statusMessage,$contentError));

      return $this->standardError($contentError,$code,$statusMessage);
   }

   public function standardNotFound($content = null, $code = null, $statusMessage = null)
   {
      $this->statusCode    = $code ?: 404; 
      $this->statusMessage = $statusMessage ?: 'Not Found';
      $this->content       = $content ?: ['error' => 'Not Found'];

      return true;
   }

   public function standardError($contentError = null, $code = null, $statusMessage = null)
   {
      $this->statusCode    = $code ?: 500; 
      $this->statusMessage = $statusMessage ?: 'Server Error';
      $this->content       = ['error' => $contentError ?: 'An error occurred'];

      return true;
   }

   public function standardOk($content = null, $code = null, $statusMessage = null)
   {
      $this->statusCode    = $code ?: 200; 
      $this->statusMessage = $statusMessage ?: 'OK';
      $this->content       = $content;

      return true;
   }
}
