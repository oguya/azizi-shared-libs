<?php
/**
 * ChangeLog
 *
 * Version 0.8
 * - Allows the user to override the credentials to use when connecting to the database. @see Database#InitializeConnection
 * - Updated the test cases when confirming a user
 * - Add file name and line number to the error messages
 *
 * Version 0.7
 * - Class Dbase becomes the base class
 * - @see Database#ExecuteQuery defaults to MYSQLI_ASSOC instead of MYSQLI_BOTH
 * - Dropped the concept of using the login id to hash password @see Database#ConfirmUser
 *
 * Version 0.6
 * - Implemented the custom authentication
 * - Support of LDAP authentication
 */


 /** A class containing custom database functions that are used by the various projects
 *
 * @category   Database
 * @package    DatabaseFunctions
 * @author     Kihara Absolomon <soloincc@gmail.com>
 * @version    0.6
 */
class DBase{
   /**
    * A global variable that will hold the currently opened MySQL link identifier
    *
    * @var MySQL_link   The currently opened MySQL link identifier
    */
   public $dbcon;

   /**
    * @var string    A place holder for the last error that occured. Useful for sending data back to the user
    */
   public $lastError;

   /**
    * @var string    A mysql query to be executed
    */
   public  $query;

   /**
    * @var mixed     A place holder which will hold the last results from an executed query
    */
   public $lastResult;

   /**
    * @var  array    An array where results of session manipulation will be stored. This is because we cannot return meaningful data from the session operations.
    */
   public $session;

   public function  __construct() {
      $this->session = array();
   }

   /**
    * Initializes the database connection using the credentials inherited from the config class or the supplied credentials
    *
    * @param   array    $config  (Optional) Override the default credentials, incase there is need to use special or different credentials
    * @return  integer  Returns 0 when all went ok, else returns 1 bt the error message is saved in $this->lastError
    */
   public function InitializeConnection($config = NULL) {
//      echo '<pre>'.print_r($config, true).'wtf1</pre>';
      if(!isset(Config::$config) && is_null($config)){   //assume we have some inherited config
         $this->lastError = 'Database settings are missing. Cannot create a dbase connection.';
         $this->CreateLogEntry('Database settings are missing. Cannot create a dbase connection.', 'fatal');
         return 1;

      }
      $config = (is_null($config)) ? Config::$config : $config;

      $this->dbcon = new mysqli($config['dbloc'], $config['user'], $config['pass'], $config['dbase']);
      if($this->dbcon->connect_error) {
         $this->lastError = 'Cannot connect to the database!';
         $this->CreateLogEntry('Cannot connect to the database. '.$this->dbcon->connect_errno." ".$this->dbcon->connect_error, 'fatal');
         return 1;
      }
      $this->query = "SET character set 'utf8'";
      if(!$this->dbcon->query($this->query)){
         $this->lastError = 'Error while setting the character set.';
         $this->CreateLogEntry('Error while setting the character set.', 'error', true);
         return 0;   //this is not a really serious one, so lets just return an ok status
      }
      return 0;
   }

   /**
    * Fetches a value in a specific row
    *
    * @param   string   $table      The name of the table to fetch the data from
    * @param   string   $toreturn   The column name to return
    * @param   mixed    $col        The column name(s) to be used in the search criteria -- can either be an array or a string
    * @param   mixed    $colval     The corresponding value(s) to $col to be used in the search criteria -- can either be an array or a string
    * @param   string   $operand    The type of comparison to be used to build the query, common operands are =, like
    * @return  mixed    Returns the column value on successful completion and -2 on an error
    */
   public function GetSpecificValue($table, $toreturn, $col, $colval, $operand = "="){
      if(is_array($col)){
         $con='';
         for($i=0; $i<count($col);$i++) $con.=($i==0)?$col[$i]."$operand '".$colval[$i]."'":' AND '.$col[$i]."$operand '".$colval[$i]."'";
         $this->query="SELECT $toreturn FROM $table WHERE $con LOCK IN SHARE MODE";
      }
      else $this->query="SELECT $toreturn FROM $table WHERE $col $operand '$colval' LOCK IN SHARE MODE";
      //echo $query.'<br>';
      $result=$this->dbcon->query($this->query);
      if(!$result){
         $this->CreateLogEntry("There was an error while fetching data from the $table table", 'fatal', true);
         $this->lastError = "There was an error while fetching data from the database.";
         return 1;
      }
      $this->lastResult = NULL;
      $row = $result->fetch_array(MYSQLI_NUM);
      $this->lastResult = $row[0];
      return 0;        //this is being returned as a string even if its an integer
   }

   /**
    * Inserts data into a table
    *
    * @param	string 	$table    The tabel to insert data
    * @param	array 	$cols     The columns to update
    * @param	array 	$colvals  The column values to update
    * @return	int      Returns the last inserted id on success or 0 on error. In case of an error an error message is @see $lastError added to $this->lastError
    */
   public function InsertData($table, $cols, $colvals) {
      //lock the table to prevent concurrent reads and updates
      /**
       * Review the importance of this piece of code. It greatly reduces perfomance especially when there are many inserts
       *
      $this->query = "SELECT ".implode(',',$cols)." FROM $table FOR UPDATE";
      $result = $this->dbcon->query($this->query);
      if($result===false) {
         $this->CreateLogEntry("There was an error while inserting data into the $table table", 'fatal', true);
         $this->lastError = "There was an error while inserting data into the database.";
         return 1;
      }
      */

      $col_vals = "'" . implode("', '",$colvals) . "'";
      $this->query = "INSERT INTO $table(".implode(", ",$cols).") VALUES($col_vals)";
      $result = $this->dbcon->query($this->query);
      if($result===false) {
         $this->CreateLogEntry("There was an error while inserting data to the $table table.", 'fatal', true);
         $this->lastError = "There was an error while updating the database.";
         return 0;
      }
      else return $this->dbcon->insert_id;
   }

