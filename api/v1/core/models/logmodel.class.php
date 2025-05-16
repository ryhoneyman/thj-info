<?php

/**
 * DataModel
 */
class LogModel extends DefaultModel
{ 
   public function __construct($debug = null, $main = null)
   {
      parent::__construct($debug,$main);
   }

   public function getPhrases()
   {
      return [
         'You gain experience!!',
         'Your .* absorbs energy',
         'Your .* spell has worn',
         //'\\S+ tells you, \'Attacking',
         'I have \\S+ percent',
         'You have gained an ability point'
      ];
   }

   public function processLog($characterName, $serverName, $logEntries)
   {
      if ($this->main->connectDatabase() === false) { $this->error('database not available'); return false; }

      if (!$apiKeyId = $this->main->obj('token')->keyId) { $this->error('invalid api key'); return false; };

      if (!is_array($logEntries)) { $logEntries = [$logEntries]; }

      $accountInfo = $this->api->getAccount($apiKeyId);

      $this->debug(9,"ACCOUNT INFO: ".json_encode($accountInfo));
      $this->debug(9,"CHARACTER NAME: $characterName");
      $this->debug(9,"SERVER NAME: $serverName");
      $this->debug(9,"LOG ENTRIES: ".json_encode($logEntries));

      $info = [];

      foreach ($logEntries as $logEntry) {
         if (preg_match('/^\[(.*?)\]/',$logEntry,$match)) {
            $entryTs = strtotime($match[1]);
            $logEntry = preg_replace('/^\[(.*?)\]\s+/','',$logEntry);
         }
      
         if (preg_match('/you now have (\d+) ability points/i',$logEntry,$match)) {
            $info['aa_points'] = $match[1];
         }
         else if (preg_match('/your \[(.*?)\] absorbs energy,.*\((\S+)%\)/i',$logEntry,$match)) {
            $info['powerslot_item']    = $match[1];
            $info['powerslot_percent'] = $match[2];
         }
         else if (preg_match('/(\S+) tells you, \'I have (\S+) percent of my (?:hit|hot) points left, master/i',$logEntry,$match)) {
            $petName = $match[1];
            $info['pet'][$petName]['health'] = $match[2];
         }
         else if (preg_match('/(\S+) tells you, \'Attacking (.*?) Master.\'/i',$logEntry,$match)) {
            $petName = $match[1];
            $info['pet'][$petName]['attacking'] = $match[2];
         }
      }

      $characterColumns = [
         'aa_points'         => ['type' => 'i', 'name' => 'aa_points', 'alert' => true ],
         'powerslot_item'    => ['type' => 's', 'name' => 'powerslot_item'],
         'powerslot_percent' => ['type' => 'd', 'name' => 'powerslot_percent', 'alert' => true ],
      ];

      $updateFields = [];

      foreach ($characterColumns as $columnKey => $columnInfo) {
         $updateColumn = $columnInfo['name'];
         $alertEnabled = $columnInfo['alert'] ?? false;

         if (isset($info[$columnKey])) { 
            $updateValue = $info[$columnKey];

            $updateFields[$updateColumn] = ['type' => $columnInfo['type'], 'value' => $updateValue];
            
            if ($alertEnabled) {
               $alertMessage = null;

               if ($columnKey == 'powerslot_percent' && $updateValue >= 100 && preg_match('/\(enchanted\)$/i',$info['powerslot_item'])) {
                  $alertMessage = sprintf("You have finished `%s` from the powerslot on `%s`",str_replace('(Enchanted)','(Legendary)',$info['powerslot_item']),$characterName);
               }
               else if ($columnKey == 'aa_points' && $updateValue >= 100) {
                  $alertMessage = sprintf("You have reached `%s` ability points on `%s`",$updateValue,$characterName);
               }

               if ($alertMessage && isset($accountInfo['discord_id'])) {
                  $this->api->sendMessage($accountInfo['discord_id'],$alertMessage);
                  $this->debug(9,sprintf("Queued alert message to %s: %s",$accountInfo['discord_name'],$alertMessage));
               }
            }
         }
      }

      $this->debug(9,"UPDATE FIELDS: ".json_encode($updateFields));

      if ($updateFields) {
         $statement = "INSERT INTO character_data (account_id,name,server,updated,".implode(',',array_keys($updateFields)).") ". 
                      "VALUES (?,?,?,now(),".implode(',',array_fill(0,count($updateFields),'?')).") ".
                      "ON DUPLICATE KEY UPDATE updated=values(updated), ".implode(', ',array_map(function($field) { return "$field=values($field)"; },array_keys($updateFields)));
         $types     = 'iss'.implode('',array_column($updateFields,'type'));
         $data      = array_merge([$accountInfo['id'],$characterName,$serverName],array_column($updateFields,'value'));

         $this->debug(9,"STATEMENT: $statement");
         $this->debug(9,"TYPES:     $types");
         $this->debug(9,"DATA:      ".json_encode($data));

         $result = $this->main->db()->bindExecute($statement,$types,$data);

         if ($result === false) {
            $this->error('database error');
            return false;
         }
      }


      
      return true;
   }
}