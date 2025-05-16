<?php

class LogController extends DefaultController
{
   protected $logModel = null;
   
   /**
    * __construct
    *
    * @param  LWPLib\Debug|null $debug
    * @param  Main|null $main
    * @return void
    */
   public function __construct($debug = null, $main = null)
   {
      parent::__construct($debug,$main);

      $this->debug(5,get_class($this).' class instantiated');

      $this->logModel = new LogModel($debug,$main); 

      // If the model isn't ready we need to flag the controller as not ready and set status
      if (!$this->logModel->ready)  { $this->notReady($this->logModel->error); return; }
   }

   /**
    * processLog
    *
    * @param  array $logEntries
    * @return bool
    */
   public function processLog($request)
   {
      $this->debug(5,'processLog called');

      $parameters = $request->parameters;
      $filterData = $request->filterData;

      if (!$this->logModel->ready)  { $this->notReady($this->logModel->error); return false; }

      $characterName = $parameters['characterName'] ?? null;
      $serverName    = $parameters['serverName'] ?? null;
      $logEntries    = $parameters['log'];

      if (!is_array($logEntries)) { return $this->standardError('invalid log data, expecting array',422,'Unprocessable Entity'); }

      $result = $this->logModel->processLog($characterName,$serverName,$logEntries);

      if ($result === false) { return $this->standardError($this->logModel->error); }

      return $this->standardOk(true);
   }

   public function getPhrases($request)
   {
      $this->debug(5,'getPhrases called');

      $parameters = $request->parameters;
      $filterData = $request->filterData;

      if (!$this->logModel->ready)  { $this->notReady($this->logModel->error); return false; }

      $result = $this->logModel->getPhrases();

      if ($result === false) { return $this->standardError($this->logModel->error); }

      return $this->standardOk(['data' => $result]);
   }
}