   /**
    * Adds data to the database but incase of an existing entry, it updates the entry
    *
    * @param   string   The name of the table that we want to add/update
    * @param   array    An array with the column names to be added/updated
    * @param   array    An array with the corresponding column values
    * @return  integer  Returns the last inserted id when everything goes ok, else it returns 0 and store an error description in $this->lastError
    * @since   v0.4
    */
   public function InsertOnDuplicateUpdate($table, $cols, $colvals, $autoIncrementCol = ''){
      //lock the table to prevent concurrent reads and updates
      $this->query = "SELECT ".implode(',',$cols)." FROM $table FOR UPDATE";
      $result = $this->dbcon->query($this->query);
      if($result===false){
         $this->lastError = "There was an error while fetching data from the '$table' table.";
         $this->CreateLogEntry($this->lastError, 'fatal', true);
         return 0;
      }

      $col_vals="'".implode("', '",$colvals)."'";
      $colCount = count($cols); $valCount = count($colvals);
      if($colCount != $valCount){
         $this->lastError = 'There is an error in your data. The column count does not match the values count!.';
         $this->CreateLogEntry($this->lastError, 'fatal', true);
         return 0;
      }
      $onUpdate='';
      for($i=0; $i < $colCount; $i++){
         $onUpdate.=($onUpdate=='')?'':', ';
         $onUpdate.="$cols[$i]='$colvals[$i]'";
      }
      $lastInsertId = ($autoIncrementCol != '') ? "$autoIncrementCol=LAST_INSERT_ID($autoIncrementCol)," : '';
      $this->query = "INSERT INTO $table(".implode(", ",$cols).") VALUES($col_vals) ON DUPLICATE KEY UPDATE $lastInsertId $onUpdate";
      $result = $this->dbcon->query($this->query);
      if($result === false){
         $this->lastError = "There was an error while adding/replacing data to the '$table' table.";
         $this->CreateLogEntry($this->lastError, 'fatal', true);
         return 0;
      }
      else return $this->dbcon->insert_id;
   }

   /**
    * Executes a query
    *
    * @param   string   $fetchMode  (Optional) The type of array that will be fetched. Can be MYSQ_BOTH, MYSQL_ASSOC, MYSQL_NUM. Defaults to MYSQL_ASSOC
    * @return  mixed    A multi-dimensioanl array with the results as fetched from the dbase when successful else it returns 1
    */
   public function ExecuteQuery($fetchMode = MYSQLI_ASSOC){
      $result = $this->dbcon->query($this->query);
      if(!$result){
         $this->CreateLogEntry("There was an error while fetching data from the database.", 'fatal', true);
         $this->lastError = "There was an error while fetching data from the database.";
         return 1;
      }
      $results=array();
      while($row = $result->fetch_array($fetchMode)) array_push($results, $row);
      return $results;
   }

   /**
    * Fetches a single column value in a given row
    *
    * @param   string   $table      The name of the table to fetch the data from
    * @param   string   $toreturn   The column name to return
    * @param   mixed    $col        The column name(s) to be used in the search criteria -- can be an array
    * @param   mixed    $colval     The corresponding value(s) to $col to be used in the search criteria -- can be an array
    * @param   mixed    $operand    (Optional) The type of comparison to be used to build the query, common operands are =, like
    * @return  mixed    Returns the column value on successful completion and -2 on an error
    */
   public function GetSingleRowValue($table, $toreturn, $col, $colval, $operand = "="){
      if(is_array($col)){
         $con='';
         for($i=0; $i<count($col);$i++) $con.=($i==0)?$col[$i]."$operand '".$colval[$i]."'":' AND '.$col[$i]."$operand '".$colval[$i]."'";
         $this->query = "SELECT $toreturn FROM $table WHERE $con LOCK IN SHARE MODE";
      }
      else $this->query = "SELECT $toreturn FROM $table WHERE $col $operand '$colval' LOCK IN SHARE MODE";

      //echo '<pre>'.print_r($this, true).'</pre>'; //die();
      $result = $this->dbcon->query($this->query);
      if(!$result){
         $this->CreateLogEntry("There was an error while fetching data from the $table table.", 'fatal', true);
         $this->lastError = "There was an error while fetching data from the database.";
         return -2;
      }
      $row = $result->fetch_array(MYSQLI_NUM);
      return $row[0];        //this is being returned as a string even if its an integer
   }

   /**
    * Get all the values of only one column in a table
    * This function is just like GetAllColumnValues only that it gets values from a single column only n does not support linked tables
    *
    * @param   string   $table      The table to fetch data from
    * @param   string   $col        The column to fetch values from
    * @param   bool     $order      Whether to order the fetch values or not. We shall use the column being selected for ordering
    * @param   mixed    $criteria   (Optional)The criteria to be used when executing the query
    * @return  mixed    Returns an array with the found values on success and 1 on error
    */
   public function GetSingleColumnValues($table, $col, $order, $criteria = null){
      if($order) $ordering = " order by $col";
      else $ordering='';
      if(is_array($criteria)){
         $columns=$criteria[0]; $vals=$criteria[1];
         $criteria='';
         for($i=0;$i<count($columns);$i++){
            $criteria.=($i==0)?'WHERE ':' AND ';
            $criteria.=$columns[$i]."='".$vals[$i]."'";
         }
      }
      elseif($criteria==null || $criteria==false || $criteria=='') $criteria = '';
      else $criteria = "where $criteria";

      $this->query = "SELECT $col FROM $table $criteria $ordering LOCK IN SHARE MODE";
      $result = $this->dbcon->query($this->query);
      if(!$result){
         $this->CreateLogEntry("There was an error while fetching data from the $table table.", 'fatal', true);
         $this->lastError = "There was an error while fetching data from the database.";
         return 1;
      }
      $results = array();
      while($row = $result->fetch_array(MYSQLI_NUM)) array_push($results, $row[0]);
      return $results;
   }

