<?php

require_once 'crs.civix.php';

define('CRS_REGION_NONE', 0);       // leave blank/NULL
define('CRS_REGION_SELECTED', 1);   // use the selected region
define('CRS_REGION_USER', 2);       // let the user choose
define('CRS_REGION_CHAPTER', 3);    // use the region of the selected chapter
define('CRS_REGION_POSTAL', 4);     // lookup the region using postal code

define('CRS_CHAPTER_NONE', 0);      // leave blank/NULL
define('CRS_CHAPTER_SELECTED', 1);  // use the selected chapter
define('CRS_CHAPTER_USER', 2);      // let the user choose

define('CRS_DEFAULT_REGION_ID', 404);   // i.e. No Region
define('CRS_DEFAULT_CHAPTER_ID', 204);  // i.e. Unassigned
define('CRS_CHAPTER_GROUP_ID', 282);

/*
  The following helper functions facilitate the conversion of the current
  alphanumeric custom fields into contact references, and back.
*/
function crs_contact_to_name($id, $default = '') {
    $name = $default;
    
    if (!is_null($id)) {
      $api = new civicrm_api3();
      $api->Contact->GetSingle(array('id' => $id, 'return' => 'organization_name,nick_name'));
      if (empty($api->result->is_error)) {
        $name = $api->result->organization_name;
        if (!empty($api->result->nick_name))
          $name .= " ({$api->result->nick_name})";
      }
    }
    return $name;
}
function crs_chapter_name_to_contact($name) {

  $name = trim($name);
  if ($i = strpos($name, '(')) {
    $name = substr($name, 0, $i);
  }

  try {
    $result = civicrm_api3('Contact', 'get', array(
      //'filter.group_id' => array(
      //  '0' => CRS_CHAPTER_GROUP_ID,
      //),
      'options' => array(
        'limit' => 0,
      ),
      'sequential' => 1,
      'organization_name' => $name,
      'return' => 'id',
    ));
    if ($result['count'] > 0) {
      return $result['values'][0]['id'];
    }
  }
  catch (Exception $e) {}

  return NULL;
}
function crs_region_name_to_contact($name) {
  if (!empty($name) && !is_numeric($name)) {

    $api = new civicrm_api3();
    $api->Contact->GetValue(array('contact_sub_type' => 'Region',
                  'organization_name' => trim($name), 'return' => 'id'));
    $name = is_string($api->result) ? $api->result : NULL;
  }
  return $name;
}

/*
  This is a dummy form class that will get passed to the
  civitracker moudule in order to pre-populate the table
  contribution_page_revenue_sharing this extension creates.
*/
class fake_civitracker_form {
  private $_id;

  function __construct($contribution_page_id = 0) {
    $this->_id = $contribution_page_id;
  }

  function getVar() {
    return $this->_id;
  }

  function setDefaults() {}

  function assign() {}

  function setID($contribution_page_id) {
    $this->_id = $contribution_page_id;
  }

  // this function simulates a call to the civitracker module and
  // returns the region and chapter assigned to the $_GET variables
  function civitracker() {

    civitracker_civicrm_buildForm('CRM_Contribute_Form_Contribution_Main', $this);

    $result = array(
        'region_76' => $_GET['custom_76'],
        'chapter_77' => $_GET['custom_77']
    );

    unset($_GET['custom_76'], $_GET['custom_77'], $_GET['custom_79']);

    return $result;
  }
}

