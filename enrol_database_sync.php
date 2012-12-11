<?php // $Id$

    if(!empty($_SERVER['GATEWAY_INTERFACE'])){
        error_log("should not be called from apache!");
        exit;
    }
    error_reporting(E_ALL);

    require_once(dirname(dirname(dirname(__FILE__))).'/config.php'); // global moodle config file.

    require_once($CFG->dirroot . '/course/lib.php');
    require_once($CFG->dirroot . '/lib/blocklib.php');
    require_once($CFG->dirroot . "/enrol/idatabase/enrol.php");

    // ensure errors are well explained
    $CFG->debug=E_ALL;

    if (!is_enabled_enrol('idatabase')) {
         error_log("idatabase enrol plugin not enabled!");
         die;
    }

    // update enrolments -- these handlers should autocreate courses if required
    $enrol = new enrolment_plugin_idatabase();

    $roles = $enrol->get_available_roles();

    foreach ($roles as $role) {
        $enrol->sync_enrolments($role);
    }

    // sync metacourses
    if (function_exists('sync_metacourses')) {
        sync_metacourses();
    }