   /**
    * Fetches specific rows from the table using the search criteria
    *
    * @param   string   $table      The table to fetch the data from
    * @param   mixed    $cols       Either an array of the columns that we want returned or the column name that we are interested in
    * @param   mixed    $criteria   (Optional) Either a string or array. If its an array it holds an array of columns and their values to be used in the search,. Defaults to an empty string
    * @param   mixed    $fetchMode  (Optional) The mode that will be used to fetch data, can be MYSQL_ASSOC, MYSQL_NUM or MYSQL_BOTH. The default is MYSQL_BOTH
    * @return  mixed    Returns an multi-dimensions array with the results on sucess, else it returns 1
    */
   public function GetColumnValues($table, $cols, $criteria = '', $fetchMode = MYSQLI_ASSOC, $join = 'AND'){
      if(is_array($criteria)) {
         $columns=$criteria[0];
         $vals=$criteria[1];
         $criteria='';
         for($i=0;$i<count($columns);$i++) {
            $criteria.=($i==0)?'WHERE ':" $join ";
            $criteria.=$columns[$i]."='".$vals[$i]."'";
         }
      }
      if(is_array($cols)) $this->query = "SELECT ".implode(',',$cols)." FROM $table $criteria LOCK IN SHARE MODE";
      else $this->query = "SELECT $cols FROM $table $criteria LOCK IN SHARE MODE";
      $result = $this->dbcon->query($this->query);
      //LogError('Debugging');
      if(!$result) {
         $this->CreateLogEntry("There was an error while fetching data from the $table table.", 'fatal', true);
         $this->lastError = "There was an error while fetching data from the database.";
         return 1;
      }
      if($result->num_rows==0) return array();
      $results = array();
      while($row = $result->fetch_array($fetchMode)) array_push($results, $row);
      return $results;
   }

   /**
    * updates row(s) in a table
    *
    * @param   string   $table         Table to update the data
    * @param   mixed    $cols          The column(s) to update. Can be a single column or an array of columns
    * @param   mixed    $colvals       The column(s) values to update. Can be a single value or an array of values, but MUST be of equal number ot the $cols
    * @param   mixed    $conditioncol  The column(s) to be used in the where criteria. Can be a single column or an array of columns
    * @param   mixed    $conditionval  The column(s) values to be used in the criteria. Can be a single value or an array of values, but MUST be of equal number ot the $conditionalcol
    * @return  integer  Returns 0 on success or 1 on error
    */
   public function UpdateRecords($table, $cols, $colvals, $conditioncol, $conditionval){
      //create the conditions incase there are multiple conditions
      if(is_array($conditioncol)){
         $condition='';
         for($i=0; $i < count($conditioncol); $i++){
            $condition.=($i==0)?$conditioncol[$i]."='".$conditionval[$i]."'":' AND '.$conditioncol[$i]."='".$conditionval[$i]."'";
         }
      }
      else $condition="$conditioncol = '$conditionval'";

      $col_vals='';
      if(is_array($cols)){
         for($i=0; $i<count($cols); $i++){
            $col_vals.=($i==0)?$cols[$i]."='".$colvals[$i]."'":",".$cols[$i]."='".$colvals[$i]."'";
         }
         //lock the table to prevent concurrent reads and updates
         $this->query = "SELECT ".implode(',',$cols)." FROM $table FOR UPDATE";
      }
      else{
         $col_vals = "$cols = '$colvals'";
         //lock the table to prevent concurrent reads and updates
         $this->query = "SELECT $cols FROM $table FOR UPDATE";
      }
      $result = $this->dbcon->query($this->query);
      if(!$result){
         $this->CreateLogEntry("There was an error while fetching data from the $table table.", 'fatal', true);
         $this->lastError = "There was an error while fetching data from the database.";
         return 1;
      }

      $this->query = "UPDATE $table SET $col_vals WHERE $condition";
      $result = $this->dbcon->query($this->query);
      if(!$result){
         $this->CreateLogEntry("There was an error while fetching data from the $table table.", 'fatal', true);
         $this->lastError = "There was an error while fetching data from the database.";
         return 1;
      }
      return 0;
   }

   /**
    * Deletes data from the database
    *
    * @param   string  $table    The name of the table we are deleting from
    * @param   mixed   $col      Can be an array or a string. Specifies the columns we want to use for the search criteria
    * @param   mixed   $colval   Corresponds to the data type of the col parameter
    * @return  mixed   Returns 0 on successful delete, else it returns 1 and the error message is stored in $this->lastError
    */
   function DeleteData($table, $col, $colval){
      if(is_array($col)){
         $con='';
         for($i=0; $i<count($col);$i++) $con.=($i==0)?$col[$i]."='".$colval[$i]."'":' AND '.$col[$i]."='".$colval[$i]."'";
         $this->query = "SELECT ".implode(',',$col)." FROM $table FOR UPDATE";
      }
      else{
         $con="$col='$colval'";
         $this->query = "SELECT $col FROM $table FOR UPDATE";
      }
      //lock the table to prevent concurrent reads and updates
      $result = $this->dbcon->query($this->query);
      if(!$result){
         $this->CreateLogEntry("There was an error while fetching data from the $table.", 'fatal', true);
         $this->lastError = 'There was an error while fetching data from the database.';
         return 1;
      }
      $this->query = "DELETE FROM $table WHERE $con";
//      echo $this->query.'<br>';
      $result = $this->dbcon->query($this->query);
      if(!$result){
         $this->CreateLogEntry("There was an error while deleting from the $table.", 'fatal', true);
         $this->lastError = 'There was an error while updating the database.';
         return 1;
      }
      return 0;
   }

   /**
    * Checks if a box is defined in the database. If it is not, it adds it with the passed parameters. If its there it is updated
    *
    * @param   string   $name             The name of the box
    * @param   string   $size             The size of the box in the format '[A-Z]:[0-9]+.[A-Z]:[0-9]+'
    * @param   string   $type             The box type. Its mostly a box
    * @param   integer  $keeper           An id of the person responsible for this box
    * @param   string   $features         (Optional) A description of the box
    * @param   integer  $location         (Optional) The id of the location where the box is stored
    * @param   string   $rack             (Optional) The rack name where the box is located
    * @param   integer  $rack_position    (Optional) The position of the box in the rack
    * @return  mixed    Returns the added or updated box id if all goes ok, or a string with an error message if something fails
    */
   public function AddNewTray($dbase, $name, $size, $type, $keeper, $box_features = NULL, $location = NULL, $rack = NULL, $rack_position = NULL) {
      if($name=='' || !isset($name)) return 'Cannot add an empty box name!!';

      //fromat the cols and col vals
      $cols = array('box_name', 'size', 'box_type', 'keeper');
      $colvals = array($name, $size, $type, $keeper);
      $addons = array('box_features', 'location', 'rack', 'rack_position');
      foreach($addons as $t) {
         if(isset($$t)) {
            $cols[] = $t;  //add the column name
            $colvals[] = $$t; //add the value passed with this column name
         }
      }

      $res = $this->GetSingleRowValue("$dbase.boxes_def", 'box_id', 'box_name', $name);
      if($res == -2) return $this->lastError;
      elseif(isset($res)) { //the box is already defined, so just update the data
         $boxId = $res+0;  //convert it to an integer
         $res = $this->UpdateRecords("$dbase.boxes_def", $cols, $colvals, 'box_id', $boxId);
         if($res) return $this->lastError;
         //all is ok, return the last inserted id
         return $boxId;
      }
      elseif(is_null($res)) {  //we dont have this box so add it
         $res = $this->InsertData("$dbase.boxes_def", $cols, $colvals);
         if($res) return $this->lastError;
         //all is ok, return the last inserted id
         return $this->dbcon->insert_id;
      }
      else return 'unknown option';
   }

