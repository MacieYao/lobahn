<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class User extends CI_Controller {

    public function __construct() {
        parent::__construct();
        
        // Load language file
        $lang = $this->input->get_post('lang');
        if ($lang == "tc") {
            $this->lang->load('system', 'tc');
        } else {
            $this->lang->load('system', 'en');
        }
        
        $this->loglib->log(0);
    }
    
    /*
     * Initialise the app and corresponding records in database
     */
    public function initialise() {
        $uuid = $this->input->get_post('uuid');
        $platform = $this->input->get_post('platform');
        $version = $this->input->get_post('version');
        $lang = $this->input->get_post('lang');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($uuid, $platform, $version, $lang))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        // Generate a token for this user
        $token = $this->userlib->generateToken($uuid);
        
        // Create a user record in the user table
        $query = "
            INSERT INTO ".TABLE_USERS."
            (token) VALUES (?)
        ";
        $data = array($token);
        $this->db->query($query, $data);
        $user_id = $this->db->insert_id();
        
        // Create a device record in the device table
        $query = "
            INSERT INTO ".TABLE_USERS_DEVICES."
            (user_id, uuid, platform, version, push_token, lang, create_datetime, update_datetime)
            VALUES
            (?, ?, ?, ?, '', ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                user_id = ?,
                platform = ?,
                version = ?,
                lang = ?,
                update_datetime = NOW()
        ";
        $data = array(
            $user_id, $uuid, $platform, $version, $lang,
            $user_id, $platform, $version, $lang
        );
        $result = $this->db->query($query, $data);
        
        // Output
        $output = array("token" => $token);
        $this->outputlib->output(STATUS_OK, "", $output);
    }
    
    /*
     * Register the user, create record in database,
     * send out verification email to the user
     */
    public function register() {
        $token = $this->input->get_post('token');
        $version = $this->input->get_post('version');
        $platform = $this->input->get_post('platform');
        $lang = $this->input->get_post('lang');
        $name = $this->input->get_post('name');
        $email = $this->input->get_post('email');
        $password = $this->input->get_post('password');
        
        // Check parameters
        $params = array($token, $name, $email, $password, $lang);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        // Check whether such token exists
        $query = "
            SELECT *
            FROM ".TABLE_USERS."
            WHERE token = ?
        ";
        $data = array($token);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        $user_id = $results[0]['id'];
        if (count($results) == 0) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Check whether such email exists
        $query = "
            SELECT * 
            FROM ".TABLE_USERS."
            WHERE email = ?
        ";
        $data = array($email);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        if (count($results) > 0) {
            $this->outputlib->error($this->lang->line('error_email_exists'));
        }
        
        // Generate verification code
        $code = md5($email.$password);
        
        // Update user record
        $query = "
            UPDATE ".TABLE_USERS."
            SET
                name = ?,
                email = ?,
                password = ?,
                activation_code = ?,
                language = ?,
				status = 1
            WHERE token = ?
        ";
        //$data = array($name, $email, $password, $code, $lang, $token);
        // No need verification now, code set to empty string
        $data = array($name, $email, $password, '', $lang, $token);
        $this->db->query($query, $data);
        
        // No need verification now
        /*
        // Send out verification email
		$config = Array(
			'protocol' => 'smtp',
			'smtp_host' => 'ssl://74.125.203.16',
			'smtp_port' => 465,
			'smtp_user' => 'info@lobahn.com',
			'smtp_pass' => 'n7^Ng#!qE8BZcMFKNsTD',
			'mailtype'  => 'html', 
			'charset'   => 'iso-8859-1' 
		);        

        $this->load->library('email', $config);
		$this->email->set_newline("\r\n");        
        
        $this->email->from(ADMIN_EMAIL, ADMIN_NAME); 
        $this->email->to($email); 
        $this->email->subject($this->lang->line('email_registration_subject'));
        
        // Prepare email message
        $data = array(
            'name' => $name, 
            'email' => $email, 
            'code' => $code
        );
        $message = $this->load->view('emails/register', $data, true);
        $this->email->message($message);  
        $this->email->send();
        //echo $this->email->print_debugger();
        */

        // Output
        $this->outputlib->output(
            STATUS_OK,
            $this->lang->line('message_registration_successful'),
            array(
                "user_id" => (string)$user_id,
                "token" => $token
            )
        );
    }
    
    /*
     * Activate the user's account if the token is valid
     */
    public function activate() {
        $code = $this->input->get_post('code');
        
        // Check parameters
        $params = array($code);
        if ($this->helperlib->hasEmptyParams($params)) {
            redirect(OFFICIAL_WEBSITE, 'refresh');
            return;
        }
        
        // Check whether the activation code exists
        $query = "
            SELECT *
            FROM ".TABLE_USERS."
            WHERE activation_code = ?
        ";
        $data = array($code);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        
        // No such activation code
        if (count($result) == 0) {
            redirect(OFFICIAL_WEBSITE, 'refresh');
            return;
        }
        
        // Activate the user (set activation_code to empty)
        $query = "
            UPDATE ".TABLE_USERS."
            SET activation_code = ''
            WHERE activation_code = ?
        ";
        $data = array($code);
        $this->db->query($query, $data);
        
        // Present Web page informing the user that his/her
        // account has been activated, can proceed to login
        $this->load->view('verified');
        return;
    }
    
    
    /*
     * Update the push token in the database
     */
    public function update_push_token() {
        $uuid = $this->input->get_post('uuid');
        $push_token = $this->input->get_post('push_token');
        $platform = $this->input->get_post('platform');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($uuid, $push_token, $platform))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        // Update database
        $query = "
            UPDATE ".TABLE_USERS_DEVICES."
            SET
                push_token = ?,
                platform = ?
            WHERE uuid = ?
        ";
        $data = array($push_token, $platform, $uuid);
        $this->db->query($query, $data);
        
        // Output
        $this->outputlib->output(STATUS_OK, "", array());
        return;
    }
    
    /*
     * User makes a request to reset password
     * Will send the user a email with instructions
     */
    public function forget_password() {
        $email = $this->input->get_post('email');
        
        $params = array($email);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        // Check whether such email exists
        $query = "
            SELECT *
            FROM ".TABLE_USERS."
            WHERE email = ?
        ";
        $data = array($email);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        if (count($result) == 0) {
            $this->outputlib->error($this->lang->line('error_email_not_exists'));
        }
        $user = $result[0];
        
        // Generate a new activation code for password reset
        $code = md5(rand(1000,9999).$user['name'].$user['email']);
        $query = "
            UPDATE ".TABLE_USERS."
            SET activation_code = ?
            WHERE id = ?
        ";
        $data = array($code, $user['id']);
        $this->db->query($query, $data);
        
        // Send out password reset eamil
        $this->load->library('email');
        $this->email->from(ADMIN_EMAIL, ADMIN_NAME);
        $this->email->to($email); 
        $this->email->subject($this->lang->line('email_forget_password_subject'));
        
        // Prepare email message
        $data = array(
            'name' => $user['name'], 
            'email' => $user['email'], 
            'code' => $code
        );
        $message = $this->load->view('emails/forget_password', $data, true);
        $this->email->message($message);  
        $this->email->send();
        
        // Output
        $this->outputlib->output(
            STATUS_OK,
            $this->lang->line('message_forget_password'),
            array());
    }
    
    
    /*
     * Allow the user to reset the password
     */
    public function reset_password() {
        $code = $this->input->get_post('code');
        
        // Check parameters
        $params = array($code);
        if ($this->helperlib->hasEmptyParams($params)) {
            redirect(OFFICIAL_WEBSITE, 'refresh');
            return;
        }
        
        // Retrieve user information
        $query = "
            SELECT *
            FROM ".TABLE_USERS."
            WHERE activation_code = ?
        ";
        $data = array($code);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        
        // Redirect user away if code is invalid
        if (count($result) == 0) {
            redirect(OFFICIAL_WEBSITE, 'refresh');
            return;
        }
        
        $data = array("user" => $result[0]);
        
        // Present the Web form to the user
        $this->load->view('reset_password', $data);
        return;
    }
    
    
    /*
     * Perform password reset for the user
     */
    public function do_reset_password() {
        $code = $this->input->post('code');
        $user_id = $this->input->post('user_id');
        $password = $this->input->post('password');
        
        // Check parameters
        $params = array($code);
        if ($this->helperlib->hasEmptyParams($params)) {
            redirect(OFFICIAL_WEBSITE, 'refresh');
            return;
        }
        
        // Check if such user record exists
        $query = "
            SELECT *
            FROM ".TABLE_USERS."
            WHERE
                activation_code = ?
            AND id = ?
        ";
        $data = array($code, $user_id);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        if (count($result) == 0) {
            redirect(OFFICIAL_WEBSITE, 'refresh');
            return;
        }
        
        // Reset the password of the user
        $query = "
            UPDATE ".TABLE_USERS."
            SET password = ?
            WHERE id = ?
        ";
        $data = array(md5($password), $user_id);
        $this->db->query($query, $data);
        
        // Present the finish page
        redirect('user/reset_password_finish', 'refresh');
        return;
    }
    
    public function reset_password_finish() {
        $this->load->view('reset_password_finish');
    }
    
    
    /*
     * For authenticating the user
     */
    public function login() {
        $uuid = $this->input->get_post('uuid');
        $email = $this->input->get_post('email');
        $password = $this->input->get_post('password');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($email, $password, $uuid))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        // Retrieve user record
         $query = "
            SELECT
                id
            FROM ".TABLE_USERS."
            WHERE
                email = ?
            AND password = ?
			AND status!=0
            
        ";
        $data = array($email, $password);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        
        // If no record found, return error (email or password not correct)
        if (count($result) == 0) {
            $this->outputlib->error($this->lang->line('error_email_password_incorrect'));
        }
		$query = "
            SELECT u.*,
                IF(l.location IS NULL, '', l.location) AS employer_location,
                GROUP_CONCAT(i.industry SEPARATOR '@@') AS industry
            FROM 
                ".TABLE_USERS." u
                LEFT OUTER JOIN ".TABLE_USERS_LOCATIONS." l
                    ON u.id = l.user_id
                    LEFT OUTER JOIN ".TABLE_USERS_INDUSTRIES." i
                        ON u.id = i.user_id
            WHERE
                u.email = ?
            AND u.password = ?

        ";
        $data = array($email, $password);
        $row = $this->db->query($query, $data);
        $rows = $row->result_array();
		$profile = $rows[0];
        $user_id = $profile['id'];
        $user = array();
        $user['user_id'] = $profile['id'];
        $user['name'] = $profile['name'];
        $user['email'] = $profile['email'];
        $user['profile_picture'] = USER_PROFILE_PICTURE_URL.$profile['profile_picture'];
        $user['phone'] = $profile['phone'];
        $user['language'] = $profile['language'];
        $user['current_employer'] = $profile['current_employer'];
        $user['current_employer_location'] = $profile['employer_location'];
        $user['current_job'] = $profile['current_job'];
        $user['current_salary'] = $profile['current_salary'];
		$user['current_credit'] = $profile['current_credit'];
        $user['industries'] = explode("@@", $profile['industry']);
        $user['token'] = $profile['token'];
        $user['activation_code'] = $profile['activation_code'];
        
        // Get links to profiles of the user
        $query = "
            SELECT id, link, descriptions
            FROM ".TABLE_USERS_LINKS."
            WHERE user_id = ?
        ";
        $data = array($user_id);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        $user['links'] = array();
        
         $i = 1; 
        foreach ($result as $row) {
			if($i==1){
				$user['links']['linkedin'] = $row;
			}else{
				$user['links']['other'] = $row;
			}
			$i++;
            //$link = $row['link'];
            // if (strpos($link, "linkedin") !== False) {
                // $user['links']['linkedin'] = $row;
            // } else {
                // $user['links']['other'] = $row;
            // }
			
        }

        if (count($result) == 0) {
            $user['links'] = array(
                    "linkedin" => array(
                            "id" => "",
                            "link" => "",
                            "descriptions" => ""
                        ),
                    "other" => array(
                            "id" => "",
                            "link" => "",
                            "descriptions" => ""
                        )
                );
        }
        
        // Get messages for job applications saved by the user
       /*  $query = "
            SELECT id, message
            FROM ".TABLE_USERS_MESSAGES."
            WHERE user_id = ?
        ";
        $data = array($user_id);
        $result = $this->db->query($query, $data);
        $user['messages'] = $result->result_array();
		
		$query = "
            SELECT id, cv
            FROM users_cv
            WHERE user_id = ?
        ";
        $data = array($user_id);
        $result = $this->db->query($query, $data);
        $user['cv'] = $result->result_array(); */
		$query = "
            SELECT id, message
            FROM ".TABLE_USERS_MESSAGES."
            WHERE user_id = ? ORDER BY timestamp DESC LIMIT 0,1
        ";
        $data = array($user_id);
        $result = $this->db->query($query, $data);
       if($result->row()){
			$user['messages'] = $result->row();
		}else{
			$user['messages']['id'] = "";
			$user['messages']['message'] = "";
		}
		// Get cv for job applications saved by the user
        $query = "
            SELECT id, cv
            FROM users_cv
            WHERE user_id = ? ORDER BY timestamp DESC LIMIT 0,1
        ";
        $data = array($user_id);
        $result = $this->db->query($query, $data);
       if($result->row()){
			$user['cv'] = $result->row();
		}else{
			$user['cv']['id'] = "";
			$user['cv']['cv'] = "";
		}
        // Output
        $this->outputlib->output(STATUS_OK, '', $user);
        return;
    }
    
    public function logout() {
        $uuid = $this->input->get_post('uuid');
        $token = $this->input->get_post('token');
        
        $user = $this->userlib->getUserProfile($token);
        $user_id = $user['id'];
        
        $query = "
            UPDATE users_devices
            SET login = 0
            WHERE
                uuid = ?
            AND user_id = ?
        ";
        $data = array($uuid, $user_id);
        $this->db->query($query, $data);
        
        // Output
        $this->outputlib->output(STATUS_OK, '', array());
        return;
    }
    
    public function upload_new_document() {
        $token = $this->input->post('token');
        $user = $this->userlib->getUserProfile($token);
        
        // If no file is uploaded, return error
        if (!isset($_FILES['file'])) {
            $this->outputlib->error($this->lang->line('error_upload_document'));
        }
        
        $user_id = $user['id'];
        $name = $_FILES['file']['name'];
        $tmp_name = $_FILES['file']['tmp_name'];
        
        // Create new file name
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $num = rand(10000,99999);
        $date = date('YmdHis');
        $new_name = $user_id."_".$num."_".$date.".".$ext;
        move_uploaded_file($tmp_name, USER_DOCUMENT_DIR.$new_name);
        
        // Insert into database and obtain a file ID
        $query = "
            INSERT INTO users_documents
                (user_id, filename, system_filename, upload_datetime, active)
            VALUES
                (?, ?, ?, NOW(), 1)
        ";
        $data = array($user_id, $name, $new_name);
        $this->db->query($query, $data);
        $file_id = $this->db->insert_id();
        
        $data = array();
        $data['url'] = USER_DOCUMENT_URL.$new_name;
         
        $this->outputlib->output(STATUS_OK, '', $data);
    }
    
    public function get_documents() {
        $token = $this->input->post('token');
        $user = $this->userlib->getUserProfile($token);
        
        $query = "
            SELECT
                id AS file_id,
                filename AS file_name,
                system_filename AS link,
                DATE(upload_datetime) AS upload_date
            FROM users_documents
            WHERE user_id = ?
            ORDER BY file_id DESC
        ";
        $data = array($user['id']);
        $rows = $this->db->query($query, $data);
        $rows = $rows->result_array();
        
        $output = array();
        foreach ($rows as $row) {
            $row['link'] = USER_DOCUMENT_URL.$row['link'];
            $output[] = $row;
        }
        
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
    /*
     * Upload user's profile picture, old profile picture will be replaced
     */
    public function upload_profile_picture() {
        $token = $this->input->post('token');
        $user = $this->userlib->getUserProfile($token);
        
        // If no file is uploaded, return error
        if (!isset($_FILES['profile_picture'])) {
            $this->outputlib->error($this->lang->line('error_profile_picture'));
        }
        
        $name = $_FILES['profile_picture']['name'];
        $tmp_name = $_FILES['profile_picture']['tmp_name'];
        
        // Save the profile picture
        $file = $this->uploadlib->saveProfilePicture($user['id'], $name, $tmp_name);
        
        // Update user record
        $query = "
            UPDATE ".TABLE_USERS."
            SET profile_picture = ?
            WHERE id = ?
        ";
        $data = array($file, $user['id']);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
    
    public function update_user_profile() {
        $token = $this->input->get_post('token');
        $user = $this->userlib->getUserProfile($token);
        
        $job = $this->input->get_post('job');
        $employer = $this->input->get_post('employer');
        $location = $this->input->get_post('location');
        $industries = $this->input->get_post('industries');
        $urls = $this->input->get_post('urls');
        $salary = $this->input->get_post('salary');
        $cv_url = $this->input->get_post('cv_url');
		$phone = $this->input->get_post('phone');
		 
        $industries = json_decode($industries);
        $urls = json_decode($urls);
        
        // Update user's current job and employer
        $query = "
            UPDATE users
            SET
                current_job = ?,
                current_employer = ?,
				current_salary = ?,
				cv_url = ?,
				phone = ?
            WHERE
                token = ?
        ";
        $data = array($job, $employer, $salary, $cv_url,$phone, $token);
        $this->db->query($query, $data);
        
        // Update user's location
        // TR changes to fix search for candidate not matching as location code was 0
        // TR Lookup location code from text input
        // TODO might need to remove TR code for neatness, performance.

        // TR if we have a numeric entry we pass it straight through (we can look up the text piece later)
        if (is_numeric($location)) {
            $location_code = $location;
            $location_name = '';
            // TODO should we look up the location text? Would need to be language dependent.
        } else {
            // TR lookup the text value to find id
            $query = "SELECT id FROM data_locations WHERE name_en LIKE ? OR name_tc LIKE ? LIMIT 1";
            $data  = array('%'.$location.'%', '%'.$location.'%');
            $rows  = $this->db->query($query, $data);
            $rows  = $rows->result_array();
            if (count($rows) == 0) {
                $location_code = '';
            } else {
                $row = $rows[0];
                $location_code = $row['id'];
            }
            $location_name = $location;
        }

        // TR now has location code entered
        $this->db->query("DELETE FROM users_locations WHERE user_id = ?", array($user['id']));
        $query = "
            INSERT INTO users_locations
            (user_id, location_code, location)
            VALUES
            (?, ?, ?)
        ";
        $data = array($user['id'], $location_code, $location_name);
        $this->db->query($query, $data);
        
        // Update user's industry
        // TR changes to store correct industry value rather than 0
        // Industries are submitted as an array so need to loop lookups
        $industry_data = array();
        foreach ($industries as $industry) {
            if (is_numeric($industry)) {
                $industry_id = $industry;
                $industry_name = '';
                // TODO should we look up the industry text? Would need to be language dependent.
            } else {
                // TR lookup the text value to find id
                $query = "SELECT id FROM data_industries WHERE name_en LIKE ? OR name_tc LIKE ? LIMIT 1";
                $data  = array('%'.$industry.'%', '%'.$industry.'%');
                $rows  = $this->db->query($query, $data);
                $rows  = $rows->result_array();
                if (count($rows) == 0) {
                    $industry_id = '';
                } else {
                    $row = $rows[0];
                    $industry_id = $row['id'];
                }
                $industry_name = $industry;
            }
            $industry_data[] = array($industry_id, $industry_name);
        }

        // Now adds info from industry_data
        $this->db->query("DELETE FROM users_industries WHERE user_id = ?", array($user['id']));
        foreach ($industry_data as $industry) {
            $query = "
                INSERT INTO users_industries
                (user_id, industry_id, industry)
                VALUES
                (?, ?, ?)
            ";
            $data = array($user['id'], $industry[0], $industry[1]);
            $this->db->query($query, $data);
        }
        
        // Update user's links
        $this->db->query("DELETE FROM users_links WHERE user_id = ?", array($user['id']));
        foreach ($urls as $url) {
            $query = "
                INSERT INTO users_links
                (user_id, link, descriptions)
                VALUES
                (?, ?, '')
            ";
            $data = array($user['id'], $url);
            $this->db->query($query, $data);
        }
        
        $this->outputlib->output(STATUS_OK, '', array());
    }

// TR addition to update just the user's links 20150331
    public function update_links() {
        $token = $this->input->get_post('token');
        $user = $this->userlib->getUserProfile($token);        
        $urls = $this->input->get_post('urls');        
        
        $urls = json_decode($urls);

        // Update user's links
        $this->db->query("DELETE FROM users_links WHERE user_id = ?", array($user['id']));
        foreach ($urls as $url) {
            $query = "
                INSERT INTO users_links
                (user_id, link, descriptions)
                VALUES
                (?, ?, '')
            ";
            $data = array($user['id'], $url);
            $this->db->query($query, $data);
        }
        
        $this->outputlib->output(STATUS_OK, '', array());
    }   

    public function apply_job() {
        $token = $this->input->get_post('token');
        $job_id = $this->input->get_post('job_id');
        $phone = $this->input->get_post('phone');
        $message = $this->input->get_post('message');
        $cv_url = $this->input->get_post('cv_url');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token, $job_id))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        $query = "
            INSERT INTO ".TABLE_JOB_APPLICATIONS."
            (user_id, job_id, cv_url, phone, message, timestamp)
            VALUES
            (?, ?, ?, ?, ?, NOW())
        ";
        $data = array($user['id'], $job_id, $cv_url, $phone,$message);
        $this->db->query($query, $data);
        
        // ==========================================================
        // Push notification to recruiter of this job
        /*
        $query = "
            SELECT
                d.push_token, d.platform
            FROM
                jobs j, users u, users_devices d
            WHERE
                j.id = ?
            AND j.user_id = u.id
            AND u.id = d.user_id
        ";
        $data = array($job_id);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $users_android = array();
        $users_ios = array();
        foreach ($results as $row) {
            if ($row['platform'] == 'ios') $users_ios[] = $row['push_token'];
            if ($row['platform'] == 'android') $users_android[] = $row['push_token'];
        }
        
        $title = "Job Application";
        $message = "A candidate applied your job";
        $code = "ACTION_VIEW_CANDIDATE_PAGE";
        $params = '{"candidate_id":"'.$user_id.'"}';
        $this->pushlib->pushAndroid($title, $message, $code, $param, $user_id, $users_android);
        $this->pushlib->pushIOS($title, $message, $code, $param, $user_id, $users_ios);
        */
        // ==========================================================
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
    public function hide_job() {
        $token = $this->input->get_post('token');
        $job_id = $this->input->get_post('job_id');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token, $job_id))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Keep record in database
        $query = "
            INSERT INTO ".TABLE_JOB_ACTIONS."
            (user_id, job_id, action, timestamp)
            VALUES
            (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE timestamp = NOW()
        ";
        $data = array($user['id'], $job_id, ACTION_HIDE_JOB);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }

    //get the list of the user had hidden jobs
    public function get_hide_jobs() {
        $token = $this->input->get_post('token');
        
        // // Check parameters
        // if ($this->helperlib->hasEmptyParams(array($token))) {
        //     $this->outputlib->error($this->lang->line('error_missing_parameters'));
        // }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Keep record in database
        $query = "
            SELECT
                xjobs.*
            FROM
                (
                    SELECT *
                    FROM ".TABLE_JOB_ACTIONS."
                    WHERE 
                        action = ?
                    AND user_id = ?
                ) hide
                    JOIN
                (
                    SELECT
                        jobs.id,
                        jobs.title,
                        jobs.company,
                        jobs.salary_min AS salary,
                        jobs.show_salary,
                        jobs.industry,
                        jobs.post_date AS date_post,
                        xlocs.name_en AS location
                    FROM
                        (
                            SELECT id, name_en
                            FROM data_locations
                        ) xlocs
                            LEFT OUTER JOIN jobs_location_code xlcodes
                            ON xlocs.id = xlcodes.location_code
                                LEFT OUTER JOIN jobs jobs
                                ON xlcodes.job_id = jobs.id
                    
                ) xjobs
                    ON xjobs.id = hide.job_id
            ORDER BY hide.timestamp DESC
        ";
        $data = array(ACTION_HIDE_JOB,$user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $jobs = array();
        foreach ($results as $row) {
            $row['company_logo'] = DEFAULT_COMPANY_LOGO;
            $row['branch'] = '';
            $row['summary'] = '...';
            $row['date_due'] = '2015-06-30';
            $jobs[] = $row;
        }
        
        $output = array();
        $output['jobs'] = $jobs;
        $this->outputlib->output(STATUS_OK, '', $output);
    }

    //action to remove the hidden job 
    // public function remove_hide_job() {
    //     $token = $this->input->get_post('token');
    //     $job_id = $this->input->get_post('job_id');
        
    //     // Check parameters
    //     if ($this->helperlib->hasEmptyParams(array($token, $job_id))) {
    //         $this->outputlib->error($this->lang->line('error_missing_parameters'));
    //     }
    //     $user = $this->userlib->getUserProfile($token);
    //     if (!$user) {
    //         $this->outputlib->error($this->lang->line('error_token_not_exists'));
    //     }
        
    //     // Keep record in database
    //     $query = "
    //         DELETE FROM ".TABLE_JOB_ACTIONS."
    //         WHERE
    //             user_id = ?
    //         AND job_id = ?
    //         AND action = ?
    //     ";
    //     $data = array($user['id'], $job_id, ACTION_HIDE_JOB);
    //     $this->db->query($query, $data);
        
    //     $this->outputlib->output(STATUS_OK, '',  $data);
    // }
    
    public function save_job() {
        $token = $this->input->get_post('token');
        $job_id = $this->input->get_post('job_id');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token, $job_id))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Keep record in database
        $query = "
            INSERT INTO ".TABLE_JOB_ACTIONS."
            (user_id, job_id, action, timestamp)
            VALUES
            (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE timestamp = NOW()
        ";
        $data = array($user['id'], $job_id, ACTION_SAVE_JOB);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
    public function remove_saved_job() {
        $token = $this->input->get_post('token');
        $job_id = $this->input->get_post('job_id');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token, $job_id))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Keep record in database
        $query = "
            DELETE FROM ".TABLE_JOB_ACTIONS."
            WHERE
                user_id = ?
            AND job_id = ?
            AND action = ?
        ";
        $data = array($user['id'], $job_id, ACTION_SAVE_JOB);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '',  $data);
    }
    
    public function get_saved_jobs() {
        $token = $this->input->get_post('token');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Retrieve saved jobs
        $query = "
            SELECT
                xjobs.*
            FROM
                (
                    SELECT *
                    FROM action_jobs
                    WHERE 
                        action = 1
                    AND user_id = ?
                ) saved
                    JOIN
                (
                    SELECT
                        jobs.id,
                        jobs.title,
                        jobs.company,
                        jobs.salary_min AS salary,
                        jobs.show_salary,
                        jobs.industry,
                        jobs.post_date AS date_post,
                        xlocs.name_en AS location
                    FROM
                        (
                            SELECT id, name_en
                            FROM data_locations
                        ) xlocs
                            LEFT OUTER JOIN jobs_location_code xlcodes
                            ON xlocs.id = xlcodes.location_code
                                LEFT OUTER JOIN jobs jobs
                                ON xlcodes.job_id = jobs.id
                    
                ) xjobs
                    ON xjobs.id = saved.job_id
            ORDER BY saved.timestamp DESC
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $jobs = array();
        foreach ($results as $row) {
            $row['company_logo'] = DEFAULT_COMPANY_LOGO;
            $row['branch'] = '';
            $row['summary'] = '...';
            $row['date_due'] = '2015-06-30';
            $jobs[] = $row;
        }
        
        $output = array();
        $output['jobs'] = $jobs;
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
	public function get_applied_jobs() {
        $token = $this->input->get_post('token');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Retrieve saved jobs
        $query = "
            SELECT
                xjobs.*,applied.id as apply_id
            FROM
                (
                    SELECT *
                    FROM action_job_applications
                    WHERE 
                        user_id = ?
                    AND status = 1
                ) applied
                    JOIN
                (
                    SELECT
                        jobs.id,
                        jobs.title,
                        jobs.company,
                        jobs.salary_min AS salary,
                        jobs.show_salary,
                        jobs.industry,
                        jobs.post_date AS date_post,
                        xlocs.name_en AS location
                    FROM
                        (
                            SELECT id, name_en
                            FROM data_locations
                        ) xlocs
                            LEFT OUTER JOIN jobs_location_code xlcodes
                            ON xlocs.id = xlcodes.location_code
                                LEFT OUTER JOIN jobs jobs
                                ON xlcodes.job_id = jobs.id
                    
                ) xjobs
                    ON xjobs.id = applied.job_id
            ORDER BY applied.timestamp DESC
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $jobs = array();
        foreach ($results as $row) {
            $row['company_logo'] = DEFAULT_COMPANY_LOGO;
            $row['branch'] = '';
            $row['summary'] = '...';
            $row['date_due'] = '2015-06-30';
            $jobs[] = $row;
        }
        
        $output = array();
        $output['jobs'] = $jobs;
        $this->outputlib->output(STATUS_OK, '', $output);
    }
	
	public function remove_applied_job() {
        $token = $this->input->get_post('token');
        $apply_id = $this->input->get_post('apply_id');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token, $apply_id))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Keep record in database
        $query = "
            UPDATE ".TABLE_JOB_APPLICATIONS."
            SET status = 0
            WHERE
                user_id = ?
            AND id = ?
        ";
        $data = array($user['id'], $apply_id);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '',  $data);
    }

	
    public function share_job() {
        $token = $this->input->get_post('token');
        $job_id = $this->input->get_post('job_id');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token, $job_id))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Keep record in database
        $query = "
            INSERT INTO ".TABLE_JOB_ACTIONS."
            (user_id, job_id, action, timestamp)
            VALUES
            (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE timestamp = NOW()
        ";
        $data = array($user['id'], $job_id, ACTION_SHARE_JOB);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
    public function hide_company() {
        $token = $this->input->get_post('token');
        $company = $this->input->get_post('company');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token, $company))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Keep record in database
        $query = "
            INSERT INTO ".TABLE_USERS_HIDDEN_COMPANIES."
            (user_id, company, timestamp)
            VALUES
            (?, ?, NOW())
            ON DUPLICATE KEY UPDATE timestamp = NOW()
        ";
        $data = array($user['id'], $company);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
    public function get_user_data() {
        $token = $this->input->get_post('token');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $profile = $this->userlib->getUserProfile($token);
        if (!$profile) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        $user_id = $profile['id'];
        $user = array();
        $user['user_id'] = $profile['id'];
        $user['name'] = $profile['name'];
        $user['email'] = $profile['email'];
        $user['profile_picture'] = USER_PROFILE_PICTURE_URL.$profile['profile_picture'];
        $user['phone'] = $profile['phone'];
        $user['language'] = $profile['language'];
        $user['current_employer'] = $profile['current_employer'];
        $user['current_employer_location'] = $profile['employer_location'];
        $user['current_job'] = $profile['current_job'];
        $user['current_salary'] = $profile['current_salary'];
		$user['current_credit'] = $profile['current_credit'];
        $user['industries'] = explode("@@", $profile['industry']);
        $user['token'] = $profile['token'];
        $user['activation_code'] = $profile['activation_code'];
        
        // Get links to profiles of the user
        $query = "
            SELECT id, link, descriptions
            FROM ".TABLE_USERS_LINKS."
            WHERE user_id = ?
        ";
        $data = array($user_id);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        $user['links'] = array();
        $i = 1; 
        foreach ($result as $row) {
			if($i==1){
				$user['links']['linkedin'] = $row;
			}else{
				$user['links']['other'] = $row;
			}
			$i++;
            //$link = $row['link'];
            // if (strpos($link, "linkedin") !== False) {
                // $user['links']['linkedin'] = $row;
            // } else {
                // $user['links']['other'] = $row;
            // }
			
        }

        if (count($result) == 0) {
            $user['links'] = array(
                    "linkedin" => array(
                            "id" => "",
                            "link" => "",
                            "descriptions" => ""
                        ),
                    "other" => array(
                            "id" => "",
                            "link" => "",
                            "descriptions" => ""
                        )
                );
        }
        
        // Get messages for job applications saved by the user
        /* $query = "
            SELECT id, message
            FROM ".TABLE_USERS_MESSAGES."
            WHERE user_id = ?
        "; */
		$query = "
            SELECT id, message
            FROM ".TABLE_USERS_MESSAGES."
            WHERE user_id = ? ORDER BY timestamp DESC LIMIT 0,1
        ";
        $data = array($user_id);
        $result = $this->db->query($query, $data);
		if($result->row()){
			$user['messages'] = $result->row();
		}else{
			$user['messages']['id'] = "";
			$user['messages']['message'] = "";
		 }
        
		// Get cv for job applications saved by the user
        $query = "
            SELECT id, cv
            FROM users_cv
            WHERE user_id = ? ORDER BY timestamp DESC LIMIT 0,1
        ";
        $data = array($user_id);
        $result = $this->db->query($query, $data);
		if($result->row()){
			 $user['cv'] = $result->row();
		 }else{
			$user['cv']['id'] = "";
			$user['cv']['cv'] = "";
		 }
        
        // Output
        $this->outputlib->output(STATUS_OK, '', $user);
        return;
    }

    // TR addition to get current credit balance 20150331
    public function get_current_credit() {
        $token = $this->input->post('token');
        
        $query = "
            SELECT
                current_credit
            FROM users
            WHERE token = ?
        ";
        $data = array($token);
        $result = $this->db->query($query, $data);
        $row = $result->row();
        
        $this->outputlib->output(STATUS_OK, '', $row->current_credit);
    }

    // TR addition to add to a user's credit amount 20150324
    // returns the new balance on the account
    public function add_credit() {
        $token = $this->input->get_post('token');
        $credit_amount = $this->input->get_post('credit_amount');
                
        // Add amount to user's existing credit amount
        $query = "
            UPDATE users
            SET
                current_credit = current_credit + ?
            WHERE
                token = ?
        ";
        $data = array($credit_amount, $token);
        $this->db->query($query, $data);

         // Output
        $query = "
            SELECT current_credit
            FROM users
            WHERE
                token = ?
        ";

        $data = array($token);
        $result = $this->db->query($query, $data);
        $row = $result->row();

        $output = array();
        $output['new_credit_balance'] = $row->current_credit;
        $this->outputlib->output(STATUS_OK, '', $output);
        return;
    }

    // TR addition to subtract from a user's credit amount 20150324
    // returns the new balance on the account
    public function subtract_credit() {
        $token = $this->input->get_post('token');
        $credit_amount = $this->input->get_post('credit_amount');

         // Current credit to check if there is enough
        $query = "
            SELECT current_credit
            FROM users
            WHERE
                token = ?
        ";

        $data = array($token);
        $result = $this->db->query($query, $data);
        $row = $result->row();
        $current_credit_balance = $row->current_credit;

        if ($credit_amount > $current_credit_balance) {
            // Not enough credit on the account
            $this->outputlib->error('Not enough credit on this account');
        } else {
            // Update user's credit amount subtracting amount from existing credit
            $query = "
                UPDATE users
                SET
                    current_credit = current_credit - ?
                WHERE
                    token = ?
            ";
            $data = array($credit_amount, $token);
            $this->db->query($query, $data);

            // Output success
            $output = array();
            $output['new_credit_balance'] = $current_credit_balance - $credit_amount;
            $this->outputlib->output(STATUS_OK, '', $output);
        }
        return;  
    }
    
	// For deactivating a user account
    // The user will not be able to login again
    public function deactivate() {
        $token = $this->input->get_post('token');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
            return;
        }
        
        // Update user status to deactivated
        $query = "
            UPDATE ".TABLE_USERS."
            SET status = 0
            WHERE token = ?
        ";
        $data = array($token);
        $this->db->query($query, $data);
        $this->outputlib->output(STATUS_OK, '',  array());
    }
    
    // For setting up a user profile by updating his CV URL
    public function update_cv() {
        $token = $this->input->get_post('token');
        $cv_url = $this->input->get_post('cv_url');
		$cv_id = $this->input->get_post('cv_id');
		
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
            return;
        }
        
        $user = $this->userlib->getUserProfile($token);
        
		if($cv_id > 0){
			 $query = "
             UPDATE users_cv
                SET
                    cv =  ?
					timestamp = NOW()
                WHERE
                    id = ?
			";
			$data = array($cv_url,$cv_id);
			$this->db->query($query, $data);
		}else{
			 $query = "
             INSERT INTO users_cv
            (user_id, cv, timestamp)
            VALUES
            (?, ?, NOW())
			";
			$data = array($user['id'], $cv_url);
			$this->db->query($query, $data);
		}
		
        
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
	// For setting up a user profile by updating his CV URL
    public function update_message() {
        $token = $this->input->get_post('token');
        $message = $this->input->get_post('message');
		$message_id = $this->input->get_post('message_id');
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
            return;
        }
        
		$user = $this->userlib->getUserProfile($token);
		
        if($message_id>0){
			 $query = "
             UPDATE users_messages
                SET
                    message =  ?
					timestamp = NOW()
                WHERE
                    id = ?
			";
			$data = array($message,$message_id);
		}else{
			 $query = "
             INSERT INTO users_messages
            (user_id, message, timestamp)
            VALUES
            (?, ?, NOW())
			";
			$data = array($user['id'], $message);
		}
       
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
	
	// For setting up a user profile by updating his CV URL
    public function get_message_list() {
        $token = $this->input->get_post('token');
		
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
            return;
        }
		
        $user = $this->userlib->getUserProfile($token);
		
        $query = "
            SELECT id, message
            FROM ".TABLE_USERS_MESSAGES."
            WHERE user_id = ?
			ORDER BY id desc
        ";
        $data = array($user['id']);
        $result = $this->db->query($query, $data);
        //$data['messages'] = $result->result_array();
		$messages = array();
		$messages = $result->result_array();
        //$data['user_id'] = $user['id'];
        // Output
        $this->outputlib->output(STATUS_OK, '', $messages);

    }
	
	// For setting up a user profile by updating his CV URL
    public function get_cv_list() {
        $token = $this->input->get_post('token');
		
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
            return;
        }
		
        $user = $this->userlib->getUserProfile($token);
		
        $query = "
            SELECT *
            FROM users_cv
            WHERE user_id = ?
			ORDER BY id desc
        ";
        $data = array($user['id']);
        $result = $this->db->query($query, $data);
        $cv = array();
        $cv = $result->result_array();
        
        // Output
        $this->outputlib->output(STATUS_OK, '',$cv);

    }
}