function crs_assign_region_and_chapter($settings, $contributionId) {
  $session = CRM_Core_Session::singleton();
  $api = new civicrm_api3();

  $chapter_contact_id = FALSE;
  $region_contact_id = NULL;

  // assign region
  switch ($settings['region_mode']) {

    case CRS_REGION_NONE:
    case CRS_REGION_USER:
      $region_contact_id = $session->get('region_contact_id', 'crs');
      break;

    case CRS_REGION_SELECTED:
      $region_contact_id = $settings['region_contact_id'];
      break;

    case CRS_REGION_CHAPTER:
      // use the region of the selected chapter
      if ($api->Contact->GetValue(array('id' => $settings['chapter_contact_id'], 'return' => 'custom_241'))) {
        $region_contact_id = $api->result;
      }
      break;

    case CRS_REGION_POSTAL:
      $query = "SELECT region_contact_id FROM civicrm_regionfields_data WHERE postal_code=%1";
      // use billing postal code first
      if ($billing = $session->get('postal_code_billing', 'crs')) {
        $region_contact_id = CRM_Core_DAO::singleValueQuery($query, array(1 => array($billing, 'String'))) ?: NULL;
      }
      // fall back to primary if not found
      if (!$region_contact_id && $primary = $session->get('postal_code_primary', 'crs')) {
        $region_contact_id = CRM_Core_DAO::singleValueQuery($query, array(1 => array($primary, 'String'))) ?: NULL;
      }
      // or default region if postal code is wonky (international are dying here)
      if (!$region_contact_id) {
        $region_contact_id = CRS_DEFAULT_REGION_ID;
      }
      break;
  }
  $api->CustomValue->Create(array('entity_id' => $contributionId, 'custom_277' => $region_contact_id));

  if (CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_custom_field WHERE id=76 AND is_active=1')) {
    try {
      $region_name = civicrm_api3('Contact', 'getvalue', array(
        'id' => $region_contact_id,
        'return' => 'display_name',
      ));
    }
    catch (CiviCRM_API3_Exception $e) {
    }
  }

  // assign chapter
  switch ($settings['chapter_mode']) {
    
    case CRS_CHAPTER_NONE:
    case CRS_CHAPTER_USER:
      $chapter_contact_id = $session->get('chapter_contact_id', 'crs');
      break;

    case CRS_CHAPTER_SELECTED:
      $chapter_contact_id = $settings['chapter_contact_id'];
      break;

  }
  $api->CustomValue->Create(array('entity_id' => $contributionId, 'custom_278' => $chapter_contact_id));

  if (CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_custom_field WHERE id=77 AND is_active=1')) {
    try {
      $affiliations = civicrm_api3('Contact', 'getvalue', array(
        'id' => $chapter_contact_id,
        'return' => 'custom_80',
      ));
    }
    catch (CiviCRM_API3_Exception $e) {
    }
  }

  if (isset($region_name) && isset($affiliations)) {
    register_shutdown_function(
      function($id, $region, $chapter) {
        try {
          civicrm_api3('CustomValue', 'create', array(
            'entity_id' => $id,
            'custom_76' => $region,
            'custom_77' => $chapter,
          ));
        }
        catch (CiviCRM_API3_Exception $e) {
        }
      },
      $contributionId,
      !empty($region_name) ? $region_name : 'No Region',
      $chapter_contact_id == CRS_DEFAULT_CHAPTER_ID ? 'Unassigned' : (!empty($affiliations) ? array_shift($affiliations) : 'Unassigned')
    );
  }
  
  if (!isset($_SERVER['SERVER_ADDR']) || ($_SERVER['SERVER_ADDR'] != '127.0.0.1')) {
    $session->resetScope('crs');
  }

  return $chapter_contact_id;
}

// make sure the contact has the chapter in it's chapter affiliations (custom_80) and
// optionally set primary chapter (custom_240)
function crs_update_contact_chapter_fields($contact_id, $chapter_contact_id, $set_primary = TRUE) {

  if (!$contact_id || !$chapter_contact_id) {
    return;
  }

  $api = new civicrm_api3();
  $params = array();

  if ($set_primary) {
    $params['custom_240'] = $chapter_contact_id;
  }

  if ($chapter_contact_id != CRS_DEFAULT_CHAPTER_ID) {
    // get the chapter affiliation value from the chapter contact record
    $api->Contact->GetSingle(array('id' => $chapter_contact_id, 'return' => 'custom_80'));
    if ($api->result->is_error) {
      return;
    }
    $chapter_name = is_array($api->result->custom_80) ? array_shift($api->result->custom_80) : $api->result->custom_80;

    if ($chapter_name) {
      $chapter_80 = array();

      // get the contributor's chapter affiliations
      $api->Contact->GetSingle(array('id' => $contact_id, 'return' => 'custom_80'));
      if ($api->result->is_error) {
        return;
      }
      $chapters = is_array($api->result->custom_80) ? $api->result->custom_80: (array) $api->result->custom_80;

      foreach($chapters as $chapter) {
        if (strpos($chapter, '()') === FALSE) {  // take out bogus name com.imba.cambr was adding for a while
          $chapter_80[] = $chapter;
        }
      }
      // add the chapter if it's not already in the list
      if (array_search($chapter_name, $chapter_80) === FALSE) {
        $chapter_80[] = $chapter_name;
      }
      $params['custom_80'] = $chapter_80;
    }
  }
  if (!empty($params)) {
    $params['id'] = $contact_id;
    $api->Contact->Create($params);
  }
}