   /**
    * Adds a new sample type to the labcollector's database.
    *
    * @param   string   $dbase       The name of the database that we are going to add the samples to
    * @param   string   $sampleName  The name of the sample name that we are going to add
    * @return  mixed    Return a string with the error message in case an error occurs, else it returns the inserted sample id.
    * @since   v0.3
    */
   public function AddSampleType($dbase, $sampleName) {
      if($sampleName=='' || !isset($sampleName)) return 'Cannot add an empty sample name!!';
      //check if the sample type is already defined
      $curSample = $this->GetSingleRowValue("$dbase.sample_types_def", 'count', 'sample_type_name', $sampleName);
      if($curSample == -2) return $this->lastError;
      elseif(!is_null($curSample)) return $curSample;  //the sample is already added to the database

      $res = $this->InsertData("$dbase.sample_types_def", array('sample_type_name'), array($sampleName));
      if(!$res) return $this->lastError;
      return $res;
   }

   /**
    * Adds a new project to the database.
    *
    * @param   string   $dbase        The name of the database that we are going to add the samples to
    * @param   string   $projectName  The name of the project that we are going to add
    * @return  mixed    Return a string with the error message in case an error occurs, else it returns the inserted sample id.
    * @since   v0.8
    */
   public function AddProject($dbase, $projectName) {
      if($projectName=='' || !isset($projectName)) return 'Cannot add an empty project name!!';
      //check if the sample type is already defined
      $projectId = $this->GetSingleRowValue("$dbase.modules_custom_values", 'val_id', 'value', $projectName);
      if($projectId == -2) return $this->lastError;
      elseif(!is_null($projectId)) return $projectId;  //the sample is already added to the database

      $res = $this->InsertData("$dbase.modules_custom_values", array('value', 'field_id_link'), array($projectName, 1));
      if(!$res) return $this->lastError;
      return $res;
   }

   /**
    * Adds a new organism to the database
    *
    * @param   string   $dbase         The name of the database that we are going to add the samples to
    * @param   string   $speciesName   The name of the organism that we are adding
    * @return  mixed    Return a string with the error message in case an error occurs, else it returns the inserted organism id.
    * @since   v0.8
    */
   public function AddSpecies($dbase, $speciesName){
      if($speciesName=='' || !isset($speciesName)) return 'Cannot add an empty organism name!!';
      //check if the sample type is already defined
      $curOrg = $this->GetSingleRowValue("$dbase.organisms", 'org_id', 'org_name', $speciesName);
      if($curOrg == -2) return $this->lastError;
      elseif(!is_null($curOrg)) return $curOrg;  //the organism is already added to the database

      $res = $this->InsertData("$dbase.organisms", array('org_name'), array($speciesName));
      if(!$res) return $this->lastError;
      return $res;
   }

   /**
    * Fetches all the defined sample type from labcollector's database
    *
    * @param   string   $dbase The name of the database that we will fetch data from
    * @return  mixed    Returns a string with an error message in case an error occurs, else it returns an array with all the sample types
    * @since v0.3
    */
   public function GetAllSampleTypes($dbase) {
      $this->query = "select * from $dbase.sample_types_def";
      $res = $this->ExecuteQuery(MYSQLI_ASSOC);
      if($res==1) return 1;
      $allSampleTypes = array();
      foreach($res as $t) $allSampleTypes[$t['sample_type_name']] = $t['count'];
      return $allSampleTypes;
   }

   /**
    * Links a sample with its parent the LabCollector's way
    *
    * @param   string   $dbase      The name of the database where the changes are going
    * @param   integer  $parentId   The id of the parent sample
    * @param   integer  $childId    The id of the child sample
    * @param   string   $parentType The module to which the parent belongs: can be Sample, Primer
    * @param   string   $childType  The module to which the child belongs to: can be Sample, Primer
    * @return  integer  Returns 0 in case all went ok, else it returns 1
    * @since v0.3
    */
   public function LinkParentWithChildren($dbase, $parentId, $childId, $parentType, $childType){
      $moduleTypes = array(
         'Sample' => 'SP',
         'Primer' => 'PR'
      );
      $cols = array('module_from', 'id_from', 'module_to', 'id_to');
      $colvals = array($moduleTypes[$parentType], $parentId, $moduleTypes[$childType], $childId);
      $res = $this->InsertData("$dbase.modules_relation", $cols, $colvals);
      if($res) return 1;
      return 0;
   }

   /**
    * Validates and adds opttional data to an array of data to be added to the database
    *
    * Not all columns in a table must be set. Some of these columns are optional and from the user input we must determine whether to add them or not.
    * This function allows us to determine if a value is set and if it is and it is not empty, it is escaped and added to a list of values to be
    * inserted to the database.
    *
    * Brief example of use:
    *
    * <code>
    * //create the array with the data to be added
    * $optionalData = array(
    *     'other_type' => array('column_name' =>'sample_type'),
    *     'other_source' => array('column_name' =>'sample_source'),
    *     'contact_person' => array('column_name' =>'contact_person'),
    *     'sample_location' => array('column_name' =>'storage_location'),
    *     'box_name' => array('column_name' =>'box_name'),
    *     'box_pos' => array('column_name' =>'position')
    * );
    * MapOptionalDatabaseData($optionalData);
    * </code>
    *
    * @param   array    $data    A multi-dimensional array with the data. The keys are the html field names, the value is an array having the column names.
    * @return  array    Returns a multi dimensional array with the column names and values to be added to the database.
    * @since   v0.4
    */
   public function MapOptionalDatabaseData($data){
      //loop thru the input array and add all this data
      $additionalCols = array(); $additionalColVals = array();
      foreach($data as $key => $value){
         if(isset($_POST[$key]) && $_POST[$key] != ''){
            $additionalCols[] = $value['column_name'];
            if(is_string($_POST[$key])) $additionalColVals[] = $this->dbcon->real_escape_string($_POST[$key]);
            else $additionalColVals[] = $_POST[$key];
         }
      }
      return array('cols' => $additionalCols, 'colvals' => $additionalColVals);
   }

