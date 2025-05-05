<?php

class Token extends LWPLib\Base
{
   public $now          = null;   // The current unix time
   public $value        = null;   // Value of token
   public $keyId        = null;   // Key ID associated with token
   public $expires      = null;   // Epoch time of token expiration
   public $exists       = null;   // If true, token has been verified to exist in the database
   public $expired      = null;   // If true, token is expired
   public $valid        = null;   // If true, token is valid (verified and not expired)
   public $roleAccess   = null;   // JSON of access controls allowed for this token
   public $accessList   = null;   // Array of access controls allowed for this token
   public $limitExceeds = null;   // If true, token has met or exceeded a limit
   public $superUser    = null;   // If true, token is elevated to highest privilege (reserved for our own API calls)

   public $rateLimit    = null;   // Rate request limit
   public $rateCount    = null;   // Rate request count
   public $rateExpires  = null;   // Rate request expiration
   public $rateExceeds  = false;  // If true, token rate limit is exceeded
   public $rateReset    = false;  // If true, rate counts need to be reset
   public $rateInterval = 1;      // Seconds that rate counts should be reset

   public $concurrentLimit    = null;   // Concurrent request limit
   public $concurrentCount    = null;   // Concurrent request count
   public $concurrentExpires  = null;   // Concurrent request expiration
   public $concurrentExceeds  = false;  // If true, token concurrent limit is exceeded
   public $concurrentReset    = false;  // If true, concurrent counts need to be reset
   public $concurrentInterval = 1;      // Seconds that concurrent counts should be reset

   public function __construct($debug = null, $options = null) 
   {
      parent::__construct($debug);
      $this->debug(5,'Token class instantiated');
 
      $this->now = time();
   }

   public function mapData($data)
   {
      // Maps retieved database values to our standard token values
      // We do this so we can change the columns in the database without making changes 
      // across the API.  This is the only area that changes need to be applied, if columns change

      $this->value      = $data['token'];
      $this->keyId      = $data['api_key_id'];
      $this->expires    = $data['ut_expires'] ?: 0;
      $this->exists     = ($this->value && $this->keyId) ? true : false;
      $this->expired    = ($this->expires <= $this->now) ? true : false;
      $this->valid      = ($this->exists && !$this->expired) ? true : false;
      $this->roleAccess = $data['role_access'];
      $this->accessList = @json_decode($this->roleAccess,true);
      $this->superUser  = ($this->keyId == 1) ? true : false;

      $this->rateLimit   = $data['limit_rate'];
      $this->rateCount   = $data['count_rate'];
      $this->rateExpires = $data['ut_expire_rate'] ?: 0;
      $this->rateExceeds = ($this->rateCount && ($this->rateCount >= $this->rateLimit)) ? true : false;
      $this->rateReset   = ($this->rateExpires < $this->now) ? true : false;

      $this->concurrentLimit   = $data['limit_concurrent'] ?: 1;
      $this->concurrentCount   = $data['count_concurrent'] ?: 0;
      $this->concurrentExpires = $data['ut_expire_concurrent'] ?: 0;
      $this->concurrentExceeds = ($this->concurrentCount && ($this->concurrentCount >= $this->concurrentLimit)) ? true : false;
      $this->concurrentReset   = ($this->concurrentExpires < $this->now) ? true : false;

      $this->limitExceeds = ($this->concurrentExceeds || $this->rateExceeds) ? true : false;
   }
}