function crs_civicrm_buildForm($formName, &$form) {

  $session = CRM_Core_Session::singleton();

  switch ($formName) {

    case 'CRM_Event_Form_Registration_Register':

      $settings = $session->get('settings', 'crs');

      if (!$settings || ($settings['event_id'] != $form->id)) {

        $session->resetScope('crs');

        $dao = new CRM_Crs_DAO_RevenueSharingEvent();
        $dao->event_id = $form->_id;
        $dao->find(TRUE);

        $settings = array();

        if ($dao->id) {
          CRM_Core_DAO::storeValues($dao, $settings);
          $session->set('settings', $settings, 'crs');
        }
      }
      $is_event = TRUE;

      // fall through to next case

    case 'CRM_Contribute_Form_Contribution_Main':

      if (empty($is_event)) {
        $settings = $session->get('settings', 'crs');

        if (!$settings || ($settings['contribution_page_id'] != $form->_id)) {

          $session->resetScope('crs');

          $ip = $_SERVER['REMOTE_ADDR'];

          // IMP-347 single dynamic contribution page
          if ($chapter = $form->get('chapter')) {
            $settings = array(
              'contribution_page_id' => $form->_id,
              'region_mode' => CRS_REGION_SELECTED,
              'chapter_mode' => CRS_CHAPTER_SELECTED,
              'region_contact_id' => $chapter['custom_241'],
              'chapter_contact_id' => $chapter['id'],
            );
          }
          else {
            $dao = new CRM_Crs_DAO_RevenueSharing();
            $dao->contribution_page_id = $form->_id;
            $dao->find(TRUE);

            $settings = array();

            if ($dao->id) {
              CRM_Core_DAO::storeValues($dao, $settings);
            }
          }
          $session->set('settings', $settings, 'crs');
        }
      }

      if (!empty($settings)) {

        if ($settings['region_mode'] == CRS_REGION_USER) {

          $options = array();
          $dao = CRM_Core_DAO::executeQuery("SELECT id,organization_name FROM civicrm_contact WHERE contact_sub_type='Region' ORDER BY organization_name ASC");
          while ($dao->fetch()) {
            $options[$dao->id] = $dao->organization_name;
          }
          $form->addSelect('region_contact_id', array('label' => 'Region', 'options' => $options), TRUE);
          $form->setDefaults(array('region_contact_id' => CRS_DEFAULT_REGION_ID));

          // build zipcode to region lookup table
          $table = array();
          
          $dao = CRM_Core_DAO::executeQuery('SELECT postal_code, region_contact_id FROM civicrm_regionfields_data');
          while ($dao->fetch()) {
            if (empty($table[$dao->region_contact_id])) {
              $table[$dao->region_contact_id] = array(
                'id' => $dao->region_contact_id,
                'codes' => array()
              );
            }
            $table[$dao->region_contact_id]['codes'][] = $dao->postal_code;
          }
          $form->assign('lookup_table', json_encode(array_values($table)));
          $form->assign('default_region', CRS_DEFAULT_REGION_ID);

          $form->setDefaults(array('region_contact_id' => CRS_DEFAULT_REGION_ID));
        }

        // region can be passed in the URL for mode "leave blank / NULL"
        elseif ($settings['region_mode'] == CRS_REGION_NONE) {

          if ($id = CRM_Utils_Array::value('region', $_GET)) {
            // NOP
          }
          elseif ($name = CRM_Utils_Array::value('custom_76', $_GET)) {
            $id = crs_region_name_to_contact($name);
          }
          if (!empty($id)) {
            $form->add('hidden', 'region_contact_id', $id);
          }
        }

        // potentially get chapter from the URL
        if ($id = CRM_Utils_Array::value('chapter', $_GET)) {
          // NOP
        }
        elseif ($name = CRM_Utils_Array::value('custom_77', $_GET)) {
          $id = crs_chapter_name_to_contact($name);
        }

        if ($settings['chapter_mode'] == CRS_CHAPTER_USER) {
          $options = array();
          try {
            $result = civicrm_api3('Contact', 'get', array(
              'filter.group_id' => array(
                '0' => CRS_CHAPTER_GROUP_ID,
              ),
              'options' => array(
                'limit' => 0,
              ),
              'return' => 'id, organization_name, nick_name',
            ));
            foreach($result['values'] as $chapter) {
              $name = $chapter['organization_name'];
              if (!empty($chapter['nick_name'])) {
                $name .= " ({$chapter['nick_name']})";
              }
              $options[$chapter['id']] = $name;
            }
          }
          catch (Exception $e) {}
          
          $form->addSelect('chapter_contact_id', array('label' => 'Chapter', 'options' => $options), TRUE);
          $form->setDefaults(array('chapter_contact_id' => !empty($id) ? $id : CRS_DEFAULT_CHAPTER_ID));
        }
    
        // chapter can be passed in the URL for mode "leave blank / NULL"
        elseif ($settings['chapter_mode'] == CRS_CHAPTER_NONE) {

          if (!empty($id)) {
            $form->add('hidden', 'chapter_contact_id', $id);
          }
        }
      }

      break;

    case 'CRM_Contribute_Form_Contribution_Confirm':
    case 'CRM_Contribute_Form_Contribution_ThankYou':
    case 'CRM_Event_Form_Registration_Confirm':
    case 'CRM_Event_Form_Registration_ThankYou':

      $settings = $session->get('settings', 'crs');

      if (($settings['region_mode'] == CRS_REGION_USER) && ($id = $session->get('region_contact_id', 'crs'))) {
        $form->add('text', 'region_contact_id', 'Region');
        $form->setDefaults(array('region_contact_id' => crs_contact_to_name($id)));
      }
      if ($id = $session->get('chapter_contact_id', 'crs')) {
        $form->add('text', 'chapter_contact_id', 'Chapter');
        $form->setDefaults(array('chapter_contact_id' => crs_contact_to_name($id)));
      }
      break;

    default:
      $session->resetScope('crs');
      break;
  }

}