   /**
    * Creates a new log entry into the logs
    *
    * There can be different log levels as defined in settings.<br/>
    * If the message is empty and $logMysqlError is set to true, it logs the last MySQL error encountered or the last sql statement executed as a debug log
    * If only the message is set, then an entry is added to the info logs
    *
    * @param   string   $message       (Optional) The message that we want to log
    * @param   string   $level         (Optional) The level of the message. if not defined, it defaults to an info log
    * @param   boolean  $logMysqlError (Optional) Determines whether to log the last MySQL error encountered and the last sql statement executed
    * @return  integer  Returns a custom 0 as there is nothing more to do
    * @todo    Add a log rotation whenever the log size limit is reached
    */
   public function CreateLogEntry($message, $level='', $logMysqlError='', $file='', $line=''){
      if(!isset(Config::$logSettings) || !Config::$logSettings['logErrors']){  //if not set, it means that no logs are required so just return
         return 0;
      }
      $curLogFile = '';
      if(Config::$logSettings['combined'])   $curLogFile = 'all_logs.log';    //combine all the errors in one
      else{
         if($level=='' && $logMysqlError) $level = 'debug';
         elseif($level=='') $level = 'info';   //if level is not specified and we are not logging mysql errors, all logs are considered as info
         $curLogFile = Config::$logLevels[$level][1];
      }
      $curLogFile = Config::$logSettings['logFileDir']."/$curLogFile";

      //we are all set to log
      if(!$fd = fopen($curLogFile, 'a')) return 0;
      $messageString = date('Y-m-d H:i:s: ');

      //file and line where the error occured
      $file = ($file != '') ? "$file" : '';
      $line = ($line != '') ? ":$line" : '';

      //if we dont have any message and $logMysqlError is set to true, then log the last mysql error
      if($message=='' && $logMysqlError) $err = "({$this->dbcon->errno}) {$this->dbcon->error}\nLast Query: {$this->query}";
      elseif($logMysqlError) $err = "$message\nMySQL Error and String: ({$this->dbcon->errno}) {$this->dbcon->error}\nLast Query: {$this->query}";
      else $err = $message;

      fputs($fd, "$messageString{$file}{$line}:\n" . Config::$logLevels[$level][0] . " $err\n\n");
      fclose($fd);
      return  0;
   }

   /**
    * Truncates or initializes log files
    *
    * @global  array    $logSettings   The log settings as defined in the config file
    * @global  object   $GeneralTasks  An object with the general tasks. Goes hand in hand with the dbase module
    * @global  array    $logLevels     An array defining the various levels of logs
    * @param   array    $logs          (Optional) An array with the log file names that we are to work on, defaults to Config::$logLevels
    * @param   bool     $truncate      (Optional) Whether to truncate the files or just initialize them, defaults to false
    * @return  int      Returns 0 on an error, I dont know what it returns when all is ok.
    */
   public function InitializeLogs($logs = NULL, $truncate = false){
      if(!isset(Config::$logSettings) || !Config::$logSettings['logErrors']){  //if not set, it means that no logs are required so just return
         return 0;
      }
      if(!isset(Config::$logSettings['logFileDir']) || Config::$logSettings['logFileDir'] ==''){
         unset(Config::$logSettings); //disable logging
         return 0;   //the logs file dir must be set, else no logs
      }
      if(!is_dir($filename)){    //the dir doesnt exist, create one
         $res = GeneralTasks::CreateDirIfNotExists(Config::$logSettings['logFileDir']);
         if(!$res) return 0;     //there was an error in creating the directory, hence no logs will be saved
      }

      if(!isset($logs)){
         $logs = array();
         foreach(Config::$logLevels as $t) $logs[] = $t[1];
      }
      foreach($logs as $curLogFile){
         $curLogFile = Config::$logSettings['logFileDir']."/$curLogFile";
         //check whether the file exists and is writable. if it doest exists create it, if its not writable, make it writable
         if(!file_exists($curLogFile)) {
            $fd=fopen($curLogFile, "wt");
            if(!$fd) {
               return 0; //cant be able to create the file
            }
            else fclose($fd);
            if(!is_writable($curLogFile)) {
               if(!chmod($curLogFile, '644')) return 0;
            }
         }
         if($truncate) GeneralTasks::TruncateFile($curLogFile);
      }
   }

