<?php  // $Id$

require_once($CFG->dirroot.'/enrol/enrol.class.php');

class enrolment_plugin_idatabase {

    var $log;

    /*
     * For the given user, let's go out and look in an external database
     * for an authoritative list of enrolments, and then adjust the
     * local Moodle assignments to match.
     */
    function setup_enrolments(&$user) {
        global $CFG;

        // check whether setup enrolments is disabled
        if ($CFG->enrol_idb_disablelogincheck) {
            return;
        }

        // NOTE: if $this->enrol_connect() succeeds you MUST remember to call
        // $this->enrol_disconnect() as it is doing some nasty vodoo with $CFG->prefix
        $enroldb = $this->enrol_connect();
        if (!$enroldb) {
            error_log('[ENROL_IDB] Could not make a connection');
            return;
        }

        // sets up available courses for this user.
        $this->create_courses($enroldb, $user);

        $roles = $this->get_available_roles();

        //error_log('[ENROL_IDB] found ' . count($roles) . ' roles:');

        $courses = array(); //courses cache for this user
        $fullnamefield = (empty($CFG->enrol_idb_remotecoursefullnamefield))?
            $CFG->enrol_idb_remotecoursefield:
            $CFG->enrol_idb_remotecoursefullnamefield;
        $shortnamefield = (empty($CFG->enrol_idb_remotecourseshortnamefield))?
            $fullnamefield:
            $CFG->enrol_idb_remotecourseshortnamefield;

        // This is the rest of the select string for querying courses.
        $selectstring = ", $fullnamefield as enrolremotefullnamecoursefield,
                         $shortnamefield as enrolremoteshortnamecoursefield";
        if (!empty($CFG->enrol_idb_remotecoursecategoryfield)) {
            $selectstring .= ", {$CFG->enrol_idb_remotecoursecategoryfield} as remotecoursecategoryfield";
            if (!empty($CFG->enrol_idb_remotecoursesubcategoryfield)) {
                $selectstring .= ", {$CFG->enrol_idb_remotecoursesubcategoryfield} as remotecoursesubcategoryfield";
            }
        }

        foreach($roles as $role) {

            //error_log('[ENROL_IDB] setting up enrolments for '.$role->shortname);

            /// Get the authoritative list of enrolments from the external database table
            /// We're using the ADOdb functions natively here and not our datalib functions
            /// because we didn't want to mess with the $db global

            $useridfield = $enroldb->quote($user->{$CFG->enrol_idb_localuserfield});

            list($have_role, $remote_role_name, $remote_role_value) = $this->role_fields($enroldb, $role);

            /// Check if a particular role has been forced by the plugin site-wide
            /// (if we aren't doing a role-based select)
            if (!$have_role && $CFG->enrol_idb_defaultcourseroleid) {
                $role = get_record('role', 'id', $CFG->enrol_idb_defaultcourseroleid);
            }

            /// Whether to fetch the default role on a per-course basis (below) or not.
            $use_default_role = !$role;

            /*
            if ($have_role) {
                error_log('[ENROL_IDB] Doing role-specific select from db for role: '.$role->shortname);
            } elseif ($use_default_role) {
                error_log('[ENROL_IDB] Using course default for roles - assuming that database lists defaults');
            } else {
                error_log('[ENROL_IDB] Using config default for roles: '.$role->shortname);
            }*/


            if ($rs = $enroldb->Execute("SELECT {$CFG->enrol_idb_remoteenrolcoursefield} as enrolremotecoursefield
                                           FROM {$CFG->enrol_idb_enroltable}
                                          WHERE {$CFG->enrol_idb_remoteuserfield} = " . $useridfield .
                                            (isset($remote_role_name, $remote_role_value) ? ' AND '.$remote_role_name.' = '.$remote_role_value : ''))) {

                // We'll use this to see what to add and remove
                $existing = $role
                    ? get_records_sql("
                        SELECT * FROM {$CFG->prefix}role_assignments
                        WHERE userid = {$user->id}
                         AND roleid = {$role->id}")
                    : get_records('role_assignments', 'userid', $user->id);

                if (!$existing) {
                    $existing = array();
                }

                if (!$rs->EOF) {   // We found some courses

                    //$count = 0;
                    $courselist = array();
                    while ($fields_obj = rs_fetch_next_record($rs)) {         // Make a nice little array of courses to process
                        $fields_obj = (object)array_change_key_case((array)$fields_obj , CASE_LOWER);
                        $courselist[] = $fields_obj->enrolremotecoursefield;
                        //$count++;
                    }
                    rs_close($rs);

                    $missingcourses = array();
                    foreach ($courselist as $coursefield) {
                        if (empty($courses[$coursefield])) {
                            $missingcourses[] = $coursefield;
                        }
                    }

                    if ($rs = $enroldb->Execute("SELECT {$CFG->enrol_idb_remotecoursefield} as enrolremotecoursefield
                              $selectstring
                              FROM {$CFG->enrol_idb_coursetable}
                              WHERE {$CFG->enrol_idb_remotecoursefield} IN ('" . implode("','", $missingcourses) . "')")) {

                        //all courses for this user are loaded
                        if (!$rs->EOF) {
                            while ($c = rs_fetch_next_record($rs)) {
                                $courses[$c->enrolremotecoursefield] = $c;
                            }
                        }
                    }

                    //error_log('[ENROL_IDB] Found '.count($existing).' existing roles and '.$count.' in external database');

                    foreach ($courselist as $coursefield) {   /// Check the list of courses against existing
                        $course = get_record('course', $CFG->enrol_idb_localcoursefield, addslashes($coursefield));
                        if (!is_object($course)) {
                            if (empty($CFG->enrol_idb_courseautocreate)) { // autocreation not allowed
                                if (debugging('',DEBUG_ALL)) {
                                    error_log( "Course $coursefield does not exist, skipping") ;
                                }
                                continue; // next foreach course
                            }
                            // ok, now then let's create it!
                            // prepare any course properties we actually have
                            $course = new StdClass;
                            $course->{$CFG->enrol_idb_localcoursefield} = $coursefield;
                            $course->fullname  = $courses[$coursefield]->enrolremotefullnamecoursefield;
                            $course->shortname = $courses[$coursefield]->enrolremoteshortnamecoursefield;
                            if (!($newcourseid = $this->create_course($course, $courses[$coursefield], true)
                                and $course = get_record( 'course', 'id', $newcourseid))) {
                                error_log( "Creating course $coursefield failed");
                                continue; // nothing left to do...
                            }
                        }

                        // if the course is hidden and we don't want to enrol in hidden courses
                        // then just skip it
                        if (!$course->visible and $CFG->enrol_idb_ignorehiddencourse) {
                            continue;
                        }

                        /// If there's no role specified, we get the default course role (usually student)
                        if ($use_default_role) {
                            $role = get_default_course_role($course);
                        }

                        $context = get_context_instance(CONTEXT_COURSE, $course->id);

                        // Couldn't get a role or context, skip.
                        if (!$role || !$context) {
                            continue;
                        }

                        // Search the role assignments to see if this user
                        // already has this role in this context.  If it is, we
                        // skip to the next course.
                        foreach($existing as $key => $role_assignment) {
                            if ($role_assignment->roleid == $role->id
                                && $role_assignment->contextid == $context->id) {
                                unset($existing[$key]);
                                //error_log('[ENROL_IDB] User is already enroled in course '.$course->idnumber);
                                continue 2;
                            }
                        }

                        //error_log('[ENROL_IDB] Enrolling user in course '.$course->idnumber);
                        role_assign($role->id, $user->id, 0, $context->id, 0, 0, 0, 'idatabase');
                    }
                } // We've processed all external courses found
                unset($courses); //free mem

                /// We have some courses left that we might need to unenrol from
                /// Note: we only process enrolments that we (ie 'idatabase' plugin) made
                /// Do not unenrol anybody if the disableunenrol option is 'yes'
                if (!$CFG->enrol_idb_disableunenrol) {
                    foreach ($existing as $role_assignment) {
                        if ($role_assignment->enrol == 'idatabase') {
                            //error_log('[ENROL_IDB] Removing user from context '.$role_assignment->contextid);
                            role_unassign($role_assignment->roleid, $user->id, '', $role_assignment->contextid);
                        }
                    }
                }
            } else {
                error_log('[ENROL_IDB] Couldn\'t get rows from external db: '.$enroldb->ErrorMsg());
            }
        }
        $this->enrol_disconnect($enroldb);
    }

    /**
     * Gets all the allowed roles to enrol.
     * @global object $CFG
     * @return array array of roles that will be enrolled.
     */
    function get_available_roles() {
        global $CFG;

        // If we are expecting to get role information from our remote db, then
        // we execute the below code for every role type.  Otherwise we just
        // execute it once with null (hence the dummy array).
        if (empty($CFG->enrol_idb_allowedroles) || $CFG->enrol_idb_allowedroles == 'all') {
            $availroles = get_records('role');
        } else {
            $roleids = '\''.str_replace(',', '\',\'', $CFG->enrol_idb_allowedroles).'\'';
            $availroles = get_records_select('role', 'id IN ('.$roleids.')');
        }

        $roles = !empty($CFG->enrol_idb_remoterolefield) && !empty($CFG->enrol_idb_localrolefield)
            ? $availroles
            : array(null);

        return $roles;
    }

    /**
     * Control all actions to perform in a sole method within the plugin.
     *
     * By now:
     * 1. Creates all courses from external database.
     * 2. Enrol users to those courses.
     * 3. Enrolment roles are only those allowed.
     *
     */
    function sync_all_enrolments() {

        // get all controlled roles to sync.
        $roles = $this->get_available_roles();

        echo " Allowed roles: ".print_r($roles, true)."\n";

        // sync enrolments for all controlled roles.
        foreach ($roles as $role) {
            echo "=== Syncing for role {$role->shortname} ===\n";
            $this->sync_enrolments($role);
        }

        // sync metacourses.
        if (function_exists('sync_metacourses')) {
            echo "=== Syncing metacourses ===\n";
            sync_metacourses();
        }

     }

    /**
     * Steps performed:
     *
     * 1. Create courses from data of external database table.
     * 2. Enrol users to existing courses.
     *
     * @param object The role to sync for. If no role is specified, defaults are
     * used.
     */
    private function sync_enrolments($role = null) {

        error_reporting(E_ALL);

        // Connect to the external database
        $enroldb = $this->enrol_connect();
        if (!$enroldb) {
            notify("enrol/idatabase cannot connect to server");
            return false;
        }

        // creates courses using external database data.
        $this->create_courses($enroldb, false, true); //true for echoing
        // enrol users to courses using external database data.
        $this->enrol_users($enroldb, $role);

    }

    /**
     * Creates all courses from external database.
     * @param object $enroldb database connection.
     * @param object $user user logging in.
     * @param bool $echoing enables echo for tracing action, specially for cron script.
     * @return bool|array external course ids related to external database courses.
     * false if there exists no courses.
     */
    function create_courses(&$enroldb, $user = false, $echoing = false) {
        global $CFG;
        global $db;

        // check for all courses in external database.
        $coursefields = $this->get_course_fields(); //at least one field
        $strcoursefields = " ct.".implode(", ct.", $coursefields);

        if (!$user) {

            if ($echoing) {
                echo "=== Checking and generating courses ===\n";
            }

            // get all courses
            $sql =  "SELECT DISTINCT  $strcoursefields".
                " FROM {$CFG->enrol_idb_coursetable} ct";

            $rs = $enroldb->Execute($sql);

        } else {
            // check for courses for a specific user.
            $useridfield = $user->{$CFG->enrol_idb_localuserfield};
            $useridfieldvalue = get_field('user', $useridfield, 'id', $user->id);

            if (empty($useridfieldvalue)) {
                if ($echoing) {
                    echo "Not found external id for local user id {$user->id}.\n";
                }
                return false;
            }

            if ($echoing) {
                echo '=== Checking and generating courses for local user id '.$user->id." ===\n";
            }

            // get user's courses
            $sql =  "SELECT DISTINCT $strcoursefields" .
                " FROM {$CFG->enrol_idb_enroltable} et" .
                " JOIN {$CFG->enrol_idb_coursetable} ct ON" .
                "   et.{$CFG->enrol_idb_remoteenrolcoursefield} = ec.{$CFG->enrol_idb_remotecoursefield}" .
                " WHERE {$CFG->enrol_idb_remoteuserfield} = ". $enroldb->quote($useridfieldvalue);


            $rs = $enroldb->Execute($sql);

        }

        if (!$rs) {
            trigger_error($enroldb->ErrorMsg() .' STATEMENT: '. $sql);
            return false;
        }
        if ( $rs->EOF ) { // no courses! outta here...
            return false;
        }

        begin_sql();
        $courseidfield = strtolower($CFG->enrol_idb_remotecoursefield);
        while ($extcourse_obj = rs_fetch_next_record($rs)) { // there are more course records
            $extcourse_obj = (object)array_change_key_case((array)$extcourse_obj , CASE_LOWER);
            $extcourse = $extcourse_obj->$courseidfield;

            // does the course exist in moodle already?
            $course = false;
            $course = get_record( 'course',
                                  $CFG->enrol_idb_localcoursefield,
                                  addslashes($extcourse) );

            if (!is_object($course)) {
                if (empty($CFG->enrol_idb_courseautocreate)) { // autocreation not allowed
                    if (debugging('', DEBUG_ALL)) {
                        error_log( "Course $extcourse does not exist, no autocreate, skipping...");
                    }
                    continue; // next foreach course
                }
                // ok, now then let's create it!
                // prepare any course properties we actually have
                $fullname = isset($extcourse_obj->{$CFG->enrol_idb_remotecoursefullnamefield})?
                    $extcourse_obj->{$CFG->enrol_idb_remotecoursefullnamefield}:
                    $extcourse;
                $shortname = isset($extcourse_obj->{$CFG->enrol_idb_remotecourseshortnamefield})?
                    $extcourse_obj->{$CFG->enrol_idb_remotecourseshortnamefield}:
                    $fullname;

                $course = new stdClass();
                $course->{$CFG->enrol_idb_localcoursefield} = $extcourse;
                $course->fullname  = $fullname;
                $course->shortname = $shortname;
                if (!($newcourseid = $this->create_course($course, $extcourse_obj, true) &&
                    $course = get_record( 'course', 'id', $newcourseid))) {
                    error_log( "Failed creation of course with external id '$extcourse' by enrol/idatabase");
                    continue; // nothing left to do...
                }
            }
        }
    }

    /**
     * Updates enrolments for external users into specified $extcourses.
     * Courses from external database have already been created.
     *
     * @global object $CFG
     * @global object $db
     * @param object $enroldb
     * @param object $role the role to enrol, or false to use default role.
     * @return boolean
     */
    function enrol_users(&$enroldb, $role) {
        global $CFG;
        global $db;

        if (isset($role)) {
            echo '=== Syncing enrolments for role: '.$role->shortname." ===\n";
        } else {
            echo "=== Syncing enrolments for default role ===\n";
        }

        if (empty($extcourses)) {
            echo "=== There exist no courses to sync. END Syncing ===\n";
            return; //concluded ok.
        }

        // first, pack the sortorder...
        fix_course_sortorder();

        list($have_role, $remote_role_name, $remote_role_value) = $this->role_fields($enroldb, $role);

        if (!$have_role) {
            if (!empty($CFG->enrol_idb_defaultcourseroleid)
             and $role = get_record('role', 'id', $CFG->enrol_idb_defaultcourseroleid)) {
                echo "=== Using enrol_idb_defaultcourseroleid: {$role->id} ({$role->shortname}) ===\n";
            } elseif (isset($role)) {
                echo "!!! WARNING: Role specified by caller, but no (or invalid) role configuration !!!\n";
            }
        }

        // get enrolments per-course
        $sql =  "SELECT DISTINCT {$CFG->enrol_idb_remoteenrolcoursefield}," .
            " FROM {$CFG->enrol_idb_enroltable}" .
            " WHERE {$CFG->enrol_idb_remoteuserfield} IS NOT NULL AND" .
            (isset($remote_role_name, $remote_role_value) ? ' AND '.$remote_role_name.' = '.$remote_role_value : '');

        $rs = $db->Execute($sql);
        if (!$rs) {
            trigger_error($db->ErrorMsg() .' STATEMENT: '. $sql);
            return false;
        }
        if ( $rs->EOF ) { // no courses! outta here...
            return true;
        }

        begin_sql();
        $extcourses = array();
        $remotecourseidfield = strtolower($CFG->enrol_idb_remotecoursefield);

        while ($extcourse_obj = rs_fetch_next_record($rs)) { // there are more course records

            $extcourse_obj = (object)array_change_key_case((array)$extcourse_obj , CASE_LOWER);
            $extcourse = $extcourse_obj->{$remotecourseidfield};
            array_push($extcourses, $extcourse);

            // does the course exist in moodle already? it should.
            $course = false;
            $course = get_record( 'course',
                                  $CFG->enrol_idb_localcoursefield,
                                  addslashes($extcourse) );

            if (!is_object($course)) {
                echo "External course $extcourse was not created previously. This will be created on next syncing. Skipping...\n";
                continue;
            }

            $context = get_context_instance(CONTEXT_COURSE, $course->id);

            // If we don't have a proper role setup, then we default to the default
            // role for the current course.
            if (!$have_role) {
                $role = get_default_course_role($course);
            }

            // get a list of the user ids to be enrolled
            // from the external db -- hopefully it'll fit in memory...
            $extenrolments = array();
            $sql = "SELECT {$CFG->enrol_idb_remoteuserfield} " .
                " FROM {$CFG->enrol_idb_enroltable} " .
                " WHERE {$CFG->enrol_idb_remoteenrolcoursefield} = " . $enroldb->quote($extcourse) .
                    ($have_role ? ' AND '.$remote_role_name.' = '.$remote_role_value : '');

            $crs = $enroldb->Execute($sql);
            if (!$crs) {
                trigger_error($enroldb->ErrorMsg() .' STATEMENT: '. $sql);
                return false;
            }
            if ( $crs->EOF ) { // shouldn't happen, but cover all bases
                continue;
            }

            // slurp results into an array
            while ($crs_obj = rs_fetch_next_record($crs)) {
                $crs_obj = (object)array_change_key_case((array)$crs_obj , CASE_LOWER);
                array_push($extenrolments, $crs_obj->{strtolower($CFG->enrol_idb_remoteuserfield)});
            }
            rs_close($crs); // release the handle

            //
            // prune enrolments to users that are no longer in ext auth
            // hopefully they'll fit in the max buffer size for the RDBMS
            //
            // TODO: This doesn't work perfectly.  If we are operating without
            // roles in the external DB, then this doesn't handle changes of role
            // within a course (because the user is still enrolled in the course,
            // so NOT IN misses the course).
            //
            // When the user logs in though, their role list will be updated
            // correctly.
            //
            if (!$CFG->enrol_idb_disableunenrol) {
                $to_prune = get_records_sql("
                 SELECT ra.*
                 FROM {$CFG->prefix}role_assignments ra
                  JOIN {$CFG->prefix}user u ON ra.userid = u.id
                 WHERE ra.enrol = 'idatabase'
                  AND ra.contextid = {$context->id}
                  AND ra.roleid = ". $role->id . ($extenrolments
                    ? " AND u.{$CFG->enrol_idb_localuserfield} NOT IN (".implode(", ", array_map(array(&$db, 'quote'), $extenrolments)).")"
                    : ''));

                if ($to_prune) {
                    foreach ($to_prune as $role_assignment) {
                        if (role_unassign($role->id, $role_assignment->userid, 0, $role_assignment->contextid)){
                            error_log( "Unassigned {$role->shortname} assignment #{$role_assignment->id} for course {$course->id} (" . format_string($course->shortname) . "); user {$role_assignment->userid}");
                        } else {
                            error_log( "Failed to unassign {$role->shortname} assignment #{$role_assignment->id} for course {$course->id} (" . format_string($course->shortname) . "); user {$role_assignment->userid}");
                        }
                    }
                }
            }

            //
            // insert current enrolments
            // bad we can't do INSERT IGNORE with postgres...
            //
            foreach ($extenrolments as $member) {
                // Get the user id and whether is enrolled in one fell swoop
                $sql = "
                    SELECT u.id AS userid, ra.id AS enrolmentid
                    FROM {$CFG->prefix}user u
                     LEFT OUTER JOIN {$CFG->prefix}role_assignments ra ON u.id = ra.userid
                      AND ra.roleid = {$role->id}
                      AND ra.contextid = {$context->id}
                     WHERE u.{$CFG->enrol_idb_localuserfield} = ".$db->quote($member) .
                     " AND (u.deleted IS NULL OR u.deleted=0) ";

                $ers = $db->Execute($sql);
                if (!$ers) {
                    trigger_error($db->ErrorMsg() .' STATEMENT: '. $sql);
                    return false;
                }
                if ( $ers->EOF ) { // if this returns empty, it means we don't have the student record.
                                                  // should not happen -- but skip it anyway
                    trigger_error('weird! no user record entry?');
                    continue;
                }
                $user_obj = rs_fetch_record($ers);
                $userid      = $user_obj->userid;
                $enrolmentid = $user_obj->enrolmentid;
                rs_close($ers); // release the handle

                if ($enrolmentid) { // already enrolled - skip
                    continue;
                }

                if (role_assign($role->id, $userid, 0, $context->id, 0, 0, 0, 'idatabase')){
                    error_log( "Assigned role {$role->shortname} to user {$userid} in course {$course->id} (" . format_string($course->shortname) . ")");
                } else {
                    error_log( "Failed to assign role {$role->shortname} to user {$userid} in course {$course->id} (" . format_string($course->shortname) . ")");
                }

            } // end foreach member
        } // end while course records
        rs_close($rs); //Close the main course recordset

        //
        // prune enrolments to courses that are no longer in ext auth
        //
        // TODO: This doesn't work perfectly.  If we are operating without
        // roles in the external DB, then this doesn't handle changes of role
        // within a course (because the user is still enrolled in the course,
        // so NOT IN misses the course).
        //
        // When the user logs in though, their role list will be updated
        // correctly.
        //
        if (!$CFG->enrol_idb_disableunenrol) {
            $sql = "
                SELECT ra.roleid, ra.userid, ra.contextid
                FROM {$CFG->prefix}role_assignments ra
                    JOIN {$CFG->prefix}context cn ON cn.id = ra.contextid
                    JOIN {$CFG->prefix}course c ON c.id = cn.instanceid
                WHERE ra.enrol = 'idatabase'
                  AND cn.contextlevel = ".CONTEXT_COURSE." " .
                    ($have_role ? ' AND ra.roleid = '.$role->id : '') .
                    ($extcourses
                        ? " AND c.{$CFG->enrol_idb_localcoursefield} NOT IN (" . join(",", array_map(array(&$db, 'quote'), $extcourses)) . ")"
                        : '');

            $ers = $db->Execute($sql);
            if (!$ers) {
                trigger_error($db->ErrorMsg() .' STATEMENT: '. $sql);
                return false;
            }
            if ( !$ers->EOF ) {
                while ($user_obj = rs_fetch_next_record($ers)) {
                    $user_obj = (object)array_change_key_case((array)$user_obj , CASE_LOWER);
                    $roleid     = $user_obj->roleid;
                    $user       = $user_obj->userid;
                    $contextid  = $user_obj->contextid;
                    if (role_unassign($roleid, $user, 0, $contextid)){
                        error_log( "Unassigned role {$roleid} from user $user in context $contextid");
                    } else {
                        error_log( "Failed unassign role {$roleid} from user $user in context $contextid");
                    }
                }
                rs_close($ers); // release the handle
            }
        }

        commit_sql();

        // we are done now, a bit of housekeeping
        fix_course_sortorder();

        $this->enrol_disconnect($enroldb);
        return true;

    }

    /**
     * Overide the get_access_icons() function
     * @param type $course
     */
    function get_access_icons($course) {
    }

    /**
     * Builds the list of course items to get from external database.
     * @global object $CFG
     * @return array array of strings with the fieldnames of the table of courses.
     */
    function get_course_fields() {
        global $CFG;

        //list of fields from course table
        $fields = array();

        //course id is always got
        array_push($fields, $CFG->enrol_idb_remotecoursefield);

        //get the fullname field
        if (!empty($CFG->enrol_idb_remotecoursefullnamefield)) {
            array_push($fields, $CFG->enrol_idb_remotecoursefullnamefield);
        }

        //get the shortname field
        if (!empty($CFG->enrol_idb_remotecourseshortnamefield)) {
            array_push($fields, $CFG->enrol_idb_remotecourseshortnamefield);
        }


        //if course has a category, get it. otherwise, we will get nothing more.
        if (!empty($CFG->enrol_idb_remotecoursecategoryfield)) {
            array_push($fields, $CFG->enrol_idb_remotecoursecategoryfield);

            //we got category name. there exist a subcategory?
            if (!empty($CFG->enrol_idb_remotecoursesubcategoryfield)) {
                array_push($fields, $CFG->enrol_idb_remotecoursesubcategoryfield);
            }
        }

        //list of fields to get from the external database
        return $fields;
    }

    /**
     * Gets all parameter settings.
     * @return array array with the name of all the settings.
     */
    function get_settings() {
        return array('enrol_idb_type', 'enrol_idb_host', 'enrol_idb_name',
            'enrol_idb_user', 'enrol_idb_pass', 'enrol_idb_coursetable',
            'enrol_idb_localcoursefield', 'enrol_idb_remotecoursefield',
            'enrol_idb_remotecoursecategoryfield', 'enrol_idb_remotecoursesubcategoryfield',
            'enrol_idb_localcoursemissingcategory', 'enrol_idb_remotecoursefullnamefield',
            'enrol_idb_remotecourseshortnamefield', 'enrol_idb_enroltable',
            'enrol_idb_localuserfield', 'enrol_idb_remoteuserfield',
            'enrol_idb_localrolefield', 'enrol_idb_remoterolefield',
            'enrol_idb_localenrolcoursefield', 'enrol_idb_remoteenrolcoursefield',
            'enrol_idb_defaultcourseroleid', 'enrol_idb_allowedroles',
            'enrol_idb_courseautocreate', 'enrol_idb_categoryautocreate',
            'enrol_idb_category', 'enrol_idb_template', 'enrol_idb_ignorehiddencourse',
            'enrol_idb_disableunenrol', 'enrol_idb_disablelogincheck');
    }

    /**
     * Loads all the settings of this enrolment plugin, setting an empty string
     * to any non existing parameter from database. After that, shows the plugin form.
     * @param object $frm Object with plugin settings from database.
     */
    function config_form($frm) {
        global $CFG;

        $vars = $this->get_settings();

        foreach ($vars as $var) {
            if (!isset($frm->$var)) {
                $frm->$var = '';
            }
        }

        include("$CFG->dirroot/enrol/idatabase/config.phtml");
    }

    /**
     * Process data from web form.
     * @param object $config settings received from web form.
     * @return boolean if the configuration settings have been processed
     * successfully.
     */
    function process_config($config) {

        if (!isset($config->enrol_idb_type)) {
            $config->enrol_idb_type = 'mysql';
        }
        set_config('enrol_idb_type', $config->enrol_idb_type);

        if (!isset($config->enrol_idb_allowedroles)) {
            $config->enrol_idb_allowedroles = '';
        } else {
            $config->enrol_idb_allowedroles = implode(',', $config->enrol_idb_allowedroles);
        }
        set_config('enrol_idb_allowedroles', $config->enrol_idb_allowedroles);

        //getting all settings except enrol_idb_type and enrol_idb_allowedroles
        $vars = $this->get_settings();
        $key = array_search('enrol_idb_type', $vars);
        unset($vars[$key]);
        $key = array_search('enrol_idb_allowedroles', $vars);
        unset($vars[$key]);

        foreach ($vars as $setting) {
            if (!isset($config->$setting)) {
                $config->$setting = '';
            }
            set_config($setting, $config->$setting);
        }

        return true;
    }

    /**
     * This will create the moodle course from the template.
     * NOTE: if you pass true for $skip_fix_course_sortorder
     * you will want to call fix_course_sortorder() after your are done
     * with course creation
     *
     * @global object $CFG
     * @param object $course course to create
     * @param object $extcourse course data from external database.
     * @param boolean $skip_fix_course_sortorder if true, fix_course_sortorder() will be invoked.
     * @return boolean|int false if the course could not be created. The id
     * for the created course.
     */
    function create_course ($course, $extcourse, $skip_fix_course_sortorder=0){
        global $CFG;

        // define a template
        if(!empty($CFG->enrol_idb_template)){
            $template = get_record("course", 'shortname', $CFG->enrol_idb_template);
            $template = (array)$template;
        } else {
            $site = get_site();
            $template = array(
                              'startdate'      => time() + 3600 * 24,
                              'summary'        => get_string("defaultcoursesummary"),
                              'format'         => "weeks",
                              'password'       => "",
                              'guest'          => 0,
                              'numsections'    => 15,
                              'idnumber'       => '',
                              'cost'           => '',
                              'newsitems'      => 5,
                              'showgrades'     => 1,
                              'groupmode'      => 0,
                              'groupmodeforce' => 0,
                              'student'  => $site->student,
                              'students' => $site->students,
                              'teacher'  => $site->teacher,
                              'teachers' => $site->teachers,
                              );
        }
        // overlay template
        foreach (array_keys($template) AS $key) {
            if (empty($course->$key)) {
                $course->$key = $template[$key];
            }
        }

        $course->category = $this->get_category($extcourse);
        if ($course->category == false) {
            trigger_error("Could not get or create the category for the new course " .
                print_r($extcourse,true) . " from database");
            notify("Serious Error! Could not create the category for the new course!");
            return false;
        }

        // define the sortorder
        $sort = get_field_sql('SELECT COALESCE(MAX(sortorder)+1, 100) AS max ' .
                              ' FROM ' . $CFG->prefix . 'course ' .
                              ' WHERE category=' . $course->category);
        $course->sortorder = $sort;

        // override with local data
        $course->startdate   = time() + 3600 * 24;
        $course->timecreated = time();
        $course->visible     = 0; //hidden by default

        // clear out id just in case
        unset($course->id);

        // truncate a few key fields
        $course->idnumber  = substr($course->idnumber, 0, 100);
        $course->shortname = substr($course->shortname, 0, 100);
        $course->fullname  = substr($course->fullname, 0, 254);

        // store it and log
        $newcourseid = insert_record("course", addslashes_object($course));
        if ($newcourseid) {  // Set up new course
            $section = NULL;
            $section->course = $newcourseid;   // Create a default section.
            $section->section = 0;
            $section->id = insert_record("course_sections", $section);
            $page = page_create_object(PAGE_COURSE_VIEW, $newcourseid);
            blocks_repopulate_page($page); // Return value no


            if(!$skip_fix_course_sortorder){
                fix_course_sortorder();
            }
            add_to_log($newcourseid, "course", "new", "view.php?id=$newcourseid", "enrol/idatabase auto-creation");
        } else {
            trigger_error("Could not create new course $extcourse from  from database");
            notify("Serious Error! Could not create the new course!");
            return false;
        }

        return $newcourseid;
    }

    /**
     * Returns the course category id or false if it does not exists and cannot be
     * created or it is not allowed to.
     * @global object $CFG global configuration.
     * @param object $extcourse record from external database with the course data.
     * @return mixed the course category id or false when the course category cannot
     * be found.
     */
    function get_category($extcourse) {
        global $CFG;

        $category = 1;     // the misc 'catch-all' category
        if (!empty($CFG->enrol_idb_category)){ //category = 0 or undef will break moodle

            $category = $CFG->enrol_idb_category;
        }
        error_log(" Main category id: $category");

        //check the category specified in the course details from external database
        list($is_set, $newcategory) = $this->build_category($extcourse, $category, 'remotecoursecategoryfield');
        error_log(" Is set the course category? '$is_set'. Category id is: $newcategory");

        //is category is not set, return the above category
        if (!$is_set) {
            return $category; //do not check for subcategory
        } else if (!$newcategory) {
            //specified a category, but it could not be obtanied or created.
            return false;
        }

        //check the subcategory specified in the course details from external database
        list($is_set, $subcategory) = $this->build_category($extcourse, $category, 'remotecoursesubcategoryfield');

        //if category is not set, return the above category
        if (!$is_set) {
            return $category;
        }

        return $subcategory;
    }

    /**
     * Having the course details from the external database, check if we have
     * to create a category under the parent category with id $category.
     * We check the field with name $fieldname. If in the plugin settings has been
     * setup a category field from the external database, and the field value is empty,
     * the course will be created in the parent category $category.
     * @global object $CFG
     * @param object $extcourse course data from the external database.
     * @param int $category parent category id
     * @param string $fieldname fieldname of the course data
     * @return boolean|int return false if the category could not be created.
     * The category id otherwise.
     */
    function build_category($extcourse, $category, $fieldname) {
        global $CFG;
        global $db;

        $cfgfield = 'enrol_idb_'.$fieldname;
        $catfieldname = $CFG->$cfgfield;
        error_log(" Looking for setting $cfgfield: ". $catfieldname);

        $category_is_set = !empty($CFG->$cfgfield);

        // if there is no category setting, nothing to do. ok.
        if (!$category_is_set) {
            return array(false, false);
        }

        // if category setting is set up, force a value on it.
        if ($category_is_set && empty($extcourse->$catfieldname)) {
            return array(true, false);
        }

        // now... getting or creating the category
        error_log(" Finding category with name: " . $extcourse->$catfieldname);
        $newcatid = $category;
        $extcat = get_field_sql('SELECT id ' .
                          ' FROM ' . $CFG->prefix . 'course_categories ' .
                          ' WHERE name =  '. $db->quote($extcourse->$catfieldname) );
        if ($extcat == false) {
            error_log(" Category not found.");
            if (!empty($CFG->enrol_idb_categoryautocreate)) {
                //the category does not exist and we are allowed to create it
                $newcat = $this->create_category($extcourse->$catfieldname, $category);
                if (!$newcat) {
                    error_log(" Error: We cannot create the category.");
                    //the category could not be created.
                    $newcatid = false;
                }
                $newcatid = $newcat->id;
                error_log(" Category created with id: $newcatid");
            } else {
                error_log(" We cannot create categories. Skipping.");
                //the category does not exists and it is not allowed to create it.
                $newcatid = false;
            }
        } else {
            error_log(" Category already exists with id: $extcat");
            //the category exists
            $newcatid = $extcat;
        }
        return array($category_is_set, $newcatid);
    }

    /**
     * Creates a course category.
     * @param string $name name of the category
     * @param int $parent id from the parent course category.
     * @return boolean|object false if the category could not be created.
     * The category instance otherwise.
     */
    function create_category($name, $parent) {
        //code inspired from course/editcategory.php
        $cat = new stdClass();
        $cat->name = $name;
        $cat->parent = $parent;
        $cat->description = NULL;
        $cat->theme = NULL;
        $cat->sortorder = 999;
        if (!$cat->id = insert_record('course_categories', addslashes_object($cat))) {
            error_log("Could not insert the new category '{$cat->name}' ");
            return false;
        }
        $cat->context = get_context_instance(CONTEXT_COURSECAT, $cat->id);
        mark_context_dirty($cat->context->path);
        fix_course_sortorder(); // Required to build course_categories.depth and .path.
        add_to_log($cat->id, "course", "new", "category.php?id={$cat->id}", "enrol/idatabase category auto-creation");
        return $cat;
    }

    /// DB Connect
    /// NOTE: You MUST remember to disconnect
    /// when you stop using it -- as this call will
    /// sometimes modify $CFG->prefix for the whole of Moodle!
    function enrol_connect() {
        global $CFG;

        // Try to connect to the external database (forcing new connection)
        $enroldb = &ADONewConnection($CFG->enrol_idb_type);
        if ($enroldb->Connect($CFG->enrol_idb_host, $CFG->enrol_idb_user, $CFG->enrol_idb_pass, $CFG->enrol_idb_name, true)) {
            $enroldb->SetFetchMode(ADODB_FETCH_ASSOC); ///Set Assoc mode always after DB connection
            return $enroldb;
        } else {
            trigger_error("Error connecting to enrolment DB backend with: "
                          . "$CFG->enrol_idb_host,$CFG->enrol_idb_user,$CFG->enrol_idb_pass,$CFG->enrol_idb_name");
            return false;
        }
    }

    /// DB Disconnect
    function enrol_disconnect($enroldb) {
        global $CFG;

        $enroldb->Close();
    }

    /**
     * This function returns the name and value of the role field to query the db
     * for, or null if there isn't one.
     *
     * @param object The ADOdb connection
     * @param object The role
     * @return array (boolean, string, db quoted string)
     */
    function role_fields($enroldb, $role) {
        global $CFG;

        if ($have_role = !empty($role)
         && !empty($CFG->enrol_idb_remoterolefield)
         && !empty($CFG->enrol_idb_localrolefield)
         && !empty($role->{$CFG->enrol_idb_localrolefield})) {
            $remote_role_name = $CFG->enrol_idb_remoterolefield;
            $remote_role_value = $enroldb->quote($role->{$CFG->enrol_idb_localrolefield});
        } else {
            $remote_role_name = $remote_role_value = null;
        }

        return array($have_role, $remote_role_name, $remote_role_value);
    }

} // end of class