function crs_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {

  switch ($formName) {

    case 'CRM_Event_Form_Registration_Register':
      $is_event = TRUE;

    case 'CRM_Contribute_Form_Contribution_Main':

      $session = CRM_Core_Session::singleton();
      if ($settings = $session->get('settings', 'crs')) {
        $session->set('region_contact_id', CRM_Utils_Array::value('region_contact_id', $fields), 'crs');
        $session->set('chapter_contact_id', CRM_Utils_Array::value('chapter_contact_id', $fields), 'crs');

        if ($settings['region_mode'] == CRS_REGION_POSTAL) {
          // fields postal_code and billing_postal_code get a hyphenated suffix added
          // I don't know where that comes from, so search through the keys to find them
          $primary = $billing = FALSE;
          foreach($fields as $k => $v) {
            if (!$primary && strpos($k, 'postal_code') === 0) {
              $primary = $v;
              $session->set('postal_code_primary', $v, 'crs');
            }
            if (!$billing && strpos($k, 'billing_postal_code') === 0) {
              $billing = $v;
              $session->set('postal_code_billing', $v, 'crs');
            }
          }
        }
    }
  }

  return TRUE;
}
/**
 * Implements hook_civicrm_post().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_post
 */
function crs_civicrm_post($op, $objectName, $objectId, &$objectRef) {

  if ($op != 'create') {
    return;
  }

  $session = CRM_Core_Session::singleton();
  $settings = $session->get('settings', 'crs');
  // we only play with contributions made for pages/events with CRS settings
  if (!$settings) {
    return;
  }

  if ($objectName == 'Contribution') {

    if (!empty($settings['contribution_page_id']) && ($settings['contribution_page_id'] == $objectRef->contribution_page_id)) {

      $chapter_contact_id = crs_assign_region_and_chapter($settings, $objectId);

      // SET PRIMARY_CHAPTER_240 AND UPDATE CHAPTER_80 IF NEEDED, FOR THE CONTRIBUTOR

      // if the contribution chapter was not set above, see if it's already assigned
      if (($chapter_contact_id === FALSE) && !empty($objectRef->custom_278)) {
        $chapter_contact_id = $objectRef->custom_278;
      }

      // if we still dont have a chapter, we're done here
      if (!$chapter_contact_id) {
        return;
      }

      crs_update_contact_chapter_fields($objectRef->contact_id, $chapter_contact_id);
    }
  }
  elseif ($objectName == 'ParticipantPayment') {

    $dao = CRM_Core_DAO::executeQuery('SELECT contact_id, event_id FROM civicrm_participant WHERE id=%1',
      array(1 => array($objectRef->participant_id, 'Integer')));

    if ($dao->fetch() && ($settings['event_id'] == $dao->event_id)) {
      $chapter_contact_id = crs_assign_region_and_chapter($settings, $objectRef->contribution_id);
      crs_update_contact_chapter_fields($dao->contact_id, $chapter_contact_id, FALSE);
    }
  }
}