   /**
    * Given a username and a password, confirms whether the user has privileges to access the system or not. Saves the user id in a variable $this->currentUserId
    *
    * Prior to v0.6, Dbase.ConfirmUser confirmed whether a user had privileges to access the system and fetched the necessary data from the database.
    * Since different systems have different dbase structures and they want to fetch different sets of data, the roles of this function are split
    * into two, leaving the confirming of whether a user has privileges or not to this function.
    *
    * @param   string   $username         The username specified by the user
    * @param   string   $password         The password(md5'd) entered for this page.
    * @return  mixed    Returns 1 incase of a fatal error, 2 incase of a wrong password, 3 incase the account doesnt exist, 4 in case the a/c is disabled or 0 in case all is ok.
    * @since   v0.5
    */
   public function ConfirmUser($username, $password){
      $username = $this->dbcon->real_escape_string($username);
      $query = "select a.id from ".Config::$config['session_dbase'].".users as a
               inner join ".Config::$config['session_dbase'].".user_levels as b on a.user_level=b.id";

      if(isset(Config::$psswdSettings['useSalt']) && Config::$psswdSettings['useSalt'])
         $this->query = "$query WHERE a.login='$username' AND psswd=sha1(concat(a.salt,'$password')) AND a.allowed=1";
      else  $this->query = "$query WHERE a.login='$username' AND psswd='$password' AND a.allowed=1 and b.allowed=1";
//      echo '<pre>'. print_r($_POST, true) .'</pre>';
//      echo $this->query;
//      $this->CreateLogEntry($this->query, 'debug');

      $result = $this->ExecuteQuery(MYSQL_ASSOC);
      if($result == 1){
         $this->CreateLogEntry("There was an error while fetching data from the database.", 'fatal', true);
         $this->lastError = "There was an error while fetching data from the session database.<br />Please try again later.";
         return 1;
      }

      //do some NCIS work kiasi
      if(count($result) == 0){
         //check if the account actually exists
         $res = $this->GetSingleRowValue(Config::$config['session_dbase'].'.users', 'id', 'login', $username);
         if($res == -2){
            $this->CreateLogEntry("There was an error while fetching data from the database.", 'fatal', true);
            $this->lastError = "There was an error while fetching data from the session database.<br />Please try again later.";
            return 1;
         }
         elseif(is_null($res)){     //we do not have an account with that username
            $this->CreateLogEntry("There is no account with '$username' as the username.", 'debug', true);
            return 3;
         }
         elseif(is_numeric($res)){   //the account exists and the user is allowed, but cant be logged in. Most likely that the credentials provided is an LDAP credential
            $adAuthenticate = $this->ADAuthenticate($username, $password);
            $this->CreateLogEntry(print_r($adAuthenticate, true), 'fatal', true);
            echo 'wtf';
            if($adAuthenticate == 1) return 1;
            elseif(is_string($adAuthenticate)) return 1;
            elseif($adAuthenticate == 0) return 0;
         }
         else{
            //check if the account is disabled
            $res = $this->GetSingleRowValue(Config::$config['session_dbase'].'.users', 'allowed', 'login', $username);
            if($res == -2){
               $this->CreateLogEntry("There was an error while fetching data from the database.", 'fatal', true);
               $this->lastError = "There was an error while fetching data from the session database.<br />Please try again later.";
               return 1;
            }
            elseif($res == 0) return 4;   //the account is disabled
         }
         echo "--$res--";
         return 2;  //incorrect password
      }
      //store the current user id in some variable
      $this->currentUserId = $result[0]['id'];
      return 0;
   }

   /**
    * Checks if the username is allowed to log in via the AD
    *
    * @param   string   $username   The user we interested in
    * @return  mixed    Returns a string with the error message in case an error occured, else it returns 0 if allowed, 1 if not allowed if nothing to do
    * @since   v0.5
    */
   public function InWhiteList($username){
      $this->query = "select id from ".Config::$config['session_dbase'].".users where login='$username' and ldap_authentication=1 and allowed=1";
      $res = $this->ExecuteQuery(MYSQLI_ASSOC);
//      echo '<br />Count -- '.count($res).'<br />';
      if($res == 1) return "There was an error while fetching data from the database!";
      elseif(count($res) == 0) return 1;
      elseif(count($res) == 1){
         $_SESSION['project'] = $res[0]['project'];
         return 0;
      }
      else return -2;
   }

   /**
    * Given a username and password, it performs the AD authentication for the user
    *
    * @param   string   $username   The username to use to authenticate
    * @param   string   $password   The password
    * @return  mixed    Returns a string with an error message in case of an error, 1 if the user credentials are bad or 0 if all is ok
    * @since   v0.5
    */
   private function ADAuthenticate($username, $password){
//      echo '<pre>'.print_r(Config::$config, true).'</pre>';
      $this->ldapConnection = ldap_connect(Config::$config->ldapHost, Config::$config->ldapPort);
      if (!$this->ldapConnection) {
         $this->CreateLogEntry('Could not connect to the AD server!', 'fatal');
         return "There was an error while connecting to the the AD server for authentication!<br />" . Config::$contact;
      } else {
         $this->ldapConnection = ldap_connect($this->ldapHost, $this->ldapPort);
         if (!$this->ldapConnection)
            return "Could not connect to the LDAP host";
         else {
            if (ldap_bind($this->ldapConnection, "$username@ilri.cgiarad.org", $password)) {
               ldap_set_option($this->ldapConnection, LDAP_OPT_REFERRALS, 0);
               ldap_set_option($this->ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3);
               $ldapSr = ldap_search($this->ldapConnection, 'ou=ILRI Kenya,dc=ilri,dc=cgiarad,dc=org', "(sAMAccountName=$username)", array('sn', 'givenName', 'title'));
               if (!$ldapSr) {
                  $this->CreateLogEntry('Connected successfully to the AD server, but cannot perform the search!', 'fatal');
                  return "There was an error while searching the AD server for you!<br />" . Config::$contact;
               }
               $entry1 = ldap_first_entry($this->ldapConnection, $ldapSr);
               if (!$entry1) {
                  $this->CreateLogEntry('Connected successfully to the AD server, but there was an error while searching the AD!', 'fatal');
                  return "Invalid username or password(AD)!<br />If your credentials are correct, maybe you do not have sufficient privileges to access the system.<br />" . Config::$contact;
               }
               $ldapAttributes = ldap_get_attributes($this->ldapConnection, $entry1);
               $_SESSION['username'] = $username; $_SESSION['surname'] = $ldapAttributes['sn'][0]; $_SESSION['onames'] = $ldapAttributes['givenName'][0];
               $_SESSION['user_level'] = $ldapAttributes['title'][0];
               return 0;
            }
            else {
               $this->CreateLogEntry("There was an error while binding user '$username' to the AD server!", 'fatal');
               return 1;
            }
         }
      }
   }

   /**
    * Adds a user to the whitelist, ie allows a user to login via AD
    *
    * @param   array    $userAttributes   An array with the user attributes to add to the database
    * @return  integer  Returns 1 in case of an error and the error is added to Dbase->lastError, else it returns 0 if all is ok
    * @since   v0.5
    */
   public function Add2WhiteList($userAttributes){
      $cols = array('login', 'sname', 'onames', 'salt', 'user_level', 'ldap_authentication', 'allowed');
      $colvals = array($userAttributes['username'], $userAttributes['sn'], $userAttributes['givenName'], $userAttributes['title'],
          $userAttributes['level'], 1, $userAttributes['allowed']);
      $res = $this->Dbase->InsertData(Config::$config['session_dbase'].'.users', $cols, $colvals);
      if($res) return $this->Dbase->lastError;
      else return 0;
   }

