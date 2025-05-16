<?php

include_once 'common/apibase.class.php';

class MyAPI extends LWPLib\APIBase
{
   protected $version         = 1.0;
   private   $defaultDatabase = 'eqecho';
   
   /**
    * __construct
    *
    * @param  LWPLib\Debug|null $debug
    * @param  array|null $options
    * @return void
    */
    public function __construct($debug = null, $options = null)
    {
        parent::__construct($debug,$options);

        $this->authType('auth.header.bearer');

        $this->loadUris([
            'v1-authenticate'              => '/api/v1/auth/token',
            'v1-data-provider-query'       => '/api/v1/data/provider/query/{{database}}',
            'v1-data-provider-query-table' => '/api/v1/data/provider/query/{{database}}/{{table}}',
            'v1-data-provider-execute'     => '/api/v1/data/provider/execute/{{database}}',
        ]);
    }

    public function removeMessagesFromMessageQueue($messageIds)
    {
        if (!is_array($messageIds)) { $messageIds = [$messageIds]; }

        $statement = 'DELETE FROM message_queue WHERE id in ('.implode(',',array_fill(0,count($messageIds),'?')).')';
        $types     = str_repeat('i',count($messageIds));
        $data      = $messageIds;
        $result    = $this->v1DataProviderBindExecute($this->defaultDatabase,$statement,$types,$data);

        if (!$result) {
            $this->error($this->clientError());
            return false;
        }

        return true;
    }

    public function getMessageQueue()
    {
        $statement = 'SELECT * FROM message_queue LIMIT 50';
        $result    = $this->v1DataProviderBindQuery($this->defaultDatabase,$statement);

        if (!$result) {
            $this->error($this->clientError());
            return false;
        }

        return $result['data']['results'];
    }

    public function sendMessage($discordId, $message)
    {
        $statement = 'INSERT INTO message_queue (discord_id,message,created) VALUES (?,?,now())';
        $types     = 'ss';
        $data      = [$discordId,$message];

        $result = $this->v1DataProviderBindExecute($this->defaultDatabase,$statement,$types,$data);

        if (!$result) {
            $this->error($this->clientError());
            return false;
        }

        return true;
    }

    public function getAccount($lookupValue, $lookupColumn = null)
    {
        $lookupColumns = [
            'id'               => 'i',
            'api_key_id'       => 'i',
            'discord_id'       => 's',
            'discord_username' => 's',
        ];

        if (is_null($lookupColumn) || !isset($lookupColumns[$lookupColumn])) { $lookupColumn = 'api_key_id'; }

        $statement = "SELECT * from account where $lookupColumn = ?";
        $types     = $lookupColumns[$lookupColumn];
        $data      = [$lookupValue];
        $result    = $this->v1DataProviderBindQuery($this->defaultDatabase,$statement,$types,$data,['single' => true]);

        if (!$result) {
            $this->error($this->clientError());
            return false;
        }

        return $result['data']['results'];
    }

    public function getCharacterData($discordId)
    {
        $statement = 'SELECT cd.name, cd.server, cd.level, cd.aa_points, cd.powerslot_item, cd.powerslot_percent, unix_timestamp(cd.updated) as updated FROM character_data cd LEFT JOIN account a ON a.id = cd.account_id WHERE a.discord_id = ?';
        $types     = 's';
        $data      = [$discordId];

        $result = $this->v1DataProviderBindQuery($this->defaultDatabase,$statement,$types,$data);

        if (!$result) {
            $this->error($this->clientError());
            return false;
        }

        return $result['data']['results'];
    }

    public function regenerateApiSecret($apiKeyId) {
        $clientSecret = bin2hex(random_bytes(32));

        $statement = 'UPDATE api_key SET client_secret = ?, updated = now() WHERE id = ?';
        $types     = 'si';
        $data      = [$clientSecret,$apiKeyId];

        $result = $this->v1DataProviderBindExecute($this->defaultDatabase,$statement,$types,$data);

        if (!$result) {
            $this->error($this->clientError());
            return false;
        }

        return $clientSecret;
    }

    public function generateApiKey($requestor, $roles, $limitRate = null, $limitConcurrent = null, $tokenLifetime = null, $description = null)
    {
        $clientId     = bin2hex(random_bytes(32));
        $clientSecret = bin2hex(random_bytes(32));

        $statement = 'INSERT INTO api_key (client_id,client_secret,requestor,description,roles,limit_rate,limit_concurrent,token_lifetime,created,updated) VALUES (?,?,?,?,?,?,?,?,now(),now())';
        $types     = 'sssssiii';
        $data      = [$clientId,$clientSecret,$requestor,$description,json_encode($roles ?? [],JSON_UNESCAPED_SLASHES),$limitRate,$limitConcurrent,$tokenLifetime];
    
        $result = $this->v1DataProviderBindExecute($this->defaultDatabase,$statement,$types,$data);
           
        if (!$result) {
            $this->error($this->clientError());
            return false;
        }

        if (!isset($result['insertId'])) {
            $this->error("Error creating api key: ".$this->clientError());
            return false;
        }
        
        return ['api_key_id' => $result['insertId'], 'client_id' => $clientId, 'client_secret' => $clientSecret];
    }