function crs_civicrm_tabset($tabsetName, &$tabs, $context) {
  if ($tabsetName == 'civicrm/event/manage') {
    if (!empty($context)) {
      $eventID = $context['event_id'];
      $url = CRM_Utils_System::url( 'civicrm/event/manage/revenue',
        "reset=1&snippet=5&force=1&id=$eventID&action=update&component=event" );
      //add a new Revenue Sharing tab along with url
      $tab['revenue'] = array(
        'title' => ts('Revenue Sharing'),
        'link' => $url,
        'valid' => 1,
        'active' => 1,
        'current' => TRUE,
      );
    }
    else {
      $tab['revenue'] = array(
        'title' => ts('Revenue Sharing'),
        'url' => 'civicrm/event/manage/revenue',
      );
    }
    $tabs += $tab;
  }
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function crs_civicrm_config(&$config) {
  _crs_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param $files array(string)
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function crs_civicrm_xmlMenu(&$files) {
  _crs_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function crs_civicrm_install() {

  if (!function_exists('civitracker_civicrm_buildForm')) {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/' . drupal_get_path('module', 'civitracker') . '/civitracker.module');
  }

  $dummy = new fake_civitracker_form();

  CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS `contribution_page_revenue_sharing` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `contribution_page_id` int(10) unsigned NOT NULL,
    `region_mode` tinyint(3) unsigned NOT NULL DEFAULT '0',
    `chapter_mode` tinyint(3) unsigned NOT NULL DEFAULT '0',
    `region_contact_id` int(10) unsigned DEFAULT NULL,
    `chapter_contact_id` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `contribution_page_id` (`contribution_page_id`),
    KEY `region_xxx` (`region_contact_id`),
    KEY `chapter_yyy` (`chapter_contact_id`),
    CONSTRAINT `civicrm_contribution_page_revenue_sharing_ibfk_1` FOREIGN KEY (`region_contact_id`) REFERENCES `civicrm_contact` (`id`) ON DELETE SET NULL,
    CONSTRAINT `civicrm_contribution_page_revenue_sharing_ibfk_2` FOREIGN KEY (`chapter_contact_id`) REFERENCES `civicrm_contact` (`id`) ON DELETE SET NULL,
    CONSTRAINT `civicrm_contribution_page_revenue_sharing_ibfk_3` FOREIGN KEY (`contribution_page_id`) REFERENCES `civicrm_contribution_page` (`id`) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

  CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS `event_revenue_sharing` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `event_id` int(10) unsigned NOT NULL,
    `region_mode` tinyint(3) unsigned NOT NULL DEFAULT '0',
    `chapter_mode` tinyint(3) unsigned NOT NULL DEFAULT '0',
    `region_contact_id` int(10) unsigned DEFAULT NULL,
    `chapter_contact_id` int(10) unsigned DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `event_id` (`event_id`),
    KEY `region_xxx` (`region_contact_id`),
    KEY `chapter_yyy` (`chapter_contact_id`),
    CONSTRAINT `civicrm_event_revenue_sharing_ibfk_1` FOREIGN KEY (`region_contact_id`) REFERENCES `civicrm_contact` (`id`) ON DELETE SET NULL,
    CONSTRAINT `civicrm_event_revenue_sharing_ibfk_2` FOREIGN KEY (`chapter_contact_id`) REFERENCES `civicrm_contact` (`id`) ON DELETE SET NULL,
    CONSTRAINT `civicrm_event_revenue_sharing_ibfk_3` FOREIGN KEY (`event_id`) REFERENCES `civicrm_event` (`id`) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

  $chapters = array(
    'At Large' => 204,
    'Unassigned' => 204,
    'CSRA SORBA' => 210555,
    'GROC' => 226875,
    'Rockford Area Mountain Biking Alliance (RAMBA)' => 411577,
  );

  try {
    $result = civicrm_api3('Contact', 'get', array(
      'filter.group_id' => array(
        '0' => CRS_CHAPTER_GROUP_ID,
      ),
      'options' => array(
        'limit' => 0,
      ),
      'return' => 'id, organization_name, nick_name',
    ));
    foreach($result['values'] as $chapter) {
      $name = $chapter['organization_name'];
      if (!empty($chapter['nick_name'])) {
        $name .= " ({$chapter['nick_name']})";
      }
      $chapters[$name] = $chapter['id'];
    }
  }
  catch (CiviCRM_API3_Exception $e) {
    watchdog('crs', $e->getMessage());
  }

  $regions = array();
  $dao = CRM_Core_DAO::executeQuery("SELECT id,organization_name FROM civicrm_contact WHERE contact_sub_type='Region' ORDER BY organization_name ASC");
  while ($dao->fetch()){
    $name = $dao->organization_name;
    $regions[$name] = $dao->id;
  }

  $query = 'INSERT IGNORE INTO contribution_page_revenue_sharing
            (contribution_page_id,region_mode,chapter_mode,region_contact_id,chapter_contact_id)
            VALUES ';

  $dao = CRM_Core_DAO::executeQuery('SELECT id as contribution_page_id FROM civicrm_contribution_page');
  while ($dao->fetch()) {
    $id = $dao->contribution_page_id;
    $dummy->setID($id);
    $names = $dummy->civitracker();
    $region = !empty($regions[$names['region_76']]) ? $regions[$names['region_76']] : 'NULL';
    $rm = ($region != 'NULL') ? CRS_REGION_SELECTED : CRS_REGION_POSTAL;
    $chapter = !empty($chapters[$names['chapter_77']]) ? $chapters[$names['chapter_77']] : 'NULL';
    $cm = ($chapter != 'NULL') ? CRS_CHAPTER_SELECTED : CRS_CHAPTER_NONE;
    $query .= "($id,$rm,$cm,$region,$chapter),";
  }
  CRM_Core_DAO::executeQuery(substr($query, 0, -1));

  // set new revenue sharing fields on contributions
  $regions['Canada'] = CRS_DEFAULT_REGION_ID;
  $regions['None'] = CRS_DEFAULT_REGION_ID;
  $regions['Northeast'] = 271643; // Atlantic
  $regions['Pacific Northwest'] = 271652; // Pacific Northwest
  $regions['Pacific'] = 271652; // Pacific Northwest
  $regions['SORBA'] = 271654; // Southeast

  foreach($regions as $name => $id) {
    $name = CRM_Core_DAO::escapeString($name);
    CRM_Core_DAO::executeQuery("UPDATE civicrm_value_revenue_sharing_11 SET contribution_region_277='$id' WHERE region_76='$name' AND contribution_region_277 IS NULL");
  }
  CRM_Core_DAO::executeQuery("UPDATE civicrm_value_revenue_sharing_11 SET contribution_region_277='" . CRS_DEFAULT_REGION_ID . "' WHERE (region_76='' OR region_76 IS NULL) AND contribution_region_277 IS NULL");

  foreach($chapters as $name => $id) {
    $name = CRM_Core_DAO::escapeString($name);
    CRM_Core_DAO::executeQuery("UPDATE civicrm_value_revenue_sharing_11 SET contribution_chapter_278='$id' WHERE chapter_77='$name' AND contribution_chapter_278 IS NULL");
  }
  CRM_Core_DAO::executeQuery("UPDATE civicrm_value_revenue_sharing_11 SET contribution_chapter_278='" . CRS_DEFAULT_CHAPTER_ID . "' WHERE (chapter_77='' OR chapter_77 IS NULL) AND contribution_chapter_278 IS NULL");

  _crs_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function crs_civicrm_uninstall() {
  _crs_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function crs_civicrm_enable() {
  _crs_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function crs_civicrm_disable() {
  _crs_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed
 *   Based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function crs_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _crs_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function crs_civicrm_managed(&$entities) {
  _crs_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function crs_civicrm_caseTypes(&$caseTypes) {
  _crs_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function crs_civicrm_angularModules(&$angularModules) {
_crs_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function crs_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _crs_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Functions below this ship commented out. Uncomment as required.
 *

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function crs_civicrm_preProcess($formName, &$form) {

}

*/