   /**
    * Given the username and password does th eauthentication of the user to the system. First checks the custom authentication module and then the AD
    *
    * @param   string   $username   The username of the user
    * @param   string   $password   The password of the user
    * @return  mixed    Returns a string with an error message in case there is an error, 1 if the user is not allowed to log in and 0 if the user credentials are ok
    * @since   v0.5
    * @todo    Fix the LDAP authentication! Dropped dead over the weekend n am bila clue wats happening
    */
   public function Authenticate($username, $password){
      //lets start by custom authentication
//      echo '<pre>'.print_r($this, true).'</pre>';
//      echo "<pre>$username -- $password</pre>";
      $res = $this->ConfirmUser($username, md5($password));
      if($res == 0) return 0;    //all is ok and the user has been correctly been id'd
      elseif($res == 1) return $this->lastError;   //we have some error
      elseif($res == 2 && Config::$config['ldap_authenticate']){
         //the user could not be logged in using the custom login method, lets try the AD way
         $res = $this->InWhiteList($username);
         if(is_string($res)) return $res;
         elseif($res == 1) return 1;   //the user is not allowed to log in via AD and is not authenticated via the custom method
         elseif($res == 0){   //can log in via AD, so lets try and log in
            //THIS IS NOT WORKING FOR NOW...WE MOVE ON SWIFTLY
//            return 1;
            $res = $this->ADAuthenticate($username, $password);
            if(is_string($res)) return $res;
            elseif($res == 1) return 1;   //the user credentials are not ok, and he cant log in via the AD
            elseif($res == 0) return 0;   //all is ok, he has the damn tight credentials
         }
      }
      else return 1;    //cannot authenticate
   }

   /**
    * Binds the various session functions to specific functions in this class
    *
    * @since   v0.5
    */
   public function SessionStart(){
      $res = session_set_save_handler(array(&$this, 'OpenSession'), array(&$this, 'CloseSession'), array(&$this, 'ReadSession'),
              array(&$this, 'WriteSession'), array(&$this, 'DestroySession'), array(&$this, 'CleanSession'));
      if(!$res){
         $this->CreateLogEntry("Cannot set the session handlers!", 'fatal');
         return 1;
      }
      session_start();
   }

   /**
    * Does nothing, but it is required by session_set_save_handler
    *
    * @param   array    The path where the session will be saved. In this case it is the table name in the database
    * @param   string   The name of the session that we are dealing with
    * @return  boolean  It just returns true
    * @since   v0.5
    */
   public function OpenSession($sessionSavePath, $sessionName) {
//      echo "<pre>session.open: ".print_r($this, true).'</pre>';
      return true;
   }

   /**
    * Closes a session and in the processes releases the mysql dbase connection that was created
    *
    * @return  mixed    Incase the dbase connection was not opened it returns true as expected by the session_set_save_handler,
    *                   else returns it returns the results of mysqli_close
    * @since   v0.5
    */
   public function CloseSession() {
//      $this->Dbase->CreateLogEntry(">session.close:\n ".print_r($_SESSION, true), 'debug');
      if($this->dbcon == NULL) return true;
      return $this->dbcon->mysqli_close();
   }

   /**
    * Looks for session details in the database
    *
    * @param   string   $sessionId  The session id that we are interested in
    * @return  string   Returns an empty string if there is nothing to read, else returns the data that we are looking for
    * @since   v0.5
    */
   public function ReadSession($sessionId) {
//      $this->Dbase->CreateLogEntry(">session.read:\n ".print_r($_SESSION, true), 'debug');
      $this->Dbase->session = array();
      //check that the session is not timed out
      $this->Dbase->query = 'SELECT updated_at, data FROM ' . Config::$config['session_dbase'] . ".sessions WHERE session_id='$sessionId'";
      $result = $this->Dbase->ExecuteQuery(MYSQLI_ASSOC);
      if($result == 1){      //we have an error which is already logged. jst return a string as required
         $this->Dbase->session['error'] = true;
         $this->Dbase->session['message'] = "There was an error while fetching data from the database. Please try again later.";
         return '';
      }
//      $this->Dbase->CreateLogEntry(">session.read.data:\n ".print_r($result, true), 'debug');

      if(count($result) == 0){   //we dont have an initialized session
         $this->Dbase->session['no_session'] = true;
         return '';
      }
      else{
         //check that the user session time aint expired, if it has log the user out.
         $result = $result[0];
         $now = strtotime(date('Y-m-d H:i:s'));
         $updatedAt = strtotime($result['updated_at']);
         $secsDiff = $now - $updatedAt;
         $elapsed['day'] = floor($secsDiff/86400);
         $elapsed['hour'] = floor(($secsDiff - ($elapsed['day']*86400))/3600);
         $elapsed['minute'] = floor(($secsDiff - (($elapsed['day']*86400) + ($elapsed['hour']*3600)))/60);
         $elapsed['second'] = floor(($secsDiff - (($elapsed['day']*86400) + ($elapsed['hour']*3600) + ($elapsed['minute']*60))));
         $this->Dbase->session['elapsed'] = $elapsed;

         if(floor($secsDiff/60) > Config::$timeout){
            $this->Dbase->session['timeout'] = true;
            $timeIn = '';
            foreach($elapsed as $key => $value){
               if($value != 0){
                  if($timeIn != '') $timeIn .= ', ';
                  if($value == 1) $timeIn .= "$value $key";
                  else $timeIn .= "$value {$key}s";
               }
            }
            //we have a session which has already expire. Try and remove junk from the database and then return an error message
            $this->CleanSession(Config::$timeout);
            $this->Dbase->session['message'] = "You were inactive for $timeIn and you were automatically logged out.<br />Please log in again.";
            return '';
         }
         return $result['data'];
      }
   }

