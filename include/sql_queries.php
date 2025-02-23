<?php

if(LOGGING) {
	//Logging connection to prevent mysql_insert_id problems. Need to be called before the second connect...
	$log_dbh = db_connector();
}

$dbh = db_connector();

/*
 * TODO - remove this code - mysql 5 only
if ($db_server == 'mysql') {
	//SC: May not really be 5...
	$mysql = 5;
}
*/

// Cannot redfine LOGGING (withour PHP PECL runkit extension) since already true in define.php
// Ref: http://php.net/manual/en/function.runkit-method-redefine.php
// Hence take from system_defaults into new variable
// Initialise so that while it is being evaluated, it prevents logging
$can_log = false;
$can_chk_log = (LOGGING && (isset($auth_session->id) && $auth_session->id > 0) && getDefaultLoggingStatus());
$can_log = $can_chk_log;
unset($can_chk_log);

function db_connector() {

	global $config;
	/*
	* strip the pdo_ section from the adapter
	*/
	$pdoAdapter = substr($config->database->adapter, 4);

	if(!defined('PDO::MYSQL_ATTR_INIT_COMMAND') AND $pdoAdapter == "mysql" AND $config->database->adapter->utf8 == true)
	{
        simpleInvoicesError("PDO::mysql_attr");
	}

	try
	{

		switch ($pdoAdapter)
		{

		    case "pgsql":
		    	$connlink = new PDO(
					$pdoAdapter.':host='.$config->database->params->host.';	dbname='.$config->database->params->dbname,	$config->database->params->username, $config->database->params->password
				);
		    	break;

		    case "sqlite":
		    	$connlink = new PDO(
					$pdoAdapter.':host='.$config->database->params->host.';	dbname='.$config->database->params->dbname,	$config->database->params->username, $config->database->params->password
				);
				break;

		    case "mysql":
                switch ($config->database->utf8)
                {
                    case true:

        			   	$connlink = new PDO(
        					'mysql:host='.$config->database->params->host.'; port='.$config->database->params->port.'; dbname='.$config->database->params->dbname, $config->database->params->username, $config->database->params->password,  array( PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8;")
        				);
		        		break;

        		    case false:
		            default:
        		    	$connlink = new PDO(
        					$pdoAdapter.':host='.$config->database->params->host.'; port='.$config->database->params->port.'; dbname='.$config->database->params->dbname,	$config->database->params->username, $config->database->params->password
		        		);
    				break;
                }
    	    	break;

		}

	}
	catch( PDOException $exception )
	{
		simpleInvoicesError("dbConnection",$exception->getMessage());
		die($exception->getMessage());
	}

	return $connlink;
}

function mysqlQuery($sqlQuery) {
	dbQuery($sqlQuery);
}

/*
 * dbQuery is a variadic function that, in its simplest case, functions as the
 * old mysqlQuery does.  The added complexity comes in that it also handles
 * named parameters to the queries.
 *
 * Examples:
 *  $sth = dbQuery('SELECT b.id, b.name FROM si_biller b WHERE b.enabled');
 *  $tth = dbQuery('SELECT c.name FROM si_customers c WHERE c.id = :id',
 *                 ':id', $id);
 */

function dbQuery($sqlQuery) {
	global $dbh;
	$argc = func_num_args();
	$binds = func_get_args();
	$sth = false;
	// PDO SQL Preparation
	$sth = $dbh->prepare($sqlQuery);
	if ($argc > 1) {
		array_shift($binds);
		for ($i = 0; $i < count($binds); $i++) {
			$sth->bindValue($binds[$i], $binds[++$i]);
		}
	}
	/*
	// PDO Execution
	if($sth && $sth->execute()) {
		//dbLogger($sqlQuery);
	} else {
		echo "Dude, what happened to your query?:<br /><br /> ".htmlsafe($sqlQuery)."<br />".htmlsafe(end($sth->errorInfo()));
	// Earlier implementation did not return the $sth on error
	}
// $sth now has the PDO object or false on error.
	*/
	try {
		$sth->execute();
		dbLogger($sqlQuery);
	} catch(Exception $e){
		echo $e->getMessage();
		echo "dbQuery: Dude, what happened to your query?:<br /><br /> ".htmlsafe($sqlQuery)."<br />".htmlsafe(end($sth->errorInfo()));
	}

	return $sth;
	//$sth = null;
}

// Used for logging all queries
function dbLogger($sqlQuery) {
// For PDO it gives only the skeleton sql before merging with data

	global $log_dbh;
	global $dbh;
	global $auth_session;
	global $can_log;

	$userid = $auth_session->id;
	if($can_log
		&& (preg_match('/^\s*select/iD',$sqlQuery) == 0)
		&& (preg_match('/^\s*show\s*tables\s*like/iD',$sqlQuery) == 0)
	   ) {
		// Only log queries that could result in data/database  modification

		$last = null;
		$tth = null;
		$sql = "INSERT INTO ".TB_PREFIX."log (domain_id, timestamp, userid, sqlquerie, last_id) VALUES (?, CURRENT_TIMESTAMP , ?, ?, ?)";

		/* SC: Check for the patch manager patch loader.  If a
		 *     patch is being run, avoid $log_dbh due to the
		 *     risk of deadlock.
		 */
		$call_stack = debug_backtrace();
		//SC: XXX Change the number back to 1 if returned to directly
		//    within dbQuery.  The joys of dealing with the call stack.

		if ($call_stack[2]['function'] == 'run_sql_patch') {
		/* Running the patch manager, avoid deadlock */
			$tth = $dbh->prepare($sql);
		} elseif (preg_match('/^(update|insert)/iD', $sqlQuery)) {
			$last = lastInsertId();
			$tth = $log_dbh->prepare($sql);
		} else {
			$tth = $log_dbh->prepare($sql);
		}
		$tth->execute(array($auth_session->domain_id, $userid, trim($sqlQuery), $last));
		unset($tth);
	}
}

/*
 * lastInsertId returns the id of the most recently inserted row by the session
 * used by $dbh whose id was created by AUTO_INCREMENT (MySQL) or a sequence
 * (PostgreSQL).  This is a convenience function to handle the backend-
 * specific details so you don't have to.
 *
 */
function lastInsertId() {
	global $config;
	global $dbh;
	$pdoAdapter = substr($config->database->adapter, 4);

	if ($pdoAdapter == 'pgsql') {
		$sql = 'SELECT lastval()';
	} elseif ($pdoAdapter == 'mysql') {
		$sql = 'SELECT last_insert_id()';
	}
	//echo $sql;
	$sth = $dbh->prepare($sql);
	$sth->execute();
	return $sth->fetchColumn();
}

/*
 * _invoice_check_fk performs some manual FK checks on tables that the invoice
 *     table refers to.   Under normal conditions, this function will return
 *     true.  Returning false indicates that if the INSERT or UPDATE were to
 *     proceed, bad data could be written to the database.
 */

// In every instance of insertion into / updation  of the invoice table, we only pick from dropdown boxes which are sourced from the respective lookup tables.
// Hence is this function necessary to be used at all?

function _invoice_check_fk($biller, $customer, $type, $preference) {
	global $dbh;
	$domain_id = domain_id::get();

	//Check biller
	$sth = $dbh->prepare('SELECT count(id) FROM '.TB_PREFIX.'biller WHERE id = :id AND domain_id = :domain_id');
	$sth->execute(array(':id' => $biller, ':domain_id' => $domain_id));
	if ($sth->fetchColumn() == 0) { return false; }
	//Check customer
	$sth = $dbh->prepare('SELECT count(id) FROM '.TB_PREFIX.'customers WHERE id = :id AND domain_id = :domain_id');
	$sth->execute(array(':id' => $customer, ':domain_id' => $domain_id));
	if ($sth->fetchColumn() == 0) { return false; }
	//Check invoice type
	$sth = $dbh->prepare('SELECT count(inv_ty_id) FROM '.TB_PREFIX.'invoice_type WHERE inv_ty_id = :id');
	$sth->execute(array(':id' => $type));
	if ($sth->fetchColumn() == 0) { return false; }
	//Check preferences
	$sth = $dbh->prepare('SELECT count(pref_id) FROM '.TB_PREFIX.'preferences WHERE pref_id = :id AND domain_id = :domain_id');
	$sth->execute(array(':id' => $preference, ':domain_id' => $domain_id));
	if ($sth->fetchColumn() == 0) { return false; }

	//All good
	return true;
}

/*
 * _invoice_items_check_fk performs some manual FK checks on tables that the
 *     invoice items table refers to.   Under normal conditions, this function
 *     will return true.  Returning false indicates that if the INSERT or
 *     UPDATE were to proceed, bad data could be written to the database.
 */
function _invoice_items_check_fk($invoice, $product, $tax, $update) {
	global $dbh;
	$domain_id = domain_id::get();

	//Check invoice
	if (is_null($update) || !is_null($invoice)) {
		$sth = $dbh->prepare('SELECT count(id) FROM '.TB_PREFIX.'invoices WHERE id = :id AND domain_id = :domain_id');
		$sth->execute(array(':id' => $invoice, ':domain_id' => $domain_id));
		if ($sth->fetchColumn() == 0) { return false; }
	}
	//Check product
	$sth = $dbh->prepare('SELECT count(id) FROM '.TB_PREFIX.'products WHERE id = :id AND domain_id = :domain_id');
	$sth->execute(array(':id' => $product, ':domain_id' => $domain_id));
	if ($sth->fetchColumn() == 0) { return false; }
	//Check tax id
	$sth = $dbh->prepare('SELECT count(tax_id) FROM '.TB_PREFIX.'tax WHERE tax_id = :id AND domain_id = :domain_id');
	$sth->execute(array(':id' => $tax, ':domain_id' => $domain_id));
	if ($sth->fetchColumn() == 0) { return false; }

	//All good
	return true;
}

function getGenericRecord($table, $id, $domain_id='', $id_field='id') {

	$domain_id = domain_id::get($domain_id);

	$record_sql = "SELECT * FROM `".TB_PREFIX."$table` WHERE `$id_field` = :id and `domain_id` = :domain_id";
	$sth = dbQuery($record_sql, ':id', $id, ':domain_id',$domain_id);
	return $sth->fetch();
}

function getCustomer($id, $domain_id='') {
	return getGenericRecord('customers', $id, $domain_id);
}

function getBiller($id, $domain_id='') {
	global $LANG;
	$record = getGenericRecord('biller', $id, $domain_id);
	$record['wording_for_enabled'] = $record['enabled']==1?$LANG['enabled']:$LANG['disabled'];
	return $record;
}

function getPreference($id, $domain_id='') {
	global $LANG;
	$record = getGenericRecord('preferences', $id, $domain_id, 'pref_id');
	$record['status_wording'] = $record['status']==1?$LANG['real']:$LANG['draft'];
	$record['enabled'] = $record['pref_enabled']==1?$LANG['enabled']:$LANG['disabled'];
	return $record;
}

function getTaxRate($id, $domain_id='') {
	global $LANG;
	$record = getGenericRecord('tax', $id, $domain_id, 'tax_id');
	$record['enabled'] = $record['tax_enabled'] == 1 ? $LANG['enabled']:$LANG['disabled'];
	return $record;
}

function getCatAll($id, $domain_id='') {
	global $LANG;
	$record = getGenericRecord('categories', $id, $domain_id, 'category_id');
	$record['enabled'] = $record['enabled'] == 1 ? $LANG['enabled']:$LANG['disabled'];
	return $record;
}

function getSQLPatches() {

	$sql  = "SELECT * FROM ".TB_PREFIX."sql_patchmanager
	            WHERE NOT (sql_patch = '' AND sql_release='' AND sql_statement = '')
	            ORDER BY sql_release, sql_patch_ref";
	$sth = dbQuery($sql);
	return $sth->fetchAll();
}

function getPreferences($domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."preferences WHERE domain_id = :domain_id ORDER BY pref_description";
	$sth  = dbQuery($sql,':domain_id', $domain_id);

	$preferences = null;

	for($i=0;$preference = $sth->fetch();$i++) {

  		if ($preference['pref_enabled'] == 1) {
  			$preference['enabled'] = $LANG['enabled'];
  		} else {
  			$preference['enabled'] = $LANG['disabled'];
  		}

		$preferences[$i] = $preference;
	}

	return $preferences;
}

function getActiveTaxes($domain_id='') {
	global $LANG;
	global $db_server;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."tax WHERE tax_enabled != 0 and domain_id = :domain_id ORDER BY tax_description";
	if ($db_server == 'pgsql') {
		$sql = "SELECT * FROM ".TB_PREFIX."tax WHERE tax_enabled ORDER BY tax_description";
	}
	$sth = dbQuery($sql, ':domain_id',$domain_id);

	$taxes = null;

	for($i=0;$tax = $sth->fetch();$i++) {
		if ($tax['tax_enabled'] == 1) {
			$tax['enabled'] = $LANG['enabled'];
		} else {
			$tax['enabled'] = $LANG['disabled'];
		}

		$taxes[$i] = $tax;
	}

	return $taxes;
}

function getActivePreferences($domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."preferences WHERE pref_enabled and domain_id = :domain_id ORDER BY pref_description";
	$sth  = dbQuery($sql, ':domain_id', $domain_id);

	return $sth->fetchAll();
}

function getCustomFieldLabels($domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."custom_fields WHERE domain_id = :domain_id ORDER BY cf_custom_field";
	$sth = dbQuery($sql,':domain_id',$domain_id);

	for($i=0;$customField = $sth->fetch();$i++) {
		$customFields[$customField['cf_custom_field']] = $customField['cf_custom_label'];

		if($customFields[$customField['cf_custom_field']] == null) {
			//If not set, don't show...
			$customFields[$customField['cf_custom_field']] = $LANG["custom_field"].' '.($i%4+1);
		}
	}

	return $customFields;
}

function getBillers($domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."biller WHERE domain_id = :domain_id ORDER BY name";
	$sth  = dbQuery($sql,':domain_id',$domain_id);

	$billers = null;

	for($i=0; $biller = $sth->fetch(); $i++) {

  		if ($biller['enabled'] == 1) {
  			$biller['enabled'] = $LANG['enabled'];
  		} else {
  			$biller['enabled'] = $LANG['disabled'];
  		}
		$billers[$i] = $biller;
	}

	return $billers;
}

function getActiveBillers($domain_id='') {
	global $db_server;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."biller WHERE enabled != 0 AND domain_id = :domain_id ORDER BY name";
	if ($db_server == 'pgsql') {
		$sql = "SELECT * FROM ".TB_PREFIX."biller WHERE enabled AND domain_id = :domain_id ORDER BY name";
	}
	$sth = dbQuery($sql,':domain_id',$domain_id);

	return $sth->fetchAll();
}

function getTaxTypes() {

	$types = array(
                    '%' => '%',
                    '$' => '$'
	);
	return $types;
}

function getCategory($id)
{
	global $LANG;
	global $dbh;
	global $auth_session;
	
	$sql = "SELECT * FROM ".TB_PREFIX."categories WHERE category_id = :id";
	$sth = dbQuery($sql, ':id', $id) or die(htmlsafe(end($dbh->errorInfo())));
	
	$category = $sth->fetch();
	$category['enabled'] = $category['enabled'] == 1 ? $LANG['enabled']:$LANG['disabled'];
	
	return $category;
}

function getCatys($category_id)
{
 global $LANG;
 global $dbh;
 global $auth_session;
 
 
 $sql = "SELECT
    *
FROM
    ".TB_PREFIX."categories_taxonomy WHERE category_id = $category_id";
 $sth = dbQuery($sql) or die(htmlsafe(end($dbh->errorInfo())));
 
 
 $referral = $sth->fetchObject();
 $referral = $referral->parent;
 
 
 return $referral;
 
 
}

function getCategoryParent($category_id)
{
 global $LANG;
 global $dbh;
 global $auth_session;
 
 
 $sql = "SELECT
    a.category_id,
    a.name,
    (SELECT (CASE  WHEN a.enabled = 0 THEN 'disabled' ELSE 'enabled' END )) AS enabled,
    b.parent,
    (SELECT referencia from ".TB_PREFIX."categories where category_id = b.parent) as referencia_parent,
    (SELECT (CASE WHEN b.parent != 0 THEN CONCAT(referencia_parent, a.referencia) ELSE CONCAT(a.referencia, '00') END )) as referencia
FROM
    ".TB_PREFIX."categories as a INNER JOIN ".TB_PREFIX."categories_taxonomy as b
ON a.category_id = b.category_id where a.category_id = $category_id";
 $sth = dbQuery($sql) or die(htmlsafe(end($dbh->errorInfo())));
 
 
 $referral = $sth->fetchObject();
 $referral = $referral->referencia;
 
 
 return $referral;
 
 
}

function getPaymentType($id, $domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."payment_types WHERE pt_id = :id AND domain_id = :domain_id";
	$sth = dbQuery($sql, ':id', $id, ':domain_id',$domain_id);
	$paymentType = $sth->fetch();
	$paymentType['enabled'] = $paymentType['pt_enabled']==1?$LANG['enabled']:$LANG['disabled'];

	return $paymentType;
}

function getPayment($id, $domain_id='') {
	global $config;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT
		ap.*,
		c.id as customer_id,
		c.name AS customer,
		b.id as biller_id,
		b.name AS biller
	FROM ".TB_PREFIX."payment ap,
		 ".TB_PREFIX."invoices iv,
		 ".TB_PREFIX."customers c,
		 ".TB_PREFIX."biller b
	WHERE
		ap.ac_inv_id = iv.id
	AND iv.customer_id = c.id
	AND iv.biller_id = b.id
	AND ap.id = :id
	AND ap.domain_id = :domain_id";

	$sth = dbQuery($sql, ':id', $id, ':domain_id', $domain_id);
	$payment = $sth->fetch();
	$payment['date'] = siLocal::date($payment['ac_date']);
	return $payment;
}

function getInvoicePayments($id, $domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT
				ap.*,
				c.name AS cname,
				b.name AS bname
			FROM
				".TB_PREFIX."payment ap,
				".TB_PREFIX."invoices iv,
				".TB_PREFIX."customers c,
				".TB_PREFIX."biller b
			WHERE
				ap.ac_inv_id = :id
			AND ap.domain_id = :domain_id
			AND ap.ac_inv_id = iv.id
			AND iv.domain_id = ap.domain_id
			AND iv.customer_id = c.id
			AND c.domain_id = iv.domain_id
			AND iv.biller_id = b.id
			AND b.domain_id = iv.domain_id
			ORDER BY
				ap.id DESC";

	return dbQuery($sql, ':id', $id, ':domain_id', $domain_id);
}

function getCustomerPayments($id, $domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT
				ap.*,
				c.name AS cname,
				b.name AS bname
			FROM
				".TB_PREFIX."payment ap,
				".TB_PREFIX."invoices iv,
				".TB_PREFIX."customers c,
				".TB_PREFIX."biller b
			WHERE
				c.id = :id
			AND ap.domain_id = :domain_id
			AND ap.ac_inv_id = iv.id
			AND iv.domain_id = ap.domain_id
			AND iv.customer_id = c.id
			AND c.domain_id = iv.domain_id
			AND iv.biller_id = b.id
			AND b.domain_id = iv.domain_id
			ORDER BY
				ap.id DESC";

	return dbQuery($sql, ':id', $id, ':domain_id', $domain_id);
}

function getPayments($domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT
				ap.*,
				c.name AS cname,
				b.name AS bname
			FROM 
				".TB_PREFIX."payment ap,
				".TB_PREFIX."invoices iv,
				".TB_PREFIX."customers c,
				".TB_PREFIX."biller b
			WHERE
				ap.domain_id = :domain_id
			AND ap.ac_inv_id = iv.id
			AND iv.domain_id = ap.domain_id
			AND iv.customer_id = c.id
			AND c.domain_id = iv.domain_id
			AND iv.biller_id = b.id
			AND b.domain_id = iv.domain_id
			ORDER BY
				ap.id DESC";

	return dbQuery($sql,':domain_id',$domain_id);
}

function progressPayments($sth, $domain_id='') {

	$domain_id = domain_id::get($domain_id);
	$payments = null;

	for($i=0;$payment = $sth->fetch();$i++) {

		$sql = "SELECT pt_description FROM ".TB_PREFIX."payment_types WHERE pt_id = :id and domain_id = :domain_id";
		$tth = dbQuery($sql, ':id', $payment['ac_payment_type'], ':domain_id', $domain_id);

		$pt = $tth->fetch();

		$payments[$i] = $payment;
		$payments[$i]['description'] = $pt['pt_description'];

	}

	return $payments;
}

function getPaymentTypes($domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."payment_types WHERE domain_id = :domain_id ORDER BY pt_description";
	$sth = dbQuery($sql, ':domain_id',$domain_id);

	$paymentTypes = null;

	for ($i=0;$paymentType = $sth->fetch();$i++) {
		if ($paymentType['pt_enabled'] == 1) {
			$paymentType['pt_enabled'] = $LANG['enabled'];
		} else {
			$paymentType['pt_enabled'] = $LANG['disabled'];
		}
		$paymentTypes[$i]=$paymentType;
	}

	return $paymentTypes;
}

function getActivePaymentTypes($domain_id='') {
	global $LANG;
	global $db_server;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."payment_types WHERE pt_enabled != 0 and domain_id = :domain_id ORDER BY pt_description";
	if ($db_server == 'pgsql') {
		$sql = "SELECT * FROM ".TB_PREFIX."payment_types WHERE pt_enabled and domain_id = :domain_id ORDER BY pt_description";
	}
	$sth = dbQuery($sql, ':domain_id', $domain_id);

	$paymentTypes = null;

	for ($i=0;$paymentType = $sth->fetch();$i++) {
		if ($paymentType['pt_enabled'] == 1) {
			$paymentType['pt_enabled'] = $LANG['enabled'];
		} else {
			$paymentType['pt_enabled'] = $LANG['disabled'];
		}
		$paymentTypes[$i]=$paymentType;
	}

	return $paymentTypes;
}

function getProduct($id, $domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."products WHERE id = :id and domain_id = :domain_id";
	$sth = dbQuery($sql, ':id', $id, ':domain_id', $domain_id);
	$product = $sth->fetch();
	$product['wording_for_enabled'] = $product['enabled']==1?$LANG['enabled']:$LANG['disabled'];
	return $product;
}

/*function insertProduct($description,$unit_price,$enabled=1,$visible=1,$notes="",$custom_field1="",$custom_field2="",$custom_field3="",$custom_field4="") {
	$sql = "INSERT INTO ".TB_PREFIX."products
		(`description`,`unit_price`,`notes`,`enabled`,`visible`,`custom_field1`,`custom_field2`,`custom_field3`,`custom_field4`) 
		VALUES('$description','$unit_price','$notes',$enabled,$visible,'$custom_field1','$custom_field2','$custom_field3','$custom_field4');";
	
	return mysqlQuery($sql);
}*/

function insertProductComplete($enabled=1,$visible=1,$description, $unit_price, $custom_field1 = NULL, $custom_field2, $custom_field3, $custom_field4, $notes, $domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "INSERT into
    ".TB_PREFIX."products (
            domain_id,
            description,
            unit_price,
            custom_field1,
            custom_field2,
            custom_field3,
            custom_field4,
            notes,
            enabled,
            visible,
			category_id
    ) VALUES (
            :domain_id,
            :description,
            :unit_price,
            :custom_field1,
            :custom_field2,
            :custom_field3,
            :custom_field4,
            :notes,
            :enabled,
            :visible,
			:category_id
    )";

	return dbQuery($sql,
		':domain_id',$domain_id,
		':description', $description,
		':unit_price', $unit_price,
		':custom_field1', $custom_field1,
		':custom_field2', $custom_field2,
		':custom_field3', $custom_field3,
		':custom_field4', $custom_field4,
		':notes', "".$notes,
		':enabled', $enabled,
		':visible', $visible,
		':category_id', $_POST['category']
		);
}

function insertProduct($enabled=1,$visible=1, $domain_id='') {
    global $logger;
	$domain_id = domain_id::get($domain_id);

	if (isset($_POST['enabled'])) $enabled = $_POST['enabled'];
    //select all attribts
    $sql = "SELECT * FROM ".TB_PREFIX."products_attributes";
    $sth =  dbQuery($sql);
    $attributes = $sth->fetchAll();

	$logger->log('Attr: '.var_export($attributes,true), Zend_Log::INFO);
    $attr = array();
    foreach($attributes as $k=>$v)
    {
    	$logger->log('Attr key: '.$k, Zend_Log::INFO);
    	$logger->log('Attr value: '.var_export($v,true), Zend_Log::INFO);
    	$logger->log('Attr set value: '.$k, Zend_Log::INFO);
        if($_POST['attribute'.$v[id]] == 'true')
        {
            //$attr[$k]['attr_id'] = $v['id'];
            $attr[$v['id']] = $_POST['attribute'.$v[id]];
//            $attr[$k]['a$v['id']] = $_POST['attribute'.$v[id]];
        }

    }
	$logger->log('Attr array: '.var_export($attr,true), Zend_Log::INFO);
	$notes_as_description = ($_POST['notes_as_description'] == 'true' ? 'Y' : NULL) ;
    $show_description =  ($_POST['show_description'] == 'true' ? 'Y' : NULL) ;

	$sql = "INSERT into
		".TB_PREFIX."products
		(
			domain_id,
            description,
            unit_price,
            cost,
            reorder_level,
            custom_field1,
            custom_field2,
			custom_field3,
            custom_field4,
            notes,
            default_tax_id,
            enabled,
            visible,
			category_id,
            attribute,
            notes_as_description,
            show_description
		)
	VALUES
		(
			:domain_id,
			:description,
			:unit_price,
			:cost,
			:reorder_level,
			:custom_field1,
			:custom_field2,
			:custom_field3,
			:custom_field4,
			:notes,
			:default_tax_id,
			:enabled,
			:visible,
			:category_id,
            :attribute,
            :notes_as_description,
            :show_description
		)";

	return dbQuery($sql,
		':domain_id',$domain_id,
		':description', $_POST['description'],
		':unit_price', $_POST['unit_price'],
		':cost', $_POST['cost'],
		':reorder_level', $_POST['reorder_level'],
		':custom_field1', $_POST['custom_field1'],
		':custom_field2', $_POST['custom_field2'],
		':custom_field3', $_POST['custom_field3'],
		':custom_field4', $_POST['custom_field4'],
		':notes', "".$_POST['notes'],
		':default_tax_id', $_POST['default_tax_id'],
		':enabled', $enabled,
		':visible', $visible,
		':category_id', $_POST['category'],
		':attribute', json_encode($attr),
		':notes_as_description', $notes_as_description,
		':show_description', $show_description
		);
}


function updateProduct($domain_id='') {

	$domain_id = domain_id::get($domain_id);

    //select all attributes
    $sql = "SELECT * FROM ".TB_PREFIX."products_attributes";
    $sth =  dbQuery($sql);
    $attributes = $sth->fetchAll();

    $attr = array();
    foreach($attributes as $k=>$v)
    {
        if($_POST['attribute'.$v[id]] == 'true')
        {
            $attr[$v['id']] = $_POST['attribute'.$v[id]];
        }

    }
	$notes_as_description = ($_POST['notes_as_description'] == 'true' ? 'Y' : NULL) ;
    $show_description =  ($_POST['show_description'] == 'true' ? 'Y' : NULL) ;

	$sql = "UPDATE ".TB_PREFIX."products
			SET
				description = :description,
				enabled = :enabled,
				default_tax_id = :default_tax_id,
				notes = :notes,
				custom_field1 = :custom_field1,
				custom_field2 = :custom_field2,
				custom_field3 = :custom_field3,
				custom_field4 = :custom_field4,
				unit_price = :unit_price,
				cost = :cost,
				reorder_level = :reorder_level,
				category_id = :category_id,
                attribute = :attribute,
                notes_as_description = :notes_as_description,
                show_description = :show_description
			WHERE
				id = :id
			AND domain_id = :domain_id";

	return dbQuery($sql,
		':domain_id',$domain_id,
		':description', $_POST[description],
		':enabled', $_POST['enabled'],
		':notes', $_POST[notes],
		':default_tax_id', $_POST['default_tax_id'],
		':custom_field1', $_POST[custom_field1],
		':custom_field2', $_POST[custom_field2],
		':custom_field3', $_POST[custom_field3],
		':custom_field4', $_POST[custom_field4],
		':unit_price', $_POST[unit_price],
		':cost', $_POST[cost],
		':reorder_level', $_POST[reorder_level],
		':attribute', json_encode($attr),
		':notes_as_description', $notes_as_description,
		':show_description', $show_description,
		':id', $_GET[id],
		':category_id', $_POST['category_id']
		);
}

function insertCategories($enabled=1,$visible=1, $domain_id='') {
	global $logger;
	$domain_id = domain_id::get($domain_id);
	$buscados = array("�", "�", "�", "�", "�","�");
	$reemplazos = array ("a", "e", "i", "o", "u","n");
	$cadena = strtolower(utf8_decode($_POST['name']));
	$slug = str_replace($buscados, $reemplazos, $cadena);
	$slug = str_replace(" ", "-", $slug);
	$slug = str_replace("?", "", $slug);
	(isset($_POST['enabled'])) ? $enabled = $_POST['enabled']  : $enabled = $enabled ;	
	
	$sql = "INSERT into
		".TB_PREFIX."categories
		(
			name, 
            slug,
            referencia,
            enabled			
		)
	VALUES
		(	
			:name, 
            :slug, 
            :referencia,
            :enabled			
		)";

	return dbQuery($sql,
		':name', $_POST['name'],
		':slug', $slug,
		':referencia', $_POST['referencia'],
		':enabled', $enabled
		);
}

function maxCategory() {

	global $LANG;	
	
	$sql = "SELECT max(category_id) as maxId FROM ".TB_PREFIX."categories";

	$sth = dbQuery($sql);
	return $sth->fetch();
}

function insert_categories_parent($category_id)
{
	$parent = $_POST['parent'];
	$id = maxCategory();
	$category_id  = $id[0];
	if ($parent == -1)
	{
		$parent = 0;
	}
	
	$sql = "INSERT INTO ".TB_PREFIX."categories_taxonomy (category_id, taxonomy, parent, count) VALUES (:category_id, :taxonomy, :parent, :count)";
	
	return dbQuery($sql,
		':category_id', $category_id,
		':taxonomy', 'category',
		':parent', $parent,
		':count', 0
	);
}

function updateCategories() {
	
	$buscados = array("�", "�", "�", "�", "�");
	$reemplazos = array ("a", "e", "i", "o", "u");
	$cadena = strtolower($_POST['name']);
	$slug = str_replace($buscados, $reemplazos, $cadena);
	$slug = str_replace(" ", "-", $slug);
	
	$sql = "UPDATE ".TB_PREFIX."categories
			SET
				name = :name,
				referencia = :referencia,
				slug = :slug,
				enabled = :enabled
			WHERE
				category_id = :category_id";

	return dbQuery($sql,
		':name', $_POST['name'],
		':slug', $slug,
		':referencia', $_POST['referencia'],
		':enabled', $_POST['category_enabled'],
		':category_id', $_GET['id']
		);
}

function update_categories_parent($parent,$category_id)
{
	global $dbh;	
	$parent  = $_POST['parent'];
	$category_id  = $_GET['id'];

	$sql = "UPDATE ".TB_PREFIX."categories_taxonomy SET parent = :parent WHERE category_id = :category_id";
	
	$sth  = dbQuery($sql,':category_id', $category_id,':parent', $parent) or die(htmlsafe(end($dbh->errorInfo())));
}

function getProducts($domain_id='') {
	global $LANG;
	global $db_server;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."products WHERE visible = 1 AND domain_id = :domain_id ORDER BY description";
	if ($db_server == 'pgsql') {
		$sql = "SELECT * FROM ".TB_PREFIX."products WHERE visible and domain_id = :domain_id ORDER BY description";
	}
	$sth = dbQuery($sql, ':domain_id', $domain_id);

	$products = null;

	for($i=0;$product = $sth->fetch();$i++) {
		
		if ($product['enabled'] == 1) {
			$product['enabled'] = $LANG['enabled'];
		} else {
			$product['enabled'] = $LANG['disabled'];
		}

		$products[$i] = $product;
	}

	return $products;
}

function getActiveProducts($domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."products WHERE enabled AND domain_id = :domain_id ORDER BY description";
	$sth = dbQuery($sql, ':domain_id',$domain_id);

	return $sth->fetchAll();
}

function getActiveProductsByCategory($id)
{
	global $dbh;
	global $db_server;
	global $auth_session;
	
	$sql = "SELECT * FROM ".TB_PREFIX."products WHERE enabled and domain_id = :domain_id ORDER BY description";
	$sth = dbQuery($sql, ':domain_id', $auth_session->domain_id)or die(htmlsafe(end($dbh->errorInfo())));
	
	return $sth->fetchAll();
}

function getTaxes($domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."tax WHERE domain_id = :domain_id ORDER BY tax_description";
	$sth = dbQuery($sql, ':domain_id', $domain_id);

	$taxes = null;

	for($i=0;$tax = $sth->fetch();$i++) {
		if ($tax['tax_enabled'] == 1) {
			$tax['enabled'] = $LANG['enabled'];
		} else {
			$tax['enabled'] = $LANG['disabled'];
		}

		$taxes[$i] = $tax;
	}

	return $taxes;
}

function getDefaultGeneric($param, $bool=true, $domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT value FROM ".TB_PREFIX."system_defaults s WHERE ( s.name = :param AND s.domain_id = :domain_id)";
	$sth = dbQuery($sql, ':param', $param, ':domain_id', $domain_id);
	$array = $sth->fetch();
	$paramval = (($bool) ? ($array['value']==1?$LANG['enabled']:$LANG['disabled']) : $array['value']);
	return $paramval;
}

function getDefaultCustomer($domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT c.name AS name FROM ".TB_PREFIX."customers c, ".TB_PREFIX."system_defaults s WHERE ( s.name = 'customer' AND c.id = s.value AND c.domain_id = s.domain_id AND s.domain_id = :domain_id)";
	$sth = dbQuery($sql, ':domain_id', $domain_id);
	return $sth->fetch();
}

function getDefaultPaymentType($domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT p.pt_description AS pt_description FROM ".TB_PREFIX."payment_types p, ".TB_PREFIX."system_defaults s WHERE ( s.name = 'payment_type' AND p.pt_id = s.value AND p.domain_id = s.domain_id AND s.domain_id = :domain_id)";
	$sth = dbQuery($sql,':domain_id', $domain_id);
	return $sth->fetch();
}

function getDefaultPreference($domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."preferences p, ".TB_PREFIX."system_defaults s WHERE ( s.name = 'preference' AND p.pref_id = s.value AND p.domain_id = s.domain_id AND s.domain_id = :domain_id)";
	$sth = dbQuery($sql,':domain_id', $domain_id);
	return $sth->fetch();
}

function getDefaultBiller($domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT b.name AS name FROM ".TB_PREFIX."biller b, ".TB_PREFIX."system_defaults s WHERE ( s.name = 'biller' AND b.id = s.value AND b.domain_id = s.domain_id AND s.domain_id = :domain_id)";
	$sth = dbQuery($sql,':domain_id', $domain_id);
	return $sth->fetch();
}

function getDefaultTax($domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."tax t, ".TB_PREFIX."system_defaults s WHERE s.name = 'tax' AND t.tax_id = s.value AND t.domain_id = s.domain_id AND s.domain_id = :domain_id";
	$sth = dbQuery($sql,':domain_id',$domain_id);
	return $sth->fetch();
}

function getDefaultDelete() {
	return getDefaultGeneric('delete');
}

function getDefaultLogging() {
	return getDefaultGeneric('logging');
}

function getDefaultLoggingStatus() {
	return (getDefaultGeneric('logging', false) == 1);
}

function getDefaultInventory() {
	return getDefaultGeneric('inventory');
}

function getDefaultProductAttributes() {
	return getDefaultGeneric('product_attributes');
}

function getDefaultLargeDataset() {
	return getDefaultGeneric('large_dataset');
}

function getDefaultLanguage() {
	return getDefaultGeneric('language', false);
}

function getInvoiceTotal($invoice_id, $domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql ="SELECT SUM(total) AS total FROM ".TB_PREFIX."invoice_items WHERE invoice_id =  :invoice_id AND domain_id = :domain_id";
	$sth = dbQuery($sql, ':invoice_id', $invoice_id,':domain_id', $domain_id);
	$res = $sth->fetch();
	return $res['total'];
}

function setInvoiceStatus($invoice, $status, $domain_id=''){

	$domain_id = domain_id::get($domain_id);

	$sql = "UPDATE " . TB_PREFIX . "invoices SET status_id =  :status WHERE id =  :id AND domain_id = :domain_id";
	$sth  = dbQuery($sql, ':status', $status, ':id', $invoice,':domain_id', $domain_id);
}

function getInvoice($id, $domain_id='') {
	global $config;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."invoices WHERE id =  :id AND domain_id =  :domain_id";

	$sth  = dbQuery($sql, ':id', $id, ':domain_id', $domain_id);

	$invoice = $sth->fetch();

	$invoice['calc_date'] = date('Y-m-d', strtotime( $invoice['date'] ) );
	$invoice['date'] = siLocal::date( $invoice['date'] );
	$invoice['total'] = getInvoiceTotal($invoice['id']);

	$invoiceobj = new invoice();
	$invoiceobj->domain_id = $domain_id;
	$invoice['gross'] = $invoiceobj->getInvoiceGross($invoice['id']);

	$invoice['paid'] = calc_invoice_paid($invoice['id']);
	$invoice['owing'] = $invoice['total'] - $invoice['paid'];

	#invoice total tax
	$sql ="SELECT SUM(tax_amount) AS total_tax, SUM(total) AS total FROM ".TB_PREFIX."invoice_items WHERE invoice_id =  :id AND domain_id =  :domain_id";
	$sth = dbQuery($sql, ':id', $id, ':domain_id', $domain_id);
	$result = $sth->fetch();
	//$invoice['total'] = number_format($result['total'],2);
	$invoice['total_tax'] = $result['total_tax'];

	$invoice['tax_grouped'] = taxesGroupedForInvoice($id);

	return $invoice;
}

/*
Function: taxesGroupedForInvoice
Purpose: to show a nice summary of total $ for tax for an invoice
*/
function numberOfTaxesForInvoice($invoice_id, $domain_id='')
{
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT
				DISTINCT tax.tax_id
			FROM
				".TB_PREFIX."invoice_item_tax item_tax,
				".TB_PREFIX."invoice_items item,
				".TB_PREFIX."tax tax
			WHERE
				item.id = item_tax.invoice_item_id
			AND tax.tax_id = item_tax.tax_id
			AND tax.domain_id = item.domain_id
			AND item.invoice_id = :invoice_id
			AND tax.domain_id = :domain_id
			GROUP BY
				tax.tax_id;";
	$sth = dbQuery($sql, ':invoice_id', $invoice_id, ':domain_id', $domain_id);
	$result = $sth->rowCount();

	return $result;

}

/*
Function: taxesGroupedForInvoice
Purpose: to show a nice summary of total $ for tax for an invoice
*/
function taxesGroupedForInvoice($invoice_id, $domain_id='')
{
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT
				tax.tax_description as tax_name,
				SUM(item_tax.tax_amount) as tax_amount,
				item_tax.tax_rate as tax_rate,
				count(*) as count
			FROM
				".TB_PREFIX."invoice_item_tax item_tax,
				".TB_PREFIX."invoice_items item,
				".TB_PREFIX."tax tax
			WHERE
				item.id = item_tax.invoice_item_id
			AND tax.tax_id = item_tax.tax_id
			AND tax.domain_id = item.domain_id
			AND item.invoice_id = :invoice_id
			AND tax.domain_id = :domain_id
			GROUP BY
				tax.tax_id;";
	$sth = dbQuery($sql, ':invoice_id', $invoice_id, ':domain_id', $domain_id);
	$result = $sth->fetchAll();

	return $result;

}

/*
Function: taxesGroupedForInvoiceItem
Purpose: to show a nice summary of total $ for tax for an invoice item - used for invoice editing
*/
function taxesGroupedForInvoiceItem($invoice_item_id, $domain_id='')
{
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT
				item_tax.id as row_id,
				tax.tax_description as tax_name,
				tax.tax_id as tax_id
			FROM
				".TB_PREFIX."invoice_item_tax item_tax,
				".TB_PREFIX."tax tax
			WHERE
				item_tax.invoice_item_id = :invoice_item_id
			AND tax.tax_id = item_tax.tax_id
			AND tax.domain_id = :domain_id
			ORDER BY
				row_id ASC;";
	$sth = dbQuery($sql, ':invoice_item_id', $invoice_item_id, ':domain_id', $domain_id);
	$result = $sth->fetchAll();

	return $result;

}

function setStatusExtension($extension_id, $status=2, $domain_id='') {

	$domain_id = domain_id::get($domain_id);

	//status=2 = toggle status
	if ($status == 2) {
		$sql = "SELECT enabled FROM ".TB_PREFIX."extensions WHERE id = :id AND domain_id = :domain_id LIMIT 1";
		$sth = dbQuery($sql,':id', $extension_id, ':domain_id', $domain_id);
		$extension_info = $sth->fetch();
		$status = 1 - $extension_info['enabled'];
	}

	$sql = "UPDATE ".TB_PREFIX."extensions SET enabled =  :status WHERE id =  :id AND domain_id =  :domain_id"; 
	if (dbQuery($sql, ':status', $status,':id', $extension_id, ':domain_id', $domain_id)) {
		return true;
	}
	return false;
}

function getExtensionID($extension_name = "none", $domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."extensions WHERE name = :extension_name AND (domain_id =  0 OR domain_id = :domain_id ) ORDER BY domain_id DESC LIMIT 1";
	$sth = dbQuery($sql,':extension_name', $extension_name, ':domain_id', $domain_id);
	$extension_info = $sth->fetch();
	if (! $extension_info) { return -2; }			// -2 = no result set = extension not found
	if ($extension_info['enabled'] == 0) { return -1; }	// -1 = extension not enabled
	return $extension_info['id'];				//  0 = core, >0 is extension id
}

function getSystemDefaults($domain_id='') {

	$domain_id = domain_id::get($domain_id);

	$db = new db();

    #get sql patch level - if less than 198 do sql with no exntesion table
    if ((checkTableExists(TB_PREFIX."system_defaults") == false))
    {
        return null;
    }
    if (getNumberOfDoneSQLPatches() < "198")
    {

        $sql_default  = "SELECT 
                                def.name,
                                def.value
                         FROM 
                            ".TB_PREFIX."system_defaults def";
        
        $sth = $db->query($sql_default);	

    }
    if (getNumberOfDoneSQLPatches() >= "198")
    {
        $sql_default  = "SELECT 
                                def.name,
                                def.value
                         FROM 
                            ".TB_PREFIX."system_defaults def
                         INNER JOIN
                             ".TB_PREFIX."extensions ext ON (def.domain_id = ext.domain_id)";
        $sql_default .= " WHERE enabled=1";
        $sql_default .= " AND ext.name = 'core'";
        $sql_default .= " AND def.domain_id = :domain_id";
        $sql_default .= " ORDER BY extension_id ASC";		// order is important for overriding settings

        // get all settings from default domain (0)
        //$sth = dbQuery($sql.$current_settings.$order, 'domain_id', 0) or die(htmlsafe(end($dbh->errorInfo())));

        $sth = $db->query($sql_default, ':domain_id', 0);	
	}

	$defaults = null;
	$default = null;

	while($default = $sth->fetch()) {
		$defaults["$default[name]"] = $default['value'];
	}

    if (getNumberOfDoneSQLPatches() > "198")
    {
        $sql  = "SELECT def.name,def.value FROM ".TB_PREFIX."system_defaults def ";
		$sql .= " INNER JOIN ".TB_PREFIX."extensions ext ON (def.extension_id = ext.id)";
        $sql .= " WHERE enabled=1";
        $sql .= " AND def.domain_id = :domain_id";
        $sql .= " ORDER BY extension_id ASC";		// order is important for overriding settings

        // add all settings from current domain
        //$sth = dbQuery($sql.$current_settings.$order, 'domain_id', $auth_session->domain_id) or die(htmlsafe(end($dbh->errorInfo())));
        $sth = $db->query($sql, 'domain_id', $domain_id);
        $default = null;

        while($default = $sth->fetch()) {
            $defaults["$default[name]"] = $default['value'];	// if setting is redefined, overwrite the previous value
        }
    }

	return $defaults;

}

function updateDefault($name,$value,$extension_name="core") {

	$domain_id = domain_id::get();

	$extension_id = getExtensionID($extension_name);
	if (!($extension_id >= 0))
	{
		die(htmlsafe("Invalid extension name: ".$extension)); 
	}

	$sql = "INSERT INTO 
		`".TB_PREFIX."system_defaults`
		(
			`name`, `value`, domain_id, extension_id
		)
		VALUES 
		(
			:name, :value, :domain_id, :extension_id
		) 
		ON DUPLICATE KEY UPDATE
			`value` =  :value";

	if (dbQuery($sql, 
		':value', $value, 
		':domain_id', $domain_id, 
		':name', $name, 
		':extension_id', $extension_id
		)
	) return true; 
	return false;
}

function getInvoiceType($id) {

	$sql = "SELECT * FROM ".TB_PREFIX."invoice_type WHERE inv_ty_id = :id";
	$sth = dbQuery($sql, ':id', $id);
	return $sth->fetch();
}

function insertBiller() {
	global $db_server;
	$domain_id = domain_id::get();

	if ($db_server == 'pgsql') {
		$sql = "INSERT into
			".TB_PREFIX."biller (
				domain_id, name, street_address, street_address2, city,
				state, zip_code, country, phone, mobile_phone,
				fax, email, logo, footer, notes, custom_field1,
				custom_field2, custom_field3, custom_field4,
				enabled
			)
		VALUES
			(
				:domain_id, :name, :street_address, :street_address2, :city,
				:state, :zip_code, :country, :phone,
				:mobile_phone, :fax, :email, :logo, :footer,
				:notes, :custom_field1, :custom_field2,
				:custom_field3, :custom_field4, :enabled
			 )";
	} else {
		$sql = "INSERT into
			".TB_PREFIX."biller
			(
				id, domain_id, name, street_address, street_address2, city,
				state, zip_code, country, phone, mobile_phone,
				fax, email, logo, footer, paypal_business_name, 
				paypal_notify_url, paypal_return_url, eway_customer_id, 
                paymentsgateway_api_id, notes, custom_field1,
				custom_field2, custom_field3, custom_field4,
				enabled

			)
		VALUES
			(
				NULL,
				:domain_id,
				:name,
				:street_address,
				:street_address2,
				:city,
				:state,
				:zip_code,
				:country,
				:phone,
				:mobile_phone,
				:fax,
				:email,
				:logo,
				:footer,
				:paypal_business_name,
				:paypal_notify_url,
				:paypal_return_url,
				:eway_customer_id,
				:paymentsgateway_api_id,
				:notes,
				:custom_field1,
				:custom_field2,
				:custom_field3,
				:custom_field4,
				:enabled
			 )";
	}

	return dbQuery($sql,
		':name', $_POST['name'],
		':street_address', $_POST['street_address'],
		':street_address2', $_POST['street_address2'],
		':city', $_POST['city'],
		':state', $_POST['state'],
		':zip_code', $_POST['zip_code'],
		':country', $_POST['country'],
		':phone', $_POST['phone'],
		':mobile_phone', $_POST['mobile_phone'],
		':fax', $_POST['fax'],
		':email', $_POST['email'],
		':logo', $_POST['logo'],
		':footer', $_POST['footer'],
		':paypal_business_name', $_POST['paypal_business_name'],
		':paypal_notify_url', $_POST['paypal_notify_url'],
		':paypal_return_url', $_POST['paypal_return_url'],
		':eway_customer_id', $_POST['eway_customer_id'],
		':paymentsgateway_api_id', $_POST['paymentsgateway_api_id'],
		':notes', $_POST['notes'],
		':custom_field1', $_POST['custom_field1'],
		':custom_field2', $_POST['custom_field2'],
		':custom_field3', $_POST['custom_field3'],
		':custom_field4', $_POST['custom_field4'],
		':enabled', $_POST['enabled'],
		':domain_id', $domain_id
		);
	/*
	if($query = mysqlQuery($sql)) {
		
		//error_log("iii:".mysql_insert_id());
		return $query;
	}
	else {
		return false;
	}*/
}

function updateBiller() {

	$domain_id = domain_id::get();

	$sql = "UPDATE
				".TB_PREFIX."biller
			SET
				domain_id = :domain_id,
				name = :name,
				street_address = :street_address,
				street_address2 = :street_address2,
				city = :city,
				state = :state,
				zip_code = :zip_code,
				country = :country,
				phone = :phone,
				mobile_phone = :mobile_phone,
				fax = :fax,
				email = :email,
				logo = :logo,
				footer = :footer,
				paypal_business_name = :paypal_business_name,
				paypal_notify_url = :paypal_notify_url,
				paypal_return_url = :paypal_return_url,
				eway_customer_id = :eway_customer_id,
				paymentsgateway_api_id = :paymentsgateway_api_id,
				notes = :notes,
				custom_field1 = :custom_field1,
				custom_field2 = :custom_field2,
				custom_field3 = :custom_field3,
				custom_field4 = :custom_field4,
				enabled = :enabled
			WHERE
				id = :id";
	return dbQuery($sql,
		':domain_id', $domain_id,
		':name', $_POST['name'],
		':street_address', $_POST['street_address'],
		':street_address2', $_POST['street_address2'],
		':city', $_POST['city'],
		':state', $_POST['state'],
		':zip_code', $_POST['zip_code'],
		':country', $_POST['country'],
		':phone', $_POST['phone'],
		':mobile_phone', $_POST['mobile_phone'],
		':fax', $_POST['fax'],
		':email', $_POST['email'],
		':logo', $_POST['logo'],
		':footer', $_POST['footer'],
		':paypal_business_name', $_POST['paypal_business_name'],
		':paypal_notify_url', $_POST['paypal_notify_url'],
		':paypal_return_url', $_POST['paypal_return_url'],
		':eway_customer_id', $_POST['eway_customer_id'],
		':paymentsgateway_api_id', $_POST['paymentsgateway_api_id'],
		':notes', $_POST['notes'],
		':custom_field1', $_POST['custom_field1'],
		':custom_field2', $_POST['custom_field2'],
		':custom_field3', $_POST['custom_field3'],
		':custom_field4', $_POST['custom_field4'],
		':enabled', $_POST['enabled'],
		':id', $_GET[id]
		);
}

function updateCustomer() {
	global $config;
	$domain_id = domain_id::get();

//	$encrypted_credit_card_number = '';
	$is_new_cc_num = ($_POST['credit_card_number_new'] !='');

	$sql = "UPDATE
				".TB_PREFIX."customers
			SET
				domain_id = :domain_id,
				attention = :attention,
				name = :name,
				department = :department,
				street_address = :street_address,
				street_address2 = :street_address2,
				city = :city,
				state = :state,
				zip_code = :zip_code,
				country = :country,
				phone = :phone,
				mobile_phone = :mobile_phone,
				fax = :fax,
				email = :email,
				credit_card_holder_name = :credit_card_holder_name,
                " . (($is_new_cc_num) ? 'credit_card_number = :credit_card_number,' : '') . "
				credit_card_expiry_month = :credit_card_expiry_month,
				credit_card_expiry_year = :credit_card_expiry_year,
				notes = :notes,
				custom_field1 = :custom_field1,
				custom_field2 = :custom_field2,
				custom_field3 = :custom_field3,
				custom_field4 = :custom_field4,
				enabled = :enabled
			WHERE
				id = :id";

	if($is_new_cc_num)
	{
		$credit_card_number = $_POST['credit_card_number_new'];
        
        //cc
        $enc = new encryption();
        $key = $config->encryption->default->key;
        $encrypted_credit_card_number = $enc->encrypt($key, $credit_card_number);

		return dbQuery($sql,
			':domain_id', $domain_id,
			':attention', $_POST['attention'],
			':name', $_POST['name'],
			':department', $_POST['department'],
			':street_address', $_POST['street_address'],
			':street_address2', $_POST['street_address2'],
			':city', $_POST['city'],
			':state', $_POST['state'],
			':zip_code', $_POST['zip_code'],
			':country', $_POST['country'],
			':phone', $_POST['phone'],
			':mobile_phone', $_POST['mobile_phone'],
			':fax', $_POST['fax'],
			':email', $_POST['email'],
			':notes', $_POST['notes'],
			':credit_card_holder_name', $_POST['credit_card_holder_name'],
			':credit_card_number', $encrypted_credit_card_number,
			':credit_card_expiry_month', $_POST['credit_card_expiry_month'],
			':credit_card_expiry_year', $_POST['credit_card_expiry_year'],
			':custom_field1', $_POST['custom_field1'],
			':custom_field2', $_POST['custom_field2'],
			':custom_field3', $_POST['custom_field3'],
			':custom_field4', $_POST['custom_field4'],
			':enabled', $_POST['enabled'],
			':id', $_GET['id']
		);
	} else {
		return dbQuery($sql,
			':domain_id', $domain_id,
			':attention', $_POST['attention'],
			':name', $_POST['name'],
			':department', $_POST['department'],
			':street_address', $_POST['street_address'],
			':street_address2', $_POST['street_address2'],
			':city', $_POST['city'],
			':state', $_POST['state'],
			':zip_code', $_POST['zip_code'],
			':country', $_POST['country'],
			':phone', $_POST['phone'],
			':mobile_phone', $_POST['mobile_phone'],
			':fax', $_POST['fax'],
			':email', $_POST['email'],
			':notes', $_POST['notes'],
			':credit_card_holder_name', $_POST['credit_card_holder_name'],
			':credit_card_expiry_month', $_POST['credit_card_expiry_month'],
			':credit_card_expiry_year', $_POST['credit_card_expiry_year'],
			':custom_field1', $_POST['custom_field1'],
			':custom_field2', $_POST['custom_field2'],
			':custom_field3', $_POST['custom_field3'],
			':custom_field4', $_POST['custom_field4'],
			':enabled', $_POST['enabled'],
			':id', $_GET['id']
		);
	}
}

function insertCustomer() {
    global $config;
	$domain_id = domain_id::get();

	extract( $_POST );
	$sql = "INSERT INTO 
			".TB_PREFIX."customers
			(
				domain_id, attention, name, department, street_address, street_address2,
				city, state, zip_code, country, phone, mobile_phone,
				fax, email, notes,
				credit_card_holder_name, credit_card_number,
				credit_card_expiry_month, credit_card_expiry_year, 
				custom_field1, custom_field2,
				custom_field3, custom_field4, enabled
			)
			VALUES 
			(
				:domain_id ,:attention, :name, :department, :street_address, :street_address2,
				:city, :state, :zip_code, :country, :phone, :mobile_phone,
				:fax, :email, :notes, 
				:credit_card_holder_name, :credit_card_number,
				:credit_card_expiry_month, :credit_card_expiry_year, 
				:custom_field1, :custom_field2,
				:custom_field3, :custom_field4, :enabled
			)";
	//cc
	$enc = new encryption();
    $key = $config->encryption->default->key;
	$encrypted_credit_card_number = $enc->encrypt($key, $credit_card_number);

	return dbQuery($sql,
		':attention', $attention,
		':name', $name,
		':department', $department,
		':street_address', $street_address,
		':street_address2', $street_address2,
		':city', $city,
		':state', $state,
		':zip_code', $zip_code,
		':country', $country,
		':phone', $phone,
		':mobile_phone', $mobile_phone,
		':fax', $fax,
		':email', $email,
		':notes', $notes,
		':credit_card_holder_name', $credit_card_holder_name,
		':credit_card_number', $encrypted_credit_card_number,
		':credit_card_expiry_month', $credit_card_expiry_month,
		':credit_card_expiry_year', $credit_card_expiry_year,
		':custom_field1', $custom_field1,
		':custom_field2', $custom_field2,
		':custom_field3', $custom_field3,
		':custom_field4', $custom_field4,
		':enabled', $enabled,
		':domain_id',$domain_id
		);

}

function searchCustomers($search) {
//TODO remove this function - note used anymore
	global $db_server;
	$domain_id = domain_id::get();

	$sql = "SELECT * FROM ".TB_PREFIX."customers WHERE domain_id = :domain_id AND name LIKE :search";
	if ($db_server == 'pgsql') {
		$sql = "SELECT * FROM ".TB_PREFIX."customers WHERE domain_id = :domain_id AND  name ILIKE :search";
	}
	$sth = dbQuery($sql, ':domain_id',$domain_id, ':search', "%$search%");
	
	$customers = null;
	
	for($i=0; $customer = $sth->fetch(); $i++) {
		$customers[$i] = $customer;
	}

	return $customers;
}

function getInvoices(&$sth) {
	global $config;
	$invoice = null;

	if($invoice = $sth->fetch()) {

		$invoice['calc_date'] = date( 'Y-m-d', strtotime( $invoice['date'] ) );
		$invoice['date'] = siLocal::date($invoice['date']);

		#invoice total total - start
		$invoice['total'] = getInvoiceTotal($invoice['id']);
		#invoice total total - end

		#amount paid calc - start
		$invoice['paid'] = calc_invoice_paid($invoice['id']);
		#amount paid calc - end

		#amount owing calc - start
		$invoice['owing'] = $invoice['total'] - $invoice['paid'];
		#amount owing calc - end
	}
	return $invoice;
}

function getCustomerInvoices($id, $domain_id='') {
	global $config;
	$domain_id = domain_id::get($domain_id);

// tested for MySQL	
	$sql = "SELECT	
		iv.id, 
		iv.index_id, 
		iv.date, 
		iv.type_id, 
		(SELECT SUM( COALESCE(ii.total, 0))     FROM " . TB_PREFIX . "invoice_items ii WHERE ii.invoice_id = iv.id AND ii.domain_id = iv.domain_id) AS invd,
		(SELECT SUM( COALESCE(ap.ac_amount, 0)) FROM " . TB_PREFIX . "payment ap       WHERE ap.ac_inv_id = iv.id  AND ap.domain_id = iv.domain_id) AS pmt,
		(SELECT COALESCE(invd, 0)) As total, 
		(SELECT COALESCE(pmt, 0)) As paid, 
		(select (total - paid)) as owing,
		pr.status,
		pr.pref_inv_wording
	FROM 
		" . TB_PREFIX . "invoices iv
		LEFT JOIN ".TB_PREFIX."preferences pr ON (pr.pref_id = iv.preference_id AND pr.domain_id = iv.domain_id)
	WHERE 
		iv.customer_id = :id
	AND iv.domain_id = :domain_id
	ORDER BY 
		iv.id DESC;";	

	$sth = dbQuery($sql, ':id', $id, ':domain_id', $domain_id);

	$invoices = null;
	while ($invoice = $sth->fetch()) {
		$invoice['calc_date'] = date( 'Y-m-d', strtotime( $invoice['date'] ) );
		$invoice['date'] = siLocal::date( $invoice['date'] );
		$invoices[] = $invoice;
	}
	return $invoices;

}

function getCustomers($domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$customer = null;

	$sql = "SELECT * FROM ".TB_PREFIX."customers WHERE domain_id = :domain_id";
	$sth = dbQuery($sql,':domain_id', $domain_id);

	$customers = null;

	for($i=0; $customer = $sth->fetch(); $i++) {
		if ($customer['enabled'] == 1) {
			$customer['enabled'] = $LANG['enabled'];
		} else {
			$customer['enabled'] = $LANG['disabled'];
		}

		#invoice total calc - start
		$customer['total'] = calc_customer_total($customer['id']);
		#invoice total calc - end

		#amount paid calc - start
		$customer['paid'] = calc_customer_paid($customer['id']);
		#amount paid calc - end

		#amount owing calc - start
		$customer['owing'] = $customer['total'] - $customer['paid'];

		#amount owing calc - end
		$customers[$i] = $customer;

	}

	return $customers;
}

function getActiveCustomers($domain_id='') {
	global $LANG;
	global $db_server;
	$domain_id = domain_id::get($domain_id);

	$sql = "SELECT * FROM ".TB_PREFIX."customers WHERE enabled != 0 and domain_id = :domain_id ORDER BY name";
	if ($db_server == 'pgsql') {
		$sql = "SELECT * FROM ".TB_PREFIX."customers WHERE enabled and domain_id = :domain_id ORDER BY name";
	}
	$sth = dbQuery($sql,':domain_id', $domain_id);

	return $sth->fetchAll();
}

/* DELETE this function */
function getTopDebtor($domain_id='') {

  $domain_id = domain_id::get($domain_id);

  $debtor = null;

  #Largest debtor query - start

	$sql = "SELECT	
			c.id AS \"CID\",
	        c.name AS \"Customer\",
       		SUM(ii.total) AS \"Total\",
	        (SELECT COALESCE(SUM(ap.ac_amount), 0) FROM ".TB_PREFIX."payment ap INNER JOIN ".TB_PREFIX."invoices iv2 ON (ap.ac_inv_id = iv2.id AND ap.domain_id = iv2.domain_id) WHERE iv2.customer_id = c.id AND iv2.domain_id = c.domain_id) AS \"Paid\",
	        SUM(ii.total) - (SELECT COALESCE(SUM(ap.ac_amount), 0) FROM ".TB_PREFIX."payment ap INNER JOIN ".TB_PREFIX."invoices iv2 ON (ap.ac_inv_id = iv2.id AND ap.domain_id = iv2.domain_id) WHERE iv2.customer_id = c.id AND iv2.domain_id = c.domain_id) AS \"Owing\"
	FROM
	        ".TB_PREFIX."customers c INNER JOIN
		".TB_PREFIX."invoices iv ON (c.id = iv.customer_id) INNER JOIN
		".TB_PREFIX."invoice_items ii ON (iv.id = ii.invoice_id)
	WHERE 
		c.domain_id = :domain_id
	GROUP BY
		\"CID\", iv.customer_id, c.id, c.name
	ORDER BY
	        \"Owing\" DESC
	LIMIT 1;
	";

	$sth = dbQuery($sql, ':domain_id', $domain_id);

	$debtor = $sth->fetch();

  #Largest debtor query - end
  return $debtor;
}

/* DELETE this function */
function getTopCustomer($domain_id='') {

  $domain_id = domain_id::get($domain_id);

  $customer = null;

  #Top customer query - start

	$sql2 = "SELECT
			c.id AS \"CID\",
	        c.name AS \"Customer\",
       		SUM(ii.total) AS \"Total\",
	        (SELECT COALESCE(SUM(ap.ac_amount), 0) FROM ".TB_PREFIX."payment ap INNER JOIN ".TB_PREFIX."invoices iv2 ON (ap.ac_inv_id = iv2.id AND ap.domain_id = iv2.domain_id) WHERE iv2.customer_id = c.id AND iv2.domain_id = c.domain_id) AS \"Paid\",
	        SUM(ii.total) - (SELECT COALESCE(SUM(ap.ac_amount), 0) FROM ".TB_PREFIX."payment ap INNER JOIN ".TB_PREFIX."invoices iv2 ON (ap.ac_inv_id = iv2.id AND ap.domain_id = iv2.domain_id) WHERE iv2.customer_id = c.id AND iv2.domain_id = c.domain_id) AS \"Owing\"
	FROM
       	".TB_PREFIX."customers c INNER JOIN
		".TB_PREFIX."invoices iv ON (c.id = iv.customer_id AND iv.domain_id = c.domain_id) INNER JOIN
		".TB_PREFIX."invoice_items ii ON (iv.id = ii.invoice_id AND iv.domain_id = ii.domain_id)
	WHERE
		c.domain_id = :domain_id
	GROUP BY
	        \"CID\", iv.customer_id, \"Customer\"
	ORDER BY 
		\"Total\" DESC
	LIMIT 1;
";

	$tth = dbQuery($sql2,':domain_id',$domain_id);

	$customer = $tth->fetch();

  #Top customer query - end
  return $customer;
}

/* DELETE this function */
function getTopBiller($domain_id='') {

  $domain_id = domain_id::get($domain_id);

  $biller = null;

  #Top biller query - start

	$sql3 = "SELECT
		b.name,  
		sum(ii.total) as Total 
	FROM 
		".TB_PREFIX."biller b INNER JOIN
		".TB_PREFIX."invoices iv ON (b.id = iv.biller_id) INNER JOIN
		".TB_PREFIX."invoice_items ii ON (iv.id = ii.invoice_id)
	WHERE
		b.domain_id = :domain_id
	GROUP BY b.name
	ORDER BY Total DESC
	LIMIT 1;
	";

	$uth = dbQuery($sql3, ':domain_id', $domain_id);

	$biller = $uth->fetch();

  #Top biller query - start
  return $biller;
}

function insertTaxRate($domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql = "INSERT into ".TB_PREFIX."tax
				(domain_id, tax_description, tax_percentage, type,  tax_enabled)
			VALUES
				(:domain_id, :description, :percent, :type, :enabled)";

	$display_block = $LANG['save_tax_rate_success'];
	if (!(dbQuery($sql,
		':domain_id', $domain_id,
		':description', $_POST['tax_description'],
		':percent', $_POST['tax_percentage'],
		':type', $_POST['type'],
		':enabled', $_POST['tax_enabled']))) {
		$display_block = $LANG['save_tax_rate_failure'];
	}
	return $display_block;
}

function updateTaxRate($domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$sql = "UPDATE
				".TB_PREFIX."tax
			SET
				tax_description = :description,
				tax_percentage = :percentage,
				type = :type,
				tax_enabled = :enabled
			WHERE
				tax_id = :id
			AND domain_id = :domain_id
			";

	$display_block = $LANG['save_tax_rate_success'];
	if (!(dbQuery($sql,
		':description', $_POST['tax_description'],
	  	':percentage', $_POST['tax_percentage'],
	  	':enabled', $_POST['tax_enabled'],
	  	':id', $_GET['id'],
	  	':domain_id', $domain_id,
	  	':type', $_POST['type']

		))) {
		$display_block = $LANG['save_tax_rate_failure'];
	}
	return $display_block;
}

//ensure we have a time, else add the current time to the date (to be sure invoices can be sorted by 'date desc')	
function SqlDateWithTime($in_date){
	list($date,$time)=explode(' ', $in_date);
	if(!$time or $time == '00:00:00'){
		$time=date('H:i:s');
	}
	$out_date="$date $time";
	return $out_date;
}

function insertInvoice($type, $domain_id='') {
	global $db_server;
	$domain_id = domain_id::get($domain_id);

	if ($db_server == 'mysql' && !_invoice_check_fk(
		$_POST['biller_id'], $_POST['customer_id'],
		$type, $_POST['preference_id'])) {
		return null;
	}
	$sql = "INSERT INTO
		".TB_PREFIX."invoices (
			id, 
            index_id,
			domain_id,
			biller_id, 
			customer_id, 
			type_id,
			preference_id, 
			date, 
			note,
			custom_field1,
			custom_field2,
			custom_field3,
			custom_field4
		)
		VALUES
		(
			NULL,
			:index_id,
			:domain_id,
			:biller_id,
			:customer_id,
			:type,
			:preference_id,
			:date,
			:note,
			:customField1,
			:customField2,
			:customField3,
			:customField4
			)";

	if ($db_server == 'pgsql') {
		$sql = "INSERT INTO
			".TB_PREFIX."invoices (
				index_id,
				domain_id,
				biller_id, 
				customer_id, 
				type_id,
				preference_id, 
				date, 
				note,
				custom_field1,
				custom_field2,
				custom_field3,
				custom_field4
			)
			VALUES
			(
				:index_id,
				:domain_id,
				:biller_id,
				:customer_id,
				:type,
				:preference_id,
				:date,
				:note,
				:customField1,
				:customField2,
				:customField3,
				:customField4
				)";
	}

    $pref_group=getPreference($_POST[preference_id]);

	//also set the current time (if null or =00:00:00)
	$clean_date=SqlDateWithTime($_POST['date']);

	$sth= dbQuery($sql,
		#':index_id', index::next('invoice',$pref_group[index_group], $domain_id,$_POST[biller_id]),
		':index_id',		index::next('invoice',$pref_group['index_group'], $domain_id),
		':domain_id',		$domain_id,
		':biller_id',		$_POST['biller_id'],
		':customer_id', 	$_POST['customer_id'],
		':type', 			$type,
		':preference_id',	$_POST['preference_id'],
		':date', 			$clean_date,
		':note', 			trim($_POST['note']),
		':customField1',	$_POST['customField1'],
		':customField2',	$_POST['customField2'],
		':customField3',	$_POST['customField3'],
		':customField4',	$_POST['customField4']
		);

    #index::increment('invoice',$pref_group[index_group], $domain_id,$_POST[biller_id]);
	// Needed only if si_index table exists
    index::increment('invoice',$pref_group[index_group], $domain_id);

    return $sth;
}

function updateInvoice($invoice_id, $domain_id='') {
	
//  global $logger;
    global $db_server;
    $domain_id = domain_id::get($domain_id);

	$invoiceobj = new invoice();
    $current_invoice = $invoiceobj->select($_POST['id']);
    $current_pref_group = getPreference($current_invoice['preference_id']);

    $new_pref_group=getPreference($_POST['preference_id']);

    $index_id = $current_invoice['index_id'];

//	$logger->log('Curent Index Group: '.$description, Zend_Log::INFO);
//	$logger->log('Description: '.$description, Zend_Log::INFO);

    if ($current_pref_group['index_group'] != $new_pref_group['index_group'])
    {
        $index_id = index::increment('invoice',$new_pref_group['index_group']);
    }

	if ($db_server == 'mysql' && !_invoice_check_fk(
		$_POST['biller_id'], $_POST['customer_id'],
		$type, $_POST['preference_id'])) {
		return null;
	}
	$sql = "UPDATE
			".TB_PREFIX."invoices
		SET
			index_id = :index_id,
			biller_id = :biller_id,
			customer_id = :customer_id,
			preference_id = :preference_id,
			date = :date,
			note = :note,
			custom_field1 = :customField1,
			custom_field2 = :customField2,
			custom_field3 = :customField3,
			custom_field4 = :customField4
		WHERE
			id = :invoice_id
		AND domain_id = :domain_id";
			
	return dbQuery($sql,
        ':index_id', $index_id,
		':biller_id', $_POST['biller_id'],
		':customer_id', $_POST['customer_id'],
		':preference_id', $_POST['preference_id'],
		':date', $_POST['date'],
		':note', trim($_POST['note']),
		':customField1', $_POST['customField1'],
		':customField2', $_POST['customField2'],
		':customField3', $_POST['customField3'],
		':customField4', $_POST['customField4'],
		':invoice_id', $invoice_id,
		':domain_id', $domain_id
		);
}

function insertInvoiceItem($invoice_id,$quantity,$product_id,$line_number,$line_item_tax_id,$description="", $unit_price="", $attribute="", $domain_id='') {

	global $logger;
	global $db_server;
	global $LANG;
    $domain_id = domain_id::get($domain_id);

    //do taxes

    $attr = array();
	$logger->log('Line item attributes: '.var_export($attribute,true), Zend_Log::INFO);
    foreach($attribute as $k=>$v)
    {
        if($attribute[$v] !== '')
        {
            $attr[$k] = $v;
        }
        
    }

	$tax_total = getTaxesPerLineItem($line_item_tax_id,$quantity, $unit_price, $domain_id);

	$logger->log(' ', Zend_Log::INFO);
	$logger->log(' ', Zend_Log::INFO);
	$logger->log('Invoice: '.$invoice_id.' Tax '.$line_item_tax_id.' for line item '.$line_number.': '.$tax_total, Zend_Log::INFO);
	$logger->log('Description: '.$description, Zend_Log::INFO);
	$logger->log(' ', Zend_Log::INFO);

	//line item gross total
	$gross_total = $unit_price  * $quantity;

	//line item total
	$total = $gross_total + $tax_total;	

	//Remove jquery auto-fill description - refer jquery.conf.js.tpl autofill section
	if ($description == $LANG['description'])
	{	
		$description ="";
	}

	if ($db_server == 'mysql' && !_invoice_items_check_fk(
		$invoice_id, $product_id, $tax['tax_id'])) {
		return null;
	}
	$sql = "INSERT INTO ".TB_PREFIX."invoice_items 
			(
				invoice_id, 
				domain_id, 
				quantity, 
				product_id, 
				unit_price, 
				tax_amount, 
				gross_total, 
				description, 
                total,
                attribute
			) 
			VALUES 
			(
				:invoice_id, 
				:domain_id,
				:quantity, 
				:product_id, 
				:unit_price, 
				:tax_amount, 
				:gross_total, 
				:description, 
                :total,
                :attribute
			)";


	dbQuery($sql,
		':invoice_id', $invoice_id,
		':domain_id', $domain_id,
		':quantity', $quantity,
		':product_id', $product_id,
		':unit_price', $unit_price,
	//	':tax_id', $tax[tax_id],
	//	':tax_percentage', $tax[tax_percentage],
		':tax_amount', $tax_total,
		':gross_total', $gross_total,
		':description', trim($description),
        ':total', $total,
        ':attribute',json_encode($attr)
		);

	invoice_item_tax(lastInsertId(),$line_item_tax_id,$unit_price,$quantity,"insert");
	//TODO fix this
	return true;
}

/*
Function: getTaxesPerLineItem
Purpose: get the total tax for the line item
*/
function getTaxesPerLineItem($line_item_tax_id, $quantity, $unit_price, $domain_id='')
{
	global $logger;
    $domain_id = domain_id::get($domain_id);

	$tax_total = 0;

	foreach($line_item_tax_id as $key => $value) 
	{
		$logger->log("Key: ".$key." Value: ".$value, Zend_Log::INFO);
		$tax = getTaxRate($value, $domain_id);
		$logger->log('tax rate: '.$tax['tax_percentage'], Zend_Log::INFO);

		$tax_amount = lineItemTaxCalc($tax, $unit_price, $quantity);
		//get Total tax for line item
		$tax_total = $tax_total + $tax_amount;

		//$logger->log('Qty: '.$quantity.' Unit price: '.$unit_price, Zend_Log::INFO);
		//$logger->log('Tax rate: '.$tax[tax_percentage].' Tax type: '.$tax['tax_type'].' Tax $: '.$tax_amount, Zend_Log::INFO);

	}
	return $tax_total;
}

/*
Function: lineItemTaxCalc
Purpose: do the calc for the tax for tax x on line item y
*/
function lineItemTaxCalc($tax, $unit_price, $quantity)
{
	if($tax['type'] == "%")
	{
		$tax_amount = ( ($tax['tax_percentage'] / 100)  * $unit_price ) * $quantity;
	}
	if($tax['type'] == "$")
	{
		$tax_amount = $tax['tax_percentage'] * $quantity;
	}

	return $tax_amount;
}
/*
Function: invoice_item_tax
Purpose: insert/update the multiple taxes per line item into the si_invoice_item_tax table
*/
function invoice_item_tax($invoice_item_id, $line_item_tax_id, $unit_price, $quantity, $action='', $domain_id='') {
	
	global $logger;
    $domain_id = domain_id::get($domain_id);

	//if editing invoice delete all tax info then insert first then do insert again
	//probably can be done without delete - someone to look into this if required - TODO
	if ($action =="update")
	{

		$sql_delete = "DELETE from
							".TB_PREFIX."invoice_item_tax
					   WHERE
							invoice_item_id = :invoice_item_id";
		$logger->log("Invoice item: ".$invoice_item_id." tax lines deleted", Zend_Log::INFO);

		dbQuery($sql_delete,':invoice_item_id',$invoice_item_id);

	}

	foreach($line_item_tax_id as $key => $value) 
	{
		if($value !== "")
		{
			$tax = getTaxRate($value, $domain_id);

			$logger->log("ITEM :: Key: ".$key." Value: ".$value, Zend_Log::INFO);
			$logger->log('ITEM :: tax rate: '.$tax['tax_percentage'], Zend_Log::INFO);
			$logger->log('ITEM :: domain_id: '.$domain_id, Zend_Log::INFO);

			$tax_amount = lineItemTaxCalc($tax,$unit_price,$quantity);
			//get Total tax for line item (unused here)
			// $tax_total = $tax_total + $tax_amount;

			$logger->log('ITEM :: Qty: '.$quantity.' Unit price: '.$unit_price, Zend_Log::INFO);
			$logger->log('ITEM :: Tax rate: '.$tax[tax_percentage].' Tax type: '.$tax['type'].' Tax $: '.$tax_amount, Zend_Log::INFO);

			$sql = "INSERT 
						INTO 
					".TB_PREFIX."invoice_item_tax 
					(
						invoice_item_id, 
						tax_id, 
						tax_type, 
						tax_rate, 
						tax_amount
					) 
					VALUES 
					(
						:invoice_item_id, 
						:tax_id,
						:tax_type,
						:tax_rate,
						:tax_amount
					)";

			dbQuery($sql,
				':invoice_item_id', $invoice_item_id,
				':tax_id', $tax['tax_id'],
				':tax_type', $tax['type'],
				':tax_rate', $tax['tax_percentage'],
				':tax_amount', $tax_amount
				);
		}
	}
	//TODO fix this
	return true;
}

function updateInvoiceItem($id, $quantity, $product_id, $line_number, $line_item_tax_id, $description, $unit_price, $attribute="", $domain_id='') {

	global $logger;
	global $LANG;
	global $db_server;
    $domain_id = domain_id::get($domain_id);

	//$product = getProduct($product_id);
	//$tax = getTaxRate($tax_id);

    $attr = array();
	$logger->log('Line item attributes: '.var_export($attribute,true), Zend_Log::INFO);
    foreach($attribute as $k=>$v)
    {
        if($attribute[$v] !== '')
        {
            $attr[$k] = $v;
        }

    }

	$tax_total = getTaxesPerLineItem($line_item_tax_id,$quantity, $unit_price);

	$logger->log('Invoice: '.$invoice_id.' Tax '.$line_item_tax_id.' for line item '.$line_number.': '.$tax_total, Zend_Log::INFO);
	$logger->log('Description: '.$description, Zend_Log::INFO);
	$logger->log(' ', Zend_Log::INFO);

	//line item gross total
	$gross_total = $unit_price  * $quantity;

	//line item total
	$total = $gross_total + $tax_total;	

	//Remove jquery auto-fill description - refer jquery.conf.js.tpl autofill section
	if ($description == $LANG['description'])
	{	
		$description ="";
	}

	if ($db_server == 'mysql' && !_invoice_items_check_fk(
		null, $product_id, $tax_id, 'update')) {
		return null;
	}

	$sql = "UPDATE ".TB_PREFIX."invoice_items 
	SET quantity =  :quantity,
	product_id = :product_id,
	unit_price = :unit_price,
	tax_amount = :tax_amount,
	gross_total = :gross_total,
	description = :description,
	total = :total,			
	attribute = :attribute			
	WHERE id = :id AND domain_id = :domain_id";

	dbQuery($sql,
		':quantity', $quantity,
		':product_id', $product_id,
		':unit_price', $unit_price,
		':tax_amount', $tax_total,
		':gross_total', $gross_total,
		':description', $description,
		':total', $total,
        ':attribute',json_encode($attr),
		':id', $id,
		':domain_id', $domain_id
		);

	//if from a new invoice item in the edit page user lastInsertId()
	($id == null) ? $id = lastInsertId() : $id  =$id ;
	invoice_item_tax($id,$line_item_tax_id,$unit_price,$quantity,"update");

	return true;
}

/*
function getMenuStructure() {
	global $LANG;
	global $dbh;
	global $db_server;
	$sql = "SELECT * FROM ".TB_PREFIX."menu WHERE enabled = 1 ORDER BY parentid, `order`";
	if ($db_server == 'pgsql') {
		$sql = "SELECT * FROM ".TB_PREFIX."menu WHERE enabled ORDER BY parentid, \"order\"";
	}
	$sth = dbQuery($sql) or die(htmlsafe(end($dbh->errorInfo())));
	$menu = null;
	
	while($res = $sth->fetch()) {
		//error_log($res['name']);
		$menu[$res['parentid']][$res['id']]["name"] = eval('return "'.$res['name'].'";');
		$menu[$res['parentid']][$res['id']]["link"] = $res['link'];
		$menu[$res['parentid']][$res['id']]["id"] = $res['id'];
	}
	
	echo <<<EOD
	<div id="Header">
		<div id="Tabs">
			<ul id="navmenu">
EOD;

	printEntries($menu,0,1);

echo <<<EOD
		</div id="Tabs">
	</div id="Header">
EOD;
}

function printEntries($menu,$id,$depth) {

	foreach($menu[$id] as $tempentry) {
		for($i=0;$i<$depth;$i++) {
			//echo "&nbsp;&nbsp;&nbsp;";
		}
		echo "
		<li><a href='".$tempentry[link]."'>".htmlsafe($tempentry[name])."</a>
		";
		
		if(isset($menu[$tempentry["id"]])) {
			echo "<ul>";
			printEntries($menu,$tempentry["id"],$depth+1);
			echo "</ul>";
		}
		echo "</li>\n";
	}
}
*/

function searchBillerAndCustomerInvoice($biller, $customer, $domain_id='') {
//TODO remove this function - not used
	global $db_server;
    $domain_id = domain_id::get($domain_id);

	$sql = "SELECT  b.name AS biller, 
	c.name AS customer, 
	i.id AS invoice, 
	i.date AS `date`, 
	i.type_id AS type_id,
	t.inv_ty_description AS `type` 
FROM ".TB_PREFIX."invoices i 
    LEFT JOIN ".TB_PREFIX."invoice_type t ON (i.type_id = t.inv_ty_id)
    LEFT JOIN ".TB_PREFIX."customers c    ON (i.customer_id = c.id AND i.domain_id = c.domain_id)
    LEFT JOIN ".TB_PREFIX."biller b       ON (i.biller_id   = b.id AND i.domain_id = b.domain_id)
WHERE b.name LIKE :biller 
  AND c.name LIKE :customer
  AND i.domain_id = :domain_id";
	if ($db_server == 'pgsql') {
		$sql = str_replace("name LIKE :", "name ILIKE :", $sql);
	}
	return dbQuery($sql,
		':biller',   "%$biller%",
		':customer', "%$customer%",
		':domain_id', $domain_id
		);
}

function searchInvoiceByDate($startdate, $enddate, $domain_id='') {
//TODO remove this function - not used
    $domain_id = domain_id::get($domain_id);

	$sql = "SELECT  b.name AS biller, 
	c.name AS customer, 
	i.id AS invoice, 
	i.date AS `date`, 
	i.type_id AS type_id,
	t.inv_ty_description AS `type` 
FROM ".TB_PREFIX."invoices i 
    LEFT JOIN ".TB_PREFIX."invoice_type t ON (i.type_id = t.inv_ty_id)
    LEFT JOIN ".TB_PREFIX."customers c    ON (i.customer_id = c.id AND i.domain_id = c.domain_id)
    LEFT JOIN ".TB_PREFIX."biller b       ON (i.biller_id   = b.id AND i.domain_id = b.domain_id)
WHERE i.domain_id = :domain_id 
  AND i.date BETWEEN :startdate AND :enddate";
	return dbQuery($sql,
		':startdate', $startdate,
		':enddate',   $enddate,
		':domain_id', $domain_id
		);
}

/*
 * delete attempts to delete rows from the database.  This function currently
 * allows for the deletion of invoices, invoice_items, and products entries,
 * all other $module values will fail.  $idField is also checked on a per-table
 * basis, i.e. invoice_items can be either "id" or "invoice_id" while products
 * can only be "id".
 *
 * Invalid $module or $idFields values return false, as do calls that would fail
 * foreign key checks.  Otherwise, the value returned by dbQuery's deletion
 * attempt is returned.
 */

function delete($module, $idField, $id, $domain_id='') {
	global $dbh;
	global $logger;
    $domain_id = domain_id::get($domain_id);

	$has_domain_id = false;

	$lctable = strtolower($module);
	$s_idField = ''; // Presetting the whitelisted column to fail 

	/*
	 * SC: $valid_tables contains the base names of all tables that can
	 *     have rows deleted using this function.  This is used for
	 *     whitelisting deletion targets.
	 */
	$valid_tables = array('invoices', 'invoice_items', 'invoice_item_tax', 'products');

	if (in_array($lctable, $valid_tables)) {
		// A quick once-over on the dependencies of the possible tables
		if ($lctable == 'invoice_item_tax') 
        {
			// Not required by any FK relationships
			if (!in_array($idField, array('invoice_item_id'))) {
				// Fail, invalid identity field
				return false;
			} else {
				$s_idField = $idField;
			}
        } elseif ($lctable == 'invoice_items') {
			// Not required by any FK relationships
			if (!in_array($idField, array('id', 'invoice_id'))) {
				// Fail, invalid identity field
				return false;
			} else {
				$s_idField = $idField;
			}
		} elseif ($lctable == 'products') {
			$has_domain_id = true;
			// Check for use of product
			$sth = $dbh->prepare('SELECT count(*)
				FROM '.TB_PREFIX.'invoice_items
				WHERE product_id = :id AND domain_id = :domain_id');
			$sth->execute(array(':id' => $id, ':domain_id' => $domain_id));
			$ref = $sth->fetch();
			if ($sth->fetchColumn() != 0) {
				// Fail, product still in use
				return false;
			}
			$sth = null;

			if (!in_array($idField, array('id'))) {
				// Fail, invalid identity field
				return false;
			} else {
				$s_idField = $idField;
			}
		} elseif ($lctable == 'invoices') {
			$has_domain_id = true;
			// Check for existant payments and line items
			$sth = $dbh->prepare('SELECT count(*) FROM (
				SELECT id FROM '.TB_PREFIX.'invoice_items
				WHERE invoice_id = :id AND domain_id = :domain_id
				UNION ALL
				SELECT id FROM '.TB_PREFIX.'payment
				WHERE ac_inv_id = :id AND domain_id = :domain_id) x');
			$sth->execute(array(':id' => $id, ':domain_id' => $domain_id));
			if ($sth->fetchColumn() != 0) {
				// Fail, line items or payments still exist
				return false;
			}
			$sth = null;

			//SC: Later, may accept other values for $idField
			if (!in_array($idField, array('id'))) {
				// Fail, invalid identity field
				return false;
			} else {
				$s_idField = $idField;
			}
		} else {
			// Fail, no checks for this table exist yet
			return false;
		}
	} else {
		// Fail, invalid table name
		return false;
	}

	if ($s_idField == '') {
		// Fail, column whitelisting not performed
		return false;
	}

	// Tablename and column both pass whitelisting and FK checks
	$sql = "DELETE FROM ".TB_PREFIX."$module WHERE $s_idField = :id";
	if ($has_domain_id) $sql .= " AND domain_id = :domain_id";
    $logger->log("Item deleted: ".$sql, ZEND_Log::INFO);
	if ($has_domain_id) 
		return dbQuery($sql, ':id', $id, ':domain_id',$domain_id);
	else
		return dbQuery($sql, ':id', $id);
}

function maxInvoice($domain_id='') {

	global $LANG;
    $domain_id = domain_id::get($domain_id);

	$sql = "SELECT max(id) as maxId FROM ".TB_PREFIX."invoices WHERE domain_id = :domain_id";

	$sth = dbQuery($sql, ':domain_id', $domain_id);
	return $sth->fetch();

//while ($Array_max = mysql_fetch_array($result_max) ) {
//$max_invoice_id = $Array_max['max_inv_id'];
};

//in this file are functions for all sql queries
function checkTableExists($table = "" ) {

	//$db = db::getInstance();
	//var_dump($db);
	$table == "" ? TB_PREFIX."biller" : $table;

  //  echo $table;
	global $LANG;
	global $dbh;
	global $config;
	switch ($config->database->adapter) 
	{

		case "pdo_pgsql":
			$sql = 'SELECT 1 FROM pg_tables WHERE tablename = '.$table.' LIMIT 1';
			break;

		case "pdo_sqlite":
			$sql = 'SELECT * FROM '.$table.'LIMIT 1';
			break;
		case "pdo_mysql":
		default:
		//mysql
			//$sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES where table_name = :table LIMIT 1";
			$sql = "SHOW TABLES LIKE '".$table."'";
			break;
	}

	//$sth = $dbh->prepare($sql);
	$sth = dbQuery($sql);
	if ($sth->fetchAll())
	{
		return true;
	} else {
		return false;
	}

}

function checkFieldExists($table,$field) {

	global $LANG;
	global $dbh;
	global $db_server;

	$sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE column_name = :field AND table_name = :table LIMIT 1";
	if ($db_server == 'pgsql') {
		// Use a nicer syntax
		$sql = "SELECT 1 FROM pg_attribute a INNER JOIN pg_class c ON (a.attrelid = c.oid)  WHERE c.relkind = 'r' AND c.relname = :table AND a.attname = :field AND NOT a.attisdropped AND a.attnum > 0 LIMIT 1";
	}

	$sth = $dbh->prepare($sql);

	if ($sth && $sth->execute(array(':field' => $field, ':table' => $table))) {
		if ($sth->fetch()) {
			return true;
		} else {
			return false;
		}
	} else {
		return false;
	}
}

function checkDataExists()
{
	$test = getNumberOfDoneSQLPatches();
	if ($test > 0 ){
		return true;
	} else {
		return false;
	}
}

function getURL()
{
	global $config;

	$port = "";
	$dir = dirname($_SERVER['PHP_SELF']);
	//remove incorrect slashes for WinXP etc.
 $dir = str_replace('\\','',$dir);

	//set the port of http(s) section
	if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on') {
		$_SERVER['FULL_URL'] = "https://";
	} else {
		$_SERVER['FULL_URL'] = "http://";
	}

	$_SERVER['FULL_URL'] .= $config->authentication->http.$_SERVER['HTTP_HOST'].$dir;

	return $_SERVER['FULL_URL'];

}

function urlPDF($invoiceID) {

	$url = getURL();
//	html2ps does not like &amp; and htmlcharacters encoding - latter useless since InvoiceID comes from an integer field	
//	$script = "/index.php?module=invoices&amp;view=templates/template&amp;invoice=".htmlsafe($invoiceID)."&amp;action=view&amp;location=pdf";
	$script = "/index.php?module=invoices&view=template&id=$invoiceID&action=view&location=pdf";

	$full_url=$url.$script;

	return $full_url;
}

function sql2array($strSql) { 
	global $dbh;
    $sqlInArray = null; 

    $result_strSql = dbQuery($strSql); 

    for($i=0;$sqlInRow = PDOStatement::fetchAll($result_strSql);$i++) { 

        $sqlInArray[$i] = $sqlInRow; 
    } 
    return $sqlInArray; 
}

function getNumberOfDoneSQLPatches() {

	$check_patches_sql = "SELECT count(sql_patch) AS count FROM ".TB_PREFIX."sql_patchmanager ";
	$sth = dbQuery($check_patches_sql);

	$patches = $sth->fetch();

	//Returns number of patches applied
	return $patches['count'];
}

function pdfThis($html,$file_location="",$pdfname)
{

	global $config;

//	set_include_path("../../../../library/pdf/");
	require_once('./library/pdf/config.inc.php');
	require_once('./library/pdf/pipeline.factory.class.php');
	require_once('./library/pdf/pipeline.class.php');
	parse_config_file('./library/pdf/html2ps.config');

	require_once("./include/init.php");	// for getInvoice() and getPreference()
	#$invoice_id = $_GET['id'];
	#$invoice = getInvoice($invoice_id);

	#$preference = getPreference($invoice['preference_id']);
	#$pdfname = trim($preference['pref_inv_wording']) . $invoice_id;

	#error_reporting(E_ALL);
	#ini_set("display_errors","1");
	#@set_time_limit(10000);

	/**
	 * Runs the HTML->PDF conversion with default settings
	 *
	 * Warning: if you have any files (like CSS stylesheets and/or images referenced by this file,
	 * use absolute links (like http://my.host/image.gif).
	 *
	 * @param $path_to_html String path to source html file.
	 * @param $path_to_pdf  String path to file to save generated PDF to.
	 */
	if(!function_exists(convert_to_pdf))
	{
		function convert_to_pdf($html_to_pdf, $pdfname, $file_location="") {

			global $config;

			$destination = $file_location=="download" ? "DestinationDownload" : "DestinationFile";
		  /**
		   * Handles the saving generated PDF to user-defined output file on server
		   */

		 if(!class_exists(MyFetcherLocalFile))
		 {
		  class MyFetcherLocalFile extends Fetcher {
			var $_content;

			function MyFetcherLocalFile($html_to_pdf) {
			  //$this->_content = file_get_contents($file);
			  $this->_content = $html_to_pdf;
			}

			function get_data($dummy1) {
			  return new FetchedDataURL($this->_content, array(), "");
			}

			function get_base_url() {
			  return "";
			}
		  }
		 }

		  $pipeline = PipelineFactory::create_default_pipeline("", ""); // Attempt to auto-detect encoding

		  // Override HTML source 
		  $pipeline->fetchers[] = new MyFetcherLocalFile($html_to_pdf);

		  $baseurl = "";
		  $media = Media::predefined($config->export->pdf->papersize);
		  $media->set_landscape(false);

		  global $g_config;
		  $g_config = array(
							'cssmedia'     => 'screen',
							'renderimages' => true,
							'renderlinks'  => true,
							'renderfields' => true,
							'renderforms'  => false,
							'mode'         => 'html',
							'encoding'     => '',
							'debugbox'     => false,
							'pdfversion'    => '1.4',

							'process_mode'     => 'single',
							//'output'     => 1,
							//'location'     => 'pdf',
							'pixels'     => $config->export->pdf->screensize,
							'media'     => $config->export->pdf->papersize,
				'margins'       => array(
							      'left'    => $config->export->pdf->leftmargin,
							      'right'   => $config->export->pdf->rightmargin,
							      'top'     => $config->export->pdf->topmargin,
							      'bottom'  => $config->export->pdf->bottommargin,
							      ),
							'transparency_workaround'     => 1,
							'imagequality_workaround'     => 1,

							'draw_page_border' => false
							);

			$media->set_margins($g_config['margins']);
			$media->set_pixels($config->export->pdf->screensize);

	/*
	header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
	header("Expires: Mon, 26 Jul 1997 05:00:00 GMT"); 	// Date in the past

	header("Location: $myloc");
	*/
		  global $g_px_scale;
		  $g_px_scale = mm2pt($media->width() - $media->margins['left'] - $media->margins['right']) / $media->pixels; 
		  global $g_pt_scale;
		  $g_pt_scale = $g_px_scale * 1.43; 

		  $pipeline->configure($g_config);
		  $pipeline->data_filters[] = new DataFilterUTF8("");
		  $pipeline->destination = new $destination($pdfname);
		  $pipeline->process($baseurl, $media);
		}
	}

	//echo "location: ".$file_location;
	convert_to_pdf($html, $pdfname, $file_location);

}

// ------------------------------------------------------------------------------
function getNumberOfDonePatches() {

	$check_patches_sql = "SELECT max(sql_patch_ref) AS count FROM ".TB_PREFIX."sql_patchmanager ";
	$sth = dbQuery($check_patches_sql);

	$patches = $sth->fetch();

	//Returns number of patches applied
	return $patches['count'];
}

// ------------------------------------------------------------------------------
function getNumberOfPatches() {
	global $patch;
	#Max patches applied - start

	$patches = getNumberOfDonePatches();
	//$patch_count = count($patch);
	$patch_count = max( array_keys( $patch ) );
	//Returns number of patches to be applied
	return $patch_count - $patches;
}

// ------------------------------------------------------------------------------
function runPatches() {
	global $patch;
	global $db_server;
	global $dbh;
	#DEFINE SQL PATCH

	$display_block = "";

	$sql = "SHOW TABLES LIKE '".TB_PREFIX."sql_patchmanager'";
	if ($db_server == 'pgsql') {
		$sql = "SELECT 1 FROM pg_tables WHERE tablename ='".TB_PREFIX."sql_patchmanager'";
	}
	$sth = dbQuery($sql);
	$rows = $sth->fetchAll();

	$smarty_datas=array();	

	if(count($rows) == 1) {

		if ($db_server == 'pgsql') {
			// Yay!  Transactional DDL
			$dbh->beginTransaction();
		}
		for($i=0;$i < count($patch);$i++) {
//			run_sql_patch($i,$patch[$i]); // use instead of following line if patch application status display is to be suppressed
			$smarty_datas['rows'][$i] = run_sql_patch($i,$patch[$i]);
		}
		if ($db_server == 'pgsql') {
			// Yay!  Transactional DDL
			$dbh->commit();
		}

		$smarty_datas['message']= "The database patches have now been applied. You can now start working with Simple Invoices";
		$smarty_datas['html']	= "<div class='si_toolbar si_toolbar_form'><a href='index.php'>HOME</a></div>";
		$smarty_datas['refresh']=5;
	}
	else {

		$smarty_datas['html']= "Step 1 - This is the first time Database Updates has been run";
		$smarty_datas['html']  =initialise_sql_patch();
		$smarty_datas['html'] .= "<br />
		Now that the Database upgrade table has been initialised, please go back to the Database Upgrade Manger page by clicking 
		the following button to run the remaining patches.
		<div class='si_toolbar si_toolbar_form'><a href='index.php?module=options&amp;view=database_sqlpatches'>Continue</a></div>
		.";

	}

	global $smarty;
	$smarty-> assign("page",$smarty_datas);

}

// ------------------------------------------------------------------------------
function donePatches() {
	$smarty_datas['message']="The database patches are uptodate. You can continue working with Simple Invoices";
	$smarty_datas['html']	= "<div class='si_toolbar si_toolbar_form'><a href='index.php'>HOME</a></div>";
	$smarty_datas['refresh']=3;
	global $smarty;
	$smarty-> assign("page",$smarty_datas);
}

// ------------------------------------------------------------------------------
function listPatches() {
		global $patch;

	//if(mysql_num_rows(mysqlQuery("SHOW TABLES LIKE '".TB_PREFIX."sql_patchmanager'")) == 1) {
		
		$smarty_datas=array();		
		$smarty_datas['message']= "Your version of Simple Invoices can now be upgraded.	With this new release there are database patches that need to be applied";
		$smarty_datas['html']	= <<<EOD
	<p>
			The list below describes which patches have and have not been applied to the database, the aim is to have them all applied.<br />  
			If there are patches that have not been applied to the Simple Invoices database, please run the Update database by clicking update 
	</p>

	<div class="si_message_warning">Warning: Please backup your database before upgrading!</div>

	<div class="si_toolbar si_toolbar_form"><a href="./index.php?case=run" class=""><img src="./images/common/tick.png" alt="" />Update</a></div>
EOD;

		for($p = 0; $p < count($patch);$p++) {
			$patch_name = htmlsafe($patch[$p]['name']);
			$patch_date = htmlsafe($patch[$p]['date']);
			if(check_sql_patch($p,$patch[$p]['name'])) {
				$smarty_datas['rows'][$p]['text']	= "SQL patch $p, $patch_name <i>has</i> already been applied in release $patch_date";
				$smarty_datas['rows'][$p]['result']	='skip';
			}
			else {
				$smarty_datas['rows'][$p]['text']	= "SQL patch $p, $patch_name <b>has not</b> been applied to the database";
				$smarty_datas['rows'][$p]['result']	='todo';
			}	
		}

	global $smarty;
	$smarty-> assign("page",$smarty_datas);
}

// ------------------------------------------------------------------------------
function check_sql_patch($check_sql_patch_ref, $check_sql_patch_field) {
   	$sql = "SELECT * FROM ".TB_PREFIX."sql_patchmanager WHERE sql_patch_ref = :patch" ;
	$sth = dbQuery($sql, ':patch', $check_sql_patch_ref);

	if(count($sth->fetchAll()) > 0) {
		return true;
	}
	return false;
}

// ------------------------------------------------------------------------------
function run_sql_patch($id, $patch) {
	global $dbh;
	global $db_server;
	$display_block = "";

	$sql = "SELECT * FROM ".TB_PREFIX."sql_patchmanager WHERE sql_patch_ref = :id" ;
	$sth = dbQuery($sql, ':id', $id);

	$escaped_id = htmlsafe($id);
	$patch_name = htmlsafe($patch['name']);
	#forget about the patch as it has already been run!!

	$smarty_row=array();

	if (count($sth->fetchAll()) != 0)  {

		$smarty_row['text']		= "Skipping SQL patch $escaped_id, $patch_name as it <i>has</i> already been applied";
		$smarty_row['result']	="skip";
	}
	else {

		//patch hasn't been run
		#so run the patch
		dbQuery($patch['patch']);

		$smarty_row['text']	= "SQL patch $escaped_id, $patch_name <i>has</i> been applied to the database";
		$smarty_row['result']	="done";

		# now update the ".TB_PREFIX."sql_patchmanager table		
		$sql_update = "INSERT INTO ".TB_PREFIX."sql_patchmanager ( sql_patch_ref , sql_patch , sql_release , sql_statement ) VALUES (:id, :name, :date, :patch)";		
		dbQuery($sql_update, ':id', $id, ':name', $patch['name'], ':date', $patch['date'], ':patch', $patch['patch']);

		if($id == 126) {
			patch126();
		} 

		/*
		 * cusom_fields to new customFields patch - commented out till future
		 */
			/*
		 	elseif($id == 137) {
				convertInitCustomFields();
			}
			*/
		
	}
	return $smarty_row;
}

// ------------------------------------------------------------------------------
function initialise_sql_patch() {
	//SC: MySQL-only function, not porting to PostgreSQL

	#check sql patch 1
	$sql_patch_init = "CREATE TABLE ".TB_PREFIX."sql_patchmanager (sql_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,sql_patch_ref VARCHAR( 50 ) NOT NULL ,sql_patch VARCHAR( 255 ) NOT NULL ,sql_release VARCHAR( 25 ) NOT NULL ,sql_statement TEXT NOT NULL) TYPE = MYISAM ";
	dbQuery($sql_patch_init);

	$log = "Step 2 - The SQL patch table has been created<br />";

	echo $display_block;

	$sql_insert = "INSERT INTO ".TB_PREFIX."sql_patchmanager
 ( sql_id  ,sql_patch_ref , sql_patch , sql_release , sql_statement )
VALUES ('','1','Create ".TB_PREFIX."sql_patchmanger table','20060514', :patch)";
	dbQuery($sql_insert, ':patch', $sql_patch_init);

	$log .= "Step 3 - The SQL patch has been inserted into the SQL patch table<br />";

	return $log;
}

// ------------------------------------------------------------------------------
function patch126() {
	//SC: MySQL-only function, not porting to PostgreSQL
	$sql = "SELECT * FROM ".TB_PREFIX."invoice_items WHERE product_id = 0";
	$sth = dbQuery($sql);

	while($res = $sth->fetch()) {
		$sql = "INSERT INTO ".TB_PREFIX."products (id, description, unit_price, enabled, visible) 
			VALUES (NULL, :description, :gross_total, '0',  '0')";
		dbQuery($sql, ':description', $res[description], ':total', $res[gross_total]);
		$id = lastInsertId();

		$sql = "UPDATE  ".TB_PREFIX."invoice_items SET product_id = :id, unit_price = :price WHERE ".TB_PREFIX."invoice_items.id = :item";

		dbQuery($sql,
			':id', $id[0],
			':price', $res[gross_total],
			':item', $res[id]
			);
	}
}

// ------------------------------------------------------------------------------
function convertInitCustomFields() {
// This function is exactly the same as convertCustomFields() in ./include/customFieldConversion.php but without the print_r and echo output while storing
	/* check if any value set -> keeps all data for sure */
	global $dbh;
    $domain_id = domain_id::get();

	$sql = "SELECT * FROM ".TB_PREFIX."custom_fields WHERE domain_id = :domain_id";
	$sth = $dbh->prepare($sql, ':domain_id', $domain_id);
	$sth->execute();

	while($custom = $sth->fetch()) {
		if(preg_match("/(.+)_cf([1-4])/",$custom['cf_custom_field'],$match)) {

			switch($match[1]) {
				case "biller": $cat = 1;	break;
				case "customer": $cat = 2;	break;
				case "product": $cat = 3;	break;
				case "invoice": $cat = 4;	break;
				default: $case = 0;
			}

			$cf_field = "custom_field".$match[2];
			$sql = "SELECT id, :field FROM :table WHERE domain_id = :domain_id";
			$tablename = TB_PREFIX.$match[1];
			// Only biller table is singular, products, invoices and customers tables are all plural
			if($match[1] != "biller") {
				$tablename .= "s";
			}

			$store = false;

			/*
			 * If custom field name is set
			 */
			if($custom['cf_custom_label'] != NULL) {
				$store = true;
			}

			//error_log($sql);
			$tth = $dbh->prepare($sql);
			$tth->bindValue(':table', $tablename);
			$tth->bindValue(':field', $cf_field);
			$tth->bindValue(':domain_id', $domain_id);
			$tth->execute();

			/*
			 * If any field is set, create custom field
			 */
			while($res = $tth->fetch()) {
				if($res[1] != NULL) {
					$store = true;
					break;
				}
			}

			if($store) {

				//create new text custom field
				saveInitCustomField(3,$cat,$custom['cf_custom_field'],$custom['cf_custom_label']);
				$id = lastInsertId();
				error_log($id);

				$plugin = getPluginById(3);
				$plugin->setFieldId($id);

				//insert all data
				$uth = $dbh->prepare($sql);
				$uth->bindValue(':table', $tablename);
				$uth->bindValue(':field', $cf_field);
				$uth->bindValue(':domain_id', $domain_id);
				$uth->execute();
				while($res2 = $uth->fetch()) {
					$plugin->saveInput($res2[$cf_field], $res2['id']);
				}
			}
		}
	}
}

// ------------------------------------------------------------------------------
function saveInitCustomField($id, $category, $name, $description) {
// This function is exactly same as saveCustomField() in ./include/manageCustomFields.php but without the final echo output
	$sql = "INSERT INTO ".TB_PREFIX."customFields  (pluginId, categorieId, name, description) 
		VALUES (:id, :category, :name, :description)";
	dbQuery($sql, ':id', $id, ':category', $category, ':name', $name, ':description', $description);
//	echo "SAVED<br />";
}

// start of db query functions moved from functions.php on 2013-10-28

/**
* Function: get_custom_field_label
* 
* Prints the name of the custom field based on the input. If the custom field has not been defined by the user than use the default in the lang files
*
* Arguments:
* field		- The custom field in question
**/
function get_custom_field_label($field, $domain_id='')         {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

    $sql =  "SELECT cf_custom_label FROM ".TB_PREFIX."custom_fields WHERE cf_custom_field = :field AND domain_id = :domain_id";
    $sth = dbQuery($sql, ':field', $field, ':domain_id', $domain_id);

    $cf = $sth->fetch();

    //grab the last character of the field variable
    $get_cf_number = $field[strlen($field)-1];    

    //if custom field is blank in db use the one from the LANG files
    if ($cf['cf_custom_label'] == null) {
       	$cf['cf_custom_label'] = $LANG['custom_field'] . $get_cf_number;
    }

    return $cf['cf_custom_label'];
}

function calc_invoice_paid($inv_idField, $domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	#amount paid calc - start
	$x1 = "SELECT COALESCE(SUM(ac_amount), 0) AS amount FROM ".TB_PREFIX."payment WHERE ac_inv_id = :inv_id AND domain_id = :domain_id";
	$sth = dbQuery($x1, ':inv_id', $inv_idField, ':domain_id',$domain_id);
	while ($result_x1Array = $sth->fetch()) {
		$invoice_paid_Field = $result_x1Array['amount'];
		$invoice_paid_Field_format = number_format($result_x1Array['amount'],2);
		#amount paid calc - end
		return $invoice_paid_Field;
	}
}

function calc_customer_total($customer_id, $domain_id='', $isReal=false) {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$real1 = '';
	$real2 = '';
	if ($isReal) {
		$real1 = " LEFT JOIN ".TB_PREFIX."preferences pr ON (pr.pref_id = iv.preference_id AND pr.domain_id = iv.domain_id)";
		$real2 = " AND pr.status = 1";
	}

    $sql ="SELECT
		COALESCE(SUM(ii.total),  0) AS total 
	FROM
		".TB_PREFIX."invoice_items ii INNER JOIN
		".TB_PREFIX."invoices iv ON (iv.id = ii.invoice_id AND iv.domain_id = ii.domain_id)
		$real1
	WHERE  
		iv.customer_id  = :customer
	AND ii.domain_id = :domain_id
	$real2";

    $sth = dbQuery($sql, ':customer', $customer_id, ':domain_id',$domain_id);
	$invoice = $sth->fetch();

	//return number_format($invoice['total'],"#########.##");
	return $invoice['total'];
}

function calc_customer_paid($customer_id, $domain_id='', $isReal=false) {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	$real1 = '';
	$real2 = '';
	if ($isReal) {
		$real1 = " LEFT JOIN ".TB_PREFIX."preferences pr ON (pr.pref_id = iv.preference_id AND pr.domain_id = iv.domain_id)";
		$real2 = " AND pr.status = 1";
	}

#amount paid calc - start
	$sql = "
	SELECT COALESCE(SUM(ap.ac_amount), 0) AS amount 
	FROM
		".TB_PREFIX."payment ap INNER JOIN
		".TB_PREFIX."invoices iv ON (iv.id = ap.ac_inv_id AND iv.domain_id = ap.domain_id)
		$real1
	WHERE 
		iv.customer_id = :customer
	AND ap.domain_id = :domain_id
	$real2";

	$sth = dbQuery($sql, ':customer', $customer_id, ':domain_id',$domain_id);
	$invoice = $sth->fetch();

	return $invoice['amount'];
}

/**
* Function: calc_invoice_tax
* 
* Calculates the total tax for a given invoices
*
* Arguments:
* invoice_id		- The name of the field, ie. Custom Field 1, etc..
**/
function calc_invoice_tax($invoice_id, $domain_id='') {
	global $LANG;
	$domain_id = domain_id::get($domain_id);

	#invoice total tax
	$sql ="SELECT SUM(tax_amount) AS total_tax FROM ".TB_PREFIX."invoice_items WHERE invoice_id = :invoice_id AND domain_id = :domain_id";
	$sth = dbQuery($sql, ':invoice_id', $invoice_id, ':domain_id',$domain_id);

	$tax = $sth->fetch();

	return $tax['total_tax'];
}

/**
* Function: show_custom_field
* 
* If a custom field has been defined then show it in the add,edit, or view invoice screen. This is used for the Invoice Custom Fields - may be used for the others as wll based on the situation
*
* Parameters:
* custom_field		- the db name of the custom field ie invoice_cf1
* custom_field_value	- the value of this custom field for a given invoice
* permission		- the permission level - ie. in a print view its gets a read level, in an edit or add screen its write leve
* css_class_tr		- the css class the the table row (tr)
* css_class1		- the css class of the first td
* css_class2		- the css class of the second td
* td_col_span		- the column span of the right td
* seperator		- used in the print view ie. adding a : between the 2 values
*
* Returns:
* Depending on the permission passed, either a formatted input box and the label of the custom field or a table row and data
**/

function show_custom_field($custom_field,$custom_field_value,$permission,$css_class_tr,$css_class1,$css_class2,$td_col_span,$seperator) {

	$domain_id = domain_id::get();

	# get the last character of the $custom field - used to set the name of the field

	$custom_field_number =  substr($custom_field, -1, 1);

	#get the label for the custom field

	$display_block = "";

	$get_custom_label ="SELECT cf_custom_label FROM ".TB_PREFIX."custom_fields WHERE cf_custom_field = :field AND domain_id = :domain_id";
	$sth = dbQuery($get_custom_label, ':field', $custom_field, ':domain_id', $domain_id);

	while ($Array_cl = $sth->fetch()) {
                $has_custom_label_value = $Array_cl['cf_custom_label'];
	}
	/*if permision is write then coming from a new invoice screen show show only the custom field and have a label
	* if custom_field_value !null coming from existing invoice so show only the cf that they actually have
	*/	
	if ( (($has_custom_label_value != null) AND ( $permission == "write")) OR ($custom_field_value != null)) {

		$custom_label_value = htmlsafe(get_custom_field_label($custom_field));

		if ($permission == "read") {
			$display_block = <<<EOD
			<tr class="$css_class_tr" >
				<th class="$css_class1">
					$custom_label_value$seperator
				</th>
				<td class="$css_class2" colspan="$td_col_span" >
					$custom_field_value
				</td>
			</tr>
EOD;
		}

		else if ($permission == "write") {

		$display_block = <<<EOD
			<tr>
				<th class="$css_class1">$custom_label_value
					<a class="cluetip" href="#"	rel="index.php?module=documentation&amp;view=view&amp;page=help_custom_fields" title="Custom Fields"><img src="./images/common/help-small.png" alt="" /></a>
				</th>
				<td>
					<input type="text" name="customField$custom_field_number" value="$custom_field_value" size="25" />
				</td>
			</tr>
EOD;
		}
	}
	return $display_block;
}

// end of db query functions moved from functions.php on 2013-10-28