    public function registerAccount($discordUserId, $discordUsername)
    {
        $this->debug(8,"called, discordUserId: $discordUserId, discordUsername: $discordUsername");

        $userResults = $this->v1DataProviderBindQuery($this->defaultDatabase,'SELECT a.api_key_id, ak.client_id FROM account a LEFT JOIN api_key ak ON ak.id = a.api_key_id WHERE a.discord_id = ?','s',[$discordUserId],['single' => true]);

        if (!$userResults) {
            $this->error("Error checking api key: ".$this->clientError());
            return false;
        }

        $userData     = $userResults['data']['results'];
        $userApiKeyId = $userData['api_key_id'];
        $clientId     = $userData['client_id'];

        if (!$userApiKeyId) {
            $generateResult = $this->generateApiKey("$discordUserId@discord",['discord-log'],5,3);

            if (!$generateResult) { return false; }  // error set in generateApiKey

            $userApiKeyId = $generateResult['api_key_id'];
            $clientId     = $generateResult['client_id'];
            $clientSecret = $generateResult['client_secret'];

            $createInfoResult = $this->v1DataProviderBindExecute($this->defaultDatabase,'INSERT INTO account (api_key_id,discord_id,discord_username,created,updated) VALUES (?,?,?,now(),now())','iss',[$userApiKeyId,$discordUserId,$discordUsername]);

            if (!$createInfoResult) {
                $this->error("Error creating account: ".$this->clientError());
                return false;
            }
        }
        else {
            $clientSecret = $this->regenerateApiSecret($userApiKeyId);

            if (!$clientSecret) {
                $this->error("Error updating api key: ".$this->clientError());
                return false;
            }
        }

        $return = ['client_id' => $clientId, 'client_secret' => $clientSecret];

        return $return;
    }

    public function v1DataProviderBindExecute($database, $statement, $types = null, $data = null, $options = null)
    {
        $request = [
            'params' => ['database' => $database],
            'data' => [
                'statement' => $statement, 
                'types'     => $types,
                'data'      => $data,
            ],
            'options' => [
                'method' => 'POST',
            ]
        ];

        if (!$this->makeRequest('v1-data-provider-execute','auth,json',$request)) { 
            $this->error($this->clientError());
            return false; 
        }

        return $this->clientResponse();
    }

    public function v1DataProviderBindQuery($database, $statement, $types = null, $data = null, $options = null)
    {
        $single = $options['single'] ?: false;

        $request = [
            'params' => ['database' => $database],
            'data' => [
                'statement' => $statement, 
                'types'     => $types,
                'data'      => $data,
                'single'    => $single
            ],
            'options' => [
                'method' => 'POST',
            ]
        ];

        if (!$this->makeRequest('v1-data-provider-query','auth,json',$request)) { 
            $this->error($this->clientError());
            return false; 
        }

        return $this->clientResponse();
    }

    /**
        * v1DataProviderTableData
        *
        * @param  string $database
        * @param  string $table
        * @param  array|null $options
        * @return mixed
        */
    public function v1DataProviderTableData($database, $table, $options = null)
    {
        $options = (is_array($options)) ? $options : [];

        $request = [
            'params' => ['database' => $database, 'table' => $table],
            'data' => $options,
            'options' => [
                'method' => 'POST',
            ]
        ];

        if (!$this->makeRequest('v1-data-provider-query-table','auth,json',$request)) { 
            $this->error($this->clientError());
            return false; 
        }

        return $this->clientResponse();
    }

    /**
    * v1Authenticate
    *
    * @param  string $clientId
    * @param  string $clientSecret
    * @return bool
    */
    public function v1Authenticate($clientId, $clientSecret)
    {
        $request = [
            'data' => ['client_id' => $clientId, 'client_secret' => $clientSecret],
            'options' => [
                'timeout' => 15,
            ],
        ];

        $requestResult = $this->makeRequest('v1-authenticate','json',$request);

        if ($requestResult === false) { $this->error($this->clientError()); return false; }

        $token = $this->clientResponseValue('token');

        if (!$token) { $this->error('Could not authenticate'); return false; }

        $this->authToken = $token;

        return true;
    } 
}