   /**
    * Updates the session data in the database, if the session data is already in the database, else it inserts a new record
    *
    * @param   string   $sessionId  The id of the session that we interested in
    * @param   string   $data       The data that we are adding
    * @return  boolean  Returns true when the data has been successfully been added/updated, else returns false
    * @since   v0.5
    */
   public function WriteSession($sessionId, $data) {
//      var_dump($this->Dbase->dbcon->real_escape_string($data));
//      echo "<pre>session.write: $sessionId ".print_r($data, true).'</pre>';
      if($data == '' || !isset($data)) return '';
      //also ensure that incase the session_id is the same and bt the incoming data differs frm the data in the dbase--coming from the same computer
      $time = date('Y-m-d H:i:s');
      $this->Dbase->query = "REPLACE INTO ".Config::$config['session_dbase'].".sessions(session_id, data, updated_at) VALUES ('".
      $this->Dbase->dbcon->real_escape_string($sessionId)."', '".$this->Dbase->dbcon->real_escape_string($data)."','$time')";
//      echo "<pre>session.write: $sessionId ".$this->Dbase->query.'</pre>';
      $result = $this->Dbase->dbcon->query($this->Dbase->query);
      if(!$result){     //there is nothing to do at this stage
         $this->Dbase->CreateLogEntry('There was an error while inserting data to the session database', 'fatal', true);
         return false;
      }
      return true;
   }

   /**
    * Deletes the session id data from the database
    *
    * @param   string   $sessionId  The session id that we want deleted from the database
    * @return  boolean  Returns true when all is ok, or false when there was an error as instructed by the manuals!
    * @since   v0.5
    */
   public function DestroySession($sessionId) {
//      echo "<pre>destroy, sessionId = $sessionId: ".print_r($this, true).'</pre>';
      $this->Dbase->query = "DELETE FROM " . Config::$config['session_dbase'] . " .sessions WHERE session_id='$sessionId'";
      $result = $this->Dbase->dbcon->query($this->Dbase->query);
      $_SESSION = array();
      if(!$result){  //there is nothing we can do apart from logging this error
         $this->Dbase->CreateLogEntry('There was an error while inserting data to the session database', 'fatal', true);
         return false;
      }
      return true;
   }

   /**
    * Clears the session table of garbage, which is generated when a session expires and the system doesnt have the chance to clear the data
    *
    * @param   integer  $expiryInterval   The time in minutes, of which sessions which are older than this will be deleted!
    * @return  bool     Returns true when all is ok, or false when there was an error as instructed by the manuals!
    * @since   v0.5
    */
   public function CleanSession($expiryInterval) {
//      echo "<pre>clean, sessions older than = $expiryInterval: ".print_r($this, true).'</pre>';
      $this->Dbase->query = 'DELETE FROM ' . Config::$config['session_dbase'] . ".sessions WHERE DATE_ADD(updated_at, INTERVAL "
         .(int) $expiryInterval." SECOND) < NOW()";
      $result = $this->Dbase->dbcon->query($this->Dbase->query);
      if(!$result){  //there is nothing we can do apart from logging this error
         $this->Dbase->CreateLogEntry('There was an error while inserting data to the session database', 'fatal', true);
         return false;
      }
      return true;
   }

   /**
    * Manage session data...either logs out a user if the time has expired, or restarts a session in case there is need
    */
   public function ManageSession(){
//      echo "<pre>manage session: ".print_r($this->session, true).'</pre>';
      $this->session['restart'] = false;
      if(isset($this->session['timeout']) && $this->session['timeout']){
         $this->LogOut();
         $this->session['restart'] = true;
      }
      if(isset($this->session['no_session']) && $this->session['no_session']) $this->session['restart'] = true;
      if(isset($this->session['error']) && $this->session['error']) $this->session['restart'] = true;
   }

   /**
    * Logs a user out of the system
    */
   public function LogOut(){
      //delete the data from the database
      if(isset($_COOKIE[session_name()])) setcookie(session_name(), '', time()-42000, '/');     //expire the cookie
      if(isset($_SESSION['username'])) session_destroy();   //destroy the session
   }

   /**
    * Start a database transaction. This can only be applied to innodb databases
    *
    * @return  bool  Returns true in case all went ok, else returns false
    * @since   v0.6
    */
   public function StartTrans(){
      $result = $this->dbcon->query("SET AUTOCOMMIT=0");
      if(!$result){
         $this->Dbase->CreateLogEntry('There was an error while starting a database session.', 'fatal', true);
         return false;
      }
      $result = $this->dbcon->query("START TRANSACTION");
      if(!$result){
         $this->Dbase->CreateLogEntry('There was an error while starting the session.', 'fatal', true);
         return false;
      }
      return true;
   }

   /**
    * Commits an already started transaction. Applies only to innodb databases
    *
    * @return  bool  Returns true in case all went ok, else returns false
    * @since   v0.6
    */
   public function CommitTrans(){
      $result = $this->dbcon->query("COMMIT");
      if(!$result){
         $this->CreateLogEntry('There was an error while starting a database session.', 'fatal', true);
         return false;
      }
      $result = $this->dbcon->query("SET AUTOCOMMIT=1");
      if(!$result){
         $this->CreateLogEntry('There was an error while restoring autocommit option.', 'fatal', true);
         return false;
      }
      return true;
   }

   /**
    * Rolls back a db transaction. Applies to only db databases
    *
    * @return  bool  Returns true in case all went ok, else returns false
    * @since   v0.6
    */
   public function RollBackTrans(){
      $result = $this->dbcon->query("ROLLBACK");
      if(!$result){
         $this->CreateLogEntry('There was an error while starting a database session.', 'fatal', true);
         return false;
      }
      $result = $this->dbcon->query("SET AUTOCOMMIT=1");
      if(!$result){
         $this->CreateLogEntry('There was an error while restoring autocommit option.', 'fatal', true);
         return false;
      }
      return true;
   }

   /**
    * Validates the fields as defined in the array. Gets the value associated with each field and saves an error status for each field
    *
    * @param   array    $fields  An array with the fields to validate
    * @return  mixed    Returns a string with the error message in case of an error, else it returns the updated version of the input array
    * @since   v0.8
    */
   public function ValidateFields($fields){
      if(!is_array($fields)) return 'I am expecting an array with the fields to validate, got jack!';
      foreach($fields as $index => $t){
         $val = trim($_POST[$t['html_name']]);
         if($val == '' && $t['required']) $fields[$index]['error'] = true;

         if($t['required'] || ($val != '' && $val!= 'undefined')){
            if($t['regex'] != 'text') if(!preg_match($t['regex'], $val)) $fields[$index]['error'] = true;
         }
         if(in_array($t['regex'], Config::$OPTIONS_VALIDATOR_FIELDS_2_ESCAPE)) $fields[$index]['value'] = $this->dbcon->real_escape_string($val);
         else $fields[$index]['value'] = $val;
      }
      return $fields;
   }
}
?>
