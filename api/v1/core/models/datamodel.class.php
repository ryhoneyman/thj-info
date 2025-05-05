<?php

/**
 * DataModel
 */
class DataModel extends DefaultModel
{ 
   public function __construct($debug = null, $main = null)
   {
      parent::__construct($debug,$main);
   }

   
   public function getRuleInfoByName($ruleName)
   {
      $this->debug(8,"called");

      if (!$this->main->connectDatabase() === false) { $this->error('database not available'); return false; }

      if (!preg_match('/^[\w\:]+$/',$ruleName)) { $this->error('invalid ruleName provided'); return false; }

      return $this->main->db()->query("SELECT * FROM rule_values WHERE rule_name = '$ruleName'",array('single' => true));
   }

}