<?php

class DataController extends DefaultController
{
   protected $tableConfig      = null;
   protected $fieldQualifiers  = array();
   protected $columnQualifiers = array();
   
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

      if (!$main->buildClass('input','LWPLib\Input',array('noBody' => true),'common/input.class.php')) { $this->notReady("Input library error"); }

      $this->fieldQualifiers = array(
         'startswith' => array('validFor' => array('string')),
         'contains'   => array('validFor' => array('string','json')),
         'eq'         => array('validFor' => array('string','json','int','datetime','float'), 'operator' => '='),
         'ne'         => array('validFor' => array('string','json','int','datetime','float'), 'operator' => '!='),
         'gte'        => array('validFor' => array('string','json','int','datetime','float'), 'operator' => '>='),
         'lte'        => array('validFor' => array('string','json','int','datetime','float'), 'operator' => '<='),
         'gt'         => array('validFor' => array('string','json','int','datetime','float'), 'operator' => '>'),
         'lt'         => array('validFor' => array('string','json','int','datetime','float'), 'operator' => '<'),
      );
   
      $this->columnQualifiers = array(
         'base64decode' => array('validFor' => array('base64')),
         'base64encode' => array('validFor' => array('string','json')),
         'raw'          => array('validFor' => array('json')),
         'jsondecode'   => array('validFor' => array('json')),
      );
   }

   public function bindQueryDatabase(LWPLib\Request $request): bool
   {
      $this->debug(7,'method called');

      $parameters = $request->parameters;
      $filterData = $request->filterData;

      $input = $this->main->obj('input');

      $database  = isset($filterData['database'])  ? $filterData['database'] : null;
      $statement = isset($parameters['statement']) ? $parameters['statement'] : null;
      $types     = isset($parameters['types'])     ? $parameters['types'] : null;
      $data      = isset($parameters['data'])      ? $parameters['data'] : null;
      $index     = isset($parameters['index'])     ? $parameters['index'] : null;
      $single    = isset($parameters['single'])    ? $parameters['single'] : null;

      $dbName       = $input->validate($database,'alphanumeric,underscore,dash');
      $useIndex     = $input->validate($index,'alphanumeric,underscore',null);
      $singleReturn = ($single) ? true : false;

      if (!$statement) { return $this->standardError("Invalid statement specified",500); }
   
      if ($types && !preg_match('/^[dfis]+$/',$types)) { return $this->standardError("Invalid types specified",500); }

      if ($types && !$data) { return $this->standardError("Invalid data specified",500); }

      if (!$this->raiseDatabase($dbName)) { return $this->standardError("Could not connect to database $dbName"); }

      $db       = $this->main->db($dbName);
      $dbResult = $db->bindQuery($statement,$types,$data,array('index' => $useIndex, 'single' => $singleReturn));

      $this->main->debug->writeFile('datacontroller.debug.log',json_encode([$dbResult,$statement,$types,$data,$useIndex,$singleReturn]),false);

      if ($dbResult === false) { return $this->standardError("could not query database",500); }

      $returnData = array('results' => $dbResult ?? []);

      return $this->standardOk(array('data' => $returnData, 'totalCount' => count($returnData['results'])));
   }

   public function bindExecuteDatabase($request)
   {
      $this->debug(7,'method called');

      $parameters = $request->parameters;
      $filterData = $request->filterData;

      $input = $this->main->obj('input');

      $dbName    = $input->validate($filterData['database'],'alphanumeric,underscore,dash');
      $statement = $parameters['statement'];
      $types     = $parameters['types'];
      $data      = $parameters['data'];

      if (!$statement) { return $this->standardError("Invalid statement specified",500); }
      if (!$data)      { return $this->standardError("Invalid data specified",500); }

      if (!preg_match('/^[dfis]+$/',$types)) { return $this->standardError("Invalid types specified",500); }

      if (!$this->raiseDatabase($dbName)) { return $this->standardError("Could not connect to database $dbName"); }

      $db       = $this->main->db($dbName);
      $dbResult = $db->bindExecute($statement,$types,$data);

      if (!$dbResult) {
         list($errno,$error) = $db->error();
         return $this->standardError("Could not execute statement: ".(($error) ? "$errno - $error" : 'Unknown error'),500);
      }

      $return['data'] = $dbResult;

      if (preg_match('/^insert/i',$statement)) {
         $return['insertId'] = $db->insertId();
      }

      return $this->standardOk($return);
   }

   public function queryDatabase($request)
   {
      $this->debug(7,'method called');

      $parameters = $request->parameters;
      $filterData = $request->filterData;

      $this->debug(9,"parameters: ".json_encode($parameters));

      $input = $this->main->obj('input');

      $dbName       = $input->validate($filterData['database'],'alphanumeric,underscore,dash');
      $queryFull    = $parameters['query'];
      $index        = $input->validate($parameters['index'],'alphanumeric,underscore',null);
      $singleReturn = ($parameters['single']) ? true : false;

      if (!preg_match('/^select/i',$queryFull)) { return $this->standardError("invalid query specified (select only)",500); }

      if (!$this->raiseDatabase($dbName)) { return $this->standardError("Could not connect to database $dbName"); }

      $db       = $this->main->db($dbName);
      $dbResult = $db->query($queryFull,array('index' => $index, 'single' => $singleReturn));

      if ($dbResult === false) { return $this->standardError("could not query database",500); }

      $returnData = array('results' => $dbResult);

      return $this->standardOk(array('data' => $returnData, 'totalCount' => count($returnData['results'])));
   }

   public function getStructuredTableData($request)
   {
      $this->debug(7,'method called');

      $parameters = $request->parameters;
      $filterData = $request->filterData;

      $input = $this->main->obj('input');

      $dbName     = $input->validate($filterData['database'],'alphanumeric,underscore,dash');
      $tableName  = $input->validate($filterData['table'],'alphanumeric,underscore');
      $sort       = json_decode($input->validate($parameters['sort'],'all'),true);
      $pagination = json_decode($input->validate($parameters['pagination'],'all'),true);
      $filter     = json_decode($input->validate($parameters['filter'],'all'),true);
      $column     = json_decode($input->validate($parameters['column'],'all'),true);
      $index      = $input->validate($parameters['index'],'alphanumeric,underscore',null);

      if (!$tableName) { return $this->standardError("No table specified",400); }

      if (!$this->loadTableConfiguration($dbName)) { $this->standardError("Table configuration data is missing for $dbName",400); };
      
      if (!$this->tableConfig[$tableName]) { return $this->standardError("Unknown table $tableName specified",400); }

      if (!$this->raiseDatabase($dbName)) { return $this->standardError("Could not connect to database $dbName"); }

      $db              = $this->main->db($dbName);
      $tableInfo       = $this->tableConfig[$tableName];
      $fieldList       = $tableInfo['fieldList'];
      $tableIdField    = $this->filterList($fieldList,'id',false);
      $queryBaseCount  = "SELECT COUNT(*) FROM $tableName";
      $queryBase       = "SELECT %s FROM $tableName";
      $queryWhere      = array();
      $querySort       = '';
      $queryLimit      = '';
      $columnFilter    = array();
      $columnTransform = array();

      // If user specified columns are not defined, pull list of id + selectable columns by default
      if (!is_array($column) || empty($column) || is_null($column)) {
         $columnFilter = $this->filterList($fieldList,'id,select');

         // Automatically decode JSON
         foreach ($columnFilter as $columnName) {
            if (preg_match('/^json$/i',$fieldList[$columnName]['type'])) { $columnTransform[$columnName] = 'jsondecode'; }
         }
      }
      else {
         // use the id field and the validated user requested fields to build a unique list of selectable columns
         $userColumns = array();
         foreach ($column as $columnItem) {
            list($columnItemName,$columnItemQualifier) = explode(':',$columnItem);

            $columnItemQualifier = strtolower($columnItemQualifier);

            $userColumns[$columnItemName]++;

            // Automatically transform JSON if the raw flag was not provided
            if ($columnItemQualifier != 'raw' && preg_match('/^json$/i',$fieldList[$columnItemName]['type'])) {
               $columnItemQualifier = 'jsondecode';
            }

            if ($columnItemQualifier) {
               $qualifierInfo = $this->columnQualifiers[$columnItemQualifier];

               if (!$qualifierInfo) { return $this->standardError("unknown column qualifier $columnItemQualifier",400); }

               if ($qualifierInfo['validFor']) {
                  if (!preg_match('/^('.implode('|',$qualifierInfo['validFor']).')$/i',$fieldList[$columnItemName]['type'])) {
                     return $this->standardError("cannot use $columnItemQualifier on field $columnItemName",400);
                  }
               }

               $columnTransform[$columnItemName] = $columnItemQualifier;
            }
         }

         $columnFilter = array_unique(array_merge(array($tableIdField),$this->filterList($fieldList,'select',true,array_keys($userColumns))));
      }

      // find column name and definitions and add them a list of selected columns
      $selectedColumns = array();
      foreach ($columnFilter as $fieldName) {
         $computedValue               = $fieldList[$fieldName]['computed'];
         $selectedColumns[$fieldName] = ($computedValue) ? $computedValue.' AS '.$fieldName : $fieldName;
      }

      if ($index && !$selectedColumns[$index]) { return $this->standardError("cannot use index $index on unselected column",400); }

      // add selected columns to query
      $queryBaseFull = sprintf($queryBase,implode(', ',array_values($selectedColumns)));

      if (is_array($filter)) {
         foreach ($filter as $filterField => $filterValues) {
            list($filterFieldName,$filterFieldQualifier) = explode(':',$filterField);

            $filterFieldQualifier = strtolower($filterFieldQualifier);

            if (!$fieldList[$filterFieldName]['filter']) { return $this->standardError("cannot filter by field $filterFieldName",400); }

            if ($fieldList[$filterFieldName]['computed'] && is_array($filterValues)) {
               return $this->standardError("cannot provide array values on computed field $filterFieldName",400);
            }

            if ($filterFieldQualifier) {
               $qualifierInfo = $this->fieldQualifiers[$filterFieldQualifier];
               if (!$qualifierInfo) { return $this->standardError("unknown filter qualifier $filterFieldQualifier",400); }

               if ($qualifierInfo['validFor']) {
                  if (!preg_match('/^('.implode('|',$qualifierInfo['validFor']).')$/i',$fieldList[$filterFieldName]['type'])) {
                     return $this->standardError("cannot use $filterFieldQualifier on field $filterFieldName",400);
                  }
               }
            }

            $fieldType  = $fieldList[$filterFieldName]['type'];
            $fieldQuote = (preg_match('/^(string)$/i',$fieldType)) ? true : false;

            // Array values provided
            if (is_array($filterValues)) {
               $fieldValues = array_map(array($db,'escapeString'),$filterValues);

               if (preg_match('/^startswith$/i',$filterFieldQualifier)) {
                  $queryStartsWith = array();
                  foreach ($fieldValues as $fieldValue) { $queryStartsWith[] = "$filterFieldName LIKE '$fieldValue%'"; }
                  $queryWhere[] = '('.implode(' OR ',$queryStartsWith).')';
               }
               else if (preg_match('/^contains$/i',$filterFieldQualifier)) {
                  $queryContains = array();
                  foreach ($fieldValues as $fieldValue) { $queryStartsWith[] = "$filterFieldName LIKE '%$fieldValue%'"; }
                  $queryWhere[] = '('.implode(' OR ',$queryStartsWith).')';
               }
               else {
                  $queryWhere[] = "$filterFieldName IN (".(($fieldQuote) ? "'".implode("','",$fieldValues)."'" : implode(',',$fieldValues)).")";
               }
            }
            // Single value provided
            else {
               $fieldValue = $db->escapeString($filterValues);

               if (preg_match('/^startswith$/i',$filterFieldQualifier)) {
                  $queryWhere[] = "$filterFieldName LIKE '$fieldValue%'";
               }
               else if (preg_match('/^contains$/i',$filterFieldQualifier)) {
                  $queryWhere[] = "$filterFieldName LIKE '%$fieldValue%'";
               }
               else {
                  $fieldOperator = (preg_match('/^(gte|gt|lte|lt)$/i',$filterFieldQualifier)) ? $this->fieldQualifiers[$filterFieldQualifier]['operator']
                                                                                              : $this->fieldQualifiers['eq']['operator'];

                  // If we are using a YYYY-MM-DD.* datetime string that must be quoted.
                  if (preg_match('/^(datetime)$/i',$fieldType) && preg_match('/^\d{4}-\d{2}-\d{2}/',$fieldValue)) { $fieldQuote = true; }

                  // Detect if this is a computed field, use the computation if so, otherwise use name/value equivalency
                  $queryWhere[] = ($fieldList[$filterFieldName]['computed']) ?
                                     $fieldList[$filterFieldName]['computed'] :
                                     "$filterFieldName $fieldOperator ".(($fieldQuote) ? "'".$fieldValue."'" : $fieldValue);
               }
            }
         }
      }

      if (is_array($sort)) {
         // if we only received a single element array with field/order, push it into another array so we can stack sorts.
         $sortList      = ($sort['field']) ? array($sort) : $sort;
         $querySortList = array();

         foreach ($sortList as $sortItem) {
            $sortField = $input->validate($sortItem['field'],'alphanumeric,underscore');
            $sortDir   = $input->validate($sortItem['order'],'alphanumeric');

            if (!$fieldList[$sortField]['sort'])          { return $this->standardError("cannot sort by field $sortField",400); }
            if (!preg_match('/^(asc|desc|)$/i',$sortDir)) { return $this->standardError("invalid sort directive on $sortField",400); }

            $querySortList[] = $sortField.(($sortDir) ? strtoupper(" $sortDir") : '');
         }

         $querySort = implode(', ',$querySortList);
      }

      if (is_array($pagination)) {
         $page    = $input->validate($pagination['page'],'numeric');
         $perPage = $input->validate($pagination['perPage'],'numeric');

         if (preg_match('/^\d+$/',$page) && preg_match('/^\d+$/',$perPage)) {
            if ($page <= 0)    { $page = 1; }
            if ($perPage <= 0) { $perPage = 1; }

            $queryLimit = (($page - 1) * $perPage).",".$perPage;
         }
      }

      $queryCondWhere = (($queryWhere) ? ' WHERE '.implode(' AND ',$queryWhere) : '');
      $queryCondFull  = $queryCondWhere.
                        (($querySort) ? ' ORDER BY '.$querySort : '').
                        (($queryLimit) ? ' LIMIT '.$queryLimit : '');

      $queryCount = $queryBaseCount.$queryCondWhere;
      $queryFull  = $queryBaseFull.$queryCondFull;
      $queryOpts  = ($index) ? array('index' => $index) : array();

      $dbResult = $db->query($queryFull,$queryOpts);

      $this->debug->writeFile("$dbName.data.queries",sprintf("%s %s (%s)\n",
                                (($dbResult === false) ? 'results:fail' : 'results:'.count($dbResult)),
                                $queryFull,
                                json_encode(array('table' => $tableName, 'filter' => $filter, 'column' => $column))));

      if ($dbResult === false) { return $this->standardError("could not query database",500); }

      $returnData = array();

      if (!empty($columnTransform)) {
         $transformResult = array();
         foreach ($dbResult as $resultId => $resultData) {
            foreach ($columnTransform as $columnName => $transformType) {
               $columnData = $resultData[$columnName];

               if ($transformType == 'jsondecode') { $dbResult[$resultId][$columnName] = json_decode($columnData,true); }
            }
         }
      }

      $totalCount = key($db->query($queryCount));

      $this->headers[]          = "X-Total-Count: $totalCount";
      $returnData['results']    = ($index) ? $dbResult : array_values($dbResult);
      $returnData['totalCount'] = $totalCount;
      //$returnData['query']      = $queryFull;

      return $this->standardOk(array('data' => $returnData));
   }

   protected function filterList($list, $find, $multiple = true, $keyFilter = null)
   {
      $return = array();
   
      foreach ($list as $key => $info) {
         if (is_array($keyFilter) && !in_array($key,$keyFilter)) { continue; }
   
         foreach (explode(',',$find) as $findItem) {
            if ($info[$findItem]) {
               if (!$multiple) { return $key; }
               else { $return[$key]++; }
            }
         }
      }
   
      return array_keys($return);
   }
   
   /**
    * loadTableConfiguration
    *
    * @param  string $dbName
    * @return bool
    */
   protected function loadTableConfiguration($dbName)
   {
      $appConfigDir = $this->main->getDefined('APP_CONFIGDIR') ?: __DIR__;
      $dbConfigFile = sprintf("%s/tables/%s.tables.json",$appConfigDir,$dbName);

      if (!is_file($dbConfigFile)) { return false; }

      $tableConfig = json_decode(file_get_contents($dbConfigFile),true);

      if (!$tableConfig) { return false; }

      $this->tableConfig = $tableConfig;

      return true;
   }
   
   /**
    * raiseDatabase
    *
    * @param  string $dbName
    * @return bool
    */
   protected function raiseDatabase($dbName)
   {
      $dbConfigFile = ($dbName) ? sprintf("db.%s.conf",$dbName) : null;

      return $this->main->connectDatabase($dbConfigFile,$dbName);
   }
}
