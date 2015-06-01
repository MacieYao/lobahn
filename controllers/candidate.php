<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Candidate extends CI_Controller {

    public function __construct() {
        parent::__construct();
        
        // Load language file
        $lang = $this->input->get_post('lang');
        if ($lang == "tc") {
            $this->lang->load('system', 'tc');
        } else {
            $this->lang->load('system', 'en');
        }
        
        // Check token
        $token = $this->input->get_post("token");
        if (!$token) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
    }
    
    // Prepare parameters for SQL IN clause
    // (add quotes, and concat by commas)
    private function prepareInParams($array) {
        $temp = "";
        for ($i = 0; $i < count($array); $i++) {
            if ($i > 0) $temp .= ",";
            $temp .= "'".$array[$i]."'";
        }
        return $temp;
    }
    
    public function search() {
        $token = $this->input->get_post("token");
        $titles = $this->input->get_post("titles");
        $industries = $this->input->get_post("industries");
        $locations = $this->input->get_post("locations");
        $company = $this->input->get_post("company");
        $salary_min = $this->input->get_post("salary_min");
        $salary_max = $this->input->get_post("salary_max");
        
        $params = array($token, $salary_min, $salary_max);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        
        // Prepare the parameters for search
        if (!$titles) $titles = '[]';
        if (!$industries) $industries = '[]';
        if (!$locations) $locations = '[]';

        $titles = array_map("trim", json_decode($titles));
        $industries = array_map("trim", json_decode($industries));
        $locations = array_map("trim", json_decode($locations));
        sort($titles);
        sort($industries);
        sort($locations);
        
        // Generate a hash for this search
        // First sort the titles, industries and locations
        $hash = md5(
            $token.
            implode(",",$titles).
            implode(",",$industries).
            implode(",",$locations).
            $company.
            $salary_min.$salary_max
        );
        
        // Prepare for company filter condition
        if ($company) {
            $company_filter = "AND u.current_employer like '%".$company."%' ";
        } else {
            $company_filter = "";        
        }
        
        // Prepare titles filter condition
        if (count($titles) > 0) {
            $titles_filter = "WHERE title IN (".$this->prepareInParams($titles).")";
        } else {
            $titles_filter = "";
        }
        
        // Prepare locations filter condition
        if (count($locations) > 0) {
            $locations_filter = "WHERE dl.name_en IN (".$this->prepareInParams($locations).")";
        } else {
            $locations_filter = "";
        }
        
        // Prepare industries filter condition
        if (count($industries) > 0) {
            $industries_filter = "WHERE di.name_en IN (".$this->prepareInParams($industries).")";
        } else {
            $industries_filter = "";
        }
        
        $query = "
            SELECT
                u.id,
                u.name,
                u.current_salary,
                u.current_job,
                u.current_employer
            FROM
                users u,
                (
                    SELECT user_id
                    FROM
                        users_locations ul
                            LEFT OUTER JOIN data_locations dl
                            ON ul.location_code = dl.id
                    ".$locations_filter."
                ) l,
                (
                    SELECT user_id
                    FROM
                        users_industries ui
                            LEFT OUTER JOIN data_industries di
                            ON ui.industry_id = di.id
                    ".$industries_filter."
                ) i
            WHERE
                u.id = l.user_id
            AND u.id = i.user_id
            AND u.current_salary < ?
        	".$company_filter."
            LIMIT 100
        ";
        
        $data = array($salary_max);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
                
        $salary_low = 9999999;
        $salary_high = 0;
        $total = 0;
        $num_of_matches = 0;
        
        $all_titles = array_map("strtolower", $titles);
        $candidates = array();
        foreach ($result as $row) {
            $salary = $row['current_salary'];
            $job = strtolower($row['current_job']);
            
            // Check if the job titles are suitable for the candidate
            $found = false;
            if (count($all_titles) == 0) {
                $found = true;
            } else {
                foreach($all_titles as $title) {
                    if (strpos($job, $title) !== false) {
                        $found = true;
                        break;
                    }
                }
            }
            
            if ($found) {
                if ($salary < $salary_low) $salary_low = $salary;
                if ($salary > $salary_high) $salary_high = $salary;
                $total += $salary;
                $num_of_matches++;
                
                // Store the candidate
                $row['profile_picture'] = DEFAULT_PROFILE_PICTURE;
                $candidates[] = $row;
            }
        }
        
        if ($num_of_matches == 0) {
            $salary_low = 0;
            $salary_high = 0;
            $salary_avg = 0;
        } else {
            $salary_avg = intval($total * 1.0 / $num_of_matches);
        }
        
        // Insert result into cache table
        $query = "
            INSERT INTO ".TABLE_CACHE_CANDIDATE_SEARCH."
            (
                user_id, timestamp,
                titles, industries, locations, salary_min, salary_max,
                hash,
                num_of_matches, results, salary_low, salary_avg, salary_high
            ) VALUES
            (
                ?, NOW(),
                ?, ?, ?, ?, ?,
                ?,
                ?, ?, ?, ?, ?
            )
        ";
        $data = array(
            $user['id'],
            implode(",",$titles), implode(",",$industries), implode(",", $locations),
            $salary_min, $salary_max,
            $hash,
            $num_of_matches, json_encode($candidates),
            $salary_low, $salary_avg, $salary_high
        );
        $this->db->query($query, $data);
        $search_id = $this->db->insert_id();
        
        // Output results as HTML if it is a demo
        if ($this->input->get_post('action') == "demo") {
            echo "<style>* { font-family: \"Arial\"; font-size: 14px; }</style>";
            echo "<b>Search Results</b><br/><br/>";
            echo "Number of Matches: ".$num_of_matches."<br/>";
            echo "Salary (Low): HKD ".$salary_low."<br/>";
            echo "Salary (Avg): HKD ".$salary_avg."<br/>";
            echo "Salary (High): HKD ".$salary_high."<br/><br/>";
            $i = 1;
            foreach ($candidates as $c) {
                echo "<b>".$i.". ".$c['name']."</b><br/>";
                echo "Current Job: ".$c['current_job']."<br/>";
                echo "Current Employer: ".$c['current_employer']."<br/>";
                echo "Current Salary: HKD ".$c['current_salary']."<br/><br/>";
                $i++;
            }
            return;
        }
        
        // Output result
        $output = array();
        $output['search_id'] = strval($search_id);
        $output['num_of_matches'] = strval($num_of_matches);
        $output['salary_low'] = strval($salary_low);
        $output['salary_avg'] = strval($salary_avg);
        $output['salary_high'] = strval($salary_high);
        $output['candidates'] = $candidates;
        
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
    
    public function hide_candidate() {
        $token = $this->input->get_post('token');
        $candidate_id = $this->input->get_post("candidate_id");
        
        $params = array($candidate_id);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        
        // Keep record in database
        $query = "
            INSERT INTO ".TABLE_CANDIDATE_ACTIONS."
            (user_id, candidate_id, action, timestamp)
            VALUES
            (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE timestamp = NOW()
        ";
        $data = array($user['id'], $candidate_id, ACTION_HIDE_CANDIDATE);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
    public function save_candidate() {
        $token = $this->input->get_post('token');
        $candidate_id = $this->input->get_post("candidate_id");
        
        $params = array($candidate_id);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        
        // Keep record in database
        $query = "
            INSERT INTO ".TABLE_CANDIDATE_ACTIONS."
            (user_id, candidate_id, action, timestamp)
            VALUES
            (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE timestamp = NOW()
        ";
        $data = array($user['id'], $candidate_id, ACTION_SAVE_CANDIDATE);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
    public function remove_saved_candidate() {
        $token = $this->input->get_post('token');
        $candidate_id = $this->input->get_post("candidate_id");
        
        $params = array($candidate_id);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        
        // Keep record in database
        $query = "
            DELETE FROM ".TABLE_CANDIDATE_ACTIONS."
            WHERE
                user_id = ?
            AND candidate_id = ?
            AND action = ?
        ";
        $data = array($user['id'], $candidate_id, ACTION_SAVE_CANDIDATE);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
    
    
    public function get_saved_candidates() {
        $token = $this->input->get_post('token');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Retrieve saved candidates
        $query = "
            SELECT
                xcandidates.*
            FROM
                (
                    SELECT *
                    FROM ".TABLE_CANDIDATE_ACTIONS."
                    WHERE 
                        action = ".ACTION_SAVE_CANDIDATE."
                    AND user_id = ?
                ) saved
                    JOIN
                (
                    SELECT
                        id,name,profile_picture,current_job,current_employer,current_salary
                    FROM
                       users
                ) xcandidates
                    ON xcandidates.id = saved.candidate_id
            ORDER BY saved.timestamp DESC
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $output = array();
		foreach ($results as $row) {
            
            // Prepare the location information of the candidate
            // if ($row['location'] == "") {
                // $row['location'] = array();
            // } else {
                // $row['location'] = explode("##", $row['location']);
            // }
            
            // Prepare the profile picture of the user
            if ($row['profile_picture'] == "") {
                $row['profile_picture'] = DEFAULT_PROFILE_PICTURE;
            } else {
                $row['profile_picture'] = USER_PROFILE_PICTURE_URL.$row['profile_picture'];
            }
            
            $output[] = $row;
        }
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
    
    public function get_details() {
        $token = $this->input->get_post("token");
        $candidate_id = $this->input->get_post('candidate_id');
        $user = $this->userlib->getUserProfile($token);
        
        $params = array($candidate_id);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        // Get details of the candidate
        $query = "
            SELECT
                u.id,
                u.name,
                u.email,
                u.current_job,
                u.current_employer,
                u.current_salary,
                GROUP_CONCAT(
                    IF(dloc.name_en IS NULL, '', dloc.name_en) SEPARATOR '##') AS locations
            FROM
                (
                    SELECT *
                    FROM ".TABLE_USERS."
                    WHERE id = ?
                ) u
                LEFT OUTER JOIN ".TABLE_USERS_LOCATIONS." uloc
                    ON u.id = uloc.user_id
                    LEFT OUTER JOIN ".TABLE_DATA_LOCATIONS." dloc
                        ON uloc.location_code = dloc.id
            GROUP BY u.id
        ";
        $data = array($candidate_id);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        if (count($results) == 0) {
            $this->outputlib->error($this->lang->line('error_no_such_candidate'));
            return;
        }
        $candidate = $results[0];
        
        // Check if the candidate is saved
        $query = "
            SELECT *
            FROM ".TABLE_CANDIDATE_ACTIONS."
            WHERE
                user_id = ?
            AND candidate_id = ?
            AND action = ".ACTION_SAVE_CANDIDATE."
        ";
        $data = array($user['id'], $candidate_id);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $candidate['is_saved'] = "0";
        if (count($results) > 0) {
            $candidate['is_saved'] = "1";
        }
        
        if ($candidate['locations'] == "") {
            $candidate['locations'] = array();
        } else {
            $candidate['locations'] = explode("##", $candidate['locations']);
        }
        
        $candidate['profile_picture'] = DEFAULT_PROFILE_PICTURE;
        

        $this->outputlib->output(STATUS_OK, '', $candidate);
    }
    
    
    // Create a new candidate feed
    public function create_feed() {
        $token = $this->input->get_post("token");
        $titles = $this->input->get_post("titles");
        $industries = $this->input->get_post("industries");
        $locations = $this->input->get_post("locations");
        $company = $this->input->get_post("company");
        $salary_min = $this->input->get_post("salary_min");
        $salary_max = $this->input->get_post("salary_max");
        
        $params = array($token, $salary_min, $salary_max);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        
        // Prepare the parameters for search
        if (!$titles) $titles = '[]';
        if (!$industries) $industries = '[]';
        if (!$locations) $locations = '[]';

        $titles = array_map("trim", json_decode($titles));
        $industries = array_map("trim", json_decode($industries));
        $locations = array_map("trim", json_decode($locations));
        sort($titles);
        sort($industries);
        sort($locations);
                
        // Generate a hash for this search
        // First sort the titles, industries and locations
        $hash = md5(
            $token.
            implode(",",$titles).
            implode(",",$industries).
            implode(",",$locations).
            $company.
            $salary_min.$salary_max
        );
        
        // Check if the search has already been done
        $query = "
            SELECT *
            FROM ".TABLE_CACHE_CANDIDATE_SEARCH."
            WHERE
                user_id = ?
            AND hash = ?
        ";
        $data = array($user['id'], $hash);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        // If there is previous result, retrieve it and skip querying DB
        if (count($results) > 0) {
            $row = $results[0];
            $feed_id = $results[0]['id'];
            $salary_min = $results[0]['salary_min'];
            $salary_max = $results[0]['salary_max'];

			$output = array();
			$output['search_id'] = strval($row['id']);
			$output['num_of_matches'] = strval($row['num_of_matches']);
			$output['salary_low'] = strval($row['salary_low']);
			$output['salary_avg'] = strval($row['salary_avg']);
			$output['salary_high'] = strval($row['salary_high']);
            $output['results'] = json_decode($row['results']);
        	
	        $this->outputlib->output(STATUS_OK, '', $output);
        
        // Else, query DB with the search parameters
        } else {
            
            // Prepare for company filter condition
            if ($company) {
                $company_filter = "AND u.current_employer like '%".$company."%' ";
            } else {
                $company_filter = "";        
            }
            
            // Prepare titles filter condition
            if (count($titles) > 0) {
                $titles_filter = "WHERE title IN (".$this->prepareInParams($titles).")";
            } else {
                $titles_filter = "";
            }
            
            // Prepare locations filter condition
            if (count($locations) > 0) {
                $locations_filter = "WHERE dl.name_en IN (".$this->prepareInParams($locations).")";
            } else {
                $locations_filter = "";
            }
            
            // Prepare industries filter condition
            if (count($industries) > 0) {
                $industries_filter = "WHERE di.name_en IN (".$this->prepareInParams($industries).")";
            } else {
                $industries_filter = "";
            }
            
            $query = "
                SELECT
                    u.id,
                    u.name,
                    u.current_salary,
                    u.current_job,
                    u.current_employer
                FROM
                    users u,
                    (
                        SELECT user_id
                        FROM
                            users_locations ul
                                LEFT OUTER JOIN data_locations dl
                                ON ul.location_code = dl.id
                        ".$locations_filter."
                    ) l,
                    (
                        SELECT user_id
                        FROM
                            users_industries ui
                                LEFT OUTER JOIN data_industries di
                                ON ui.industry_id = di.id
                        ".$industries_filter."
                    ) i
                WHERE
                    u.id = l.user_id
                AND u.id = i.user_id
                AND u.current_salary < ?
                ".$company_filter."
                LIMIT 100
            ";
            
            $data = array($salary_max);
            $result = $this->db->query($query, $data);
            $result = $result->result_array();
                    
            $salary_low = 9999999;
            $salary_high = 0;
            $total = 0;
            $num_of_matches = 0;
            
            $all_titles = array_map("strtolower", $titles);
            $candidates = array();
            foreach ($result as $row) {
                $salary = $row['current_salary'];
                $job = strtolower($row['current_job']);
                
                // Check if the job titles are suitable for the candidate
                $found = false;
                if (count($all_titles) == 0) {
                    $found = true;
                } else {
                    foreach($all_titles as $title) {
                        if (strpos($job, $title) !== false) {
                            $found = true;
                            break;
                        }
                    }
                }
                
                if ($found) {
                    if ($salary < $salary_low) $salary_low = $salary;
                    if ($salary > $salary_high) $salary_high = $salary;
                    $total += $salary;
                    $num_of_matches++;
                    
                    // Store the candidate
                    $row['profile_picture'] = DEFAULT_PROFILE_PICTURE;
                    $candidates[] = $row;
                }
            }
            
            if ($num_of_matches == 0) {
                $salary_low = 0;
                $salary_high = 0;
                $salary_avg = 0;
            } else {
                $salary_avg = intval($total * 1.0 / $num_of_matches);
            }
            
            // Insert result into cache table
            $query = "
                INSERT INTO ".TABLE_CACHE_CANDIDATE_SEARCH."
                (
                    user_id, timestamp,
                    titles, industries, locations, salary_min, salary_max,
                    hash,
                    num_of_matches, results, salary_low, salary_avg, salary_high
                ) VALUES
                (
                    ?, NOW(),
                    ?, ?, ?, ?, ?,
                    ?,
                    ?, ?, ?, ?, ?
                )
            ";
            $data = array(
                $user['id'],
                implode(",",$titles), implode(",",$industries), implode(",", $locations),
                $salary_min, $salary_max,
                $hash,
                $num_of_matches, json_encode($candidates),
                $salary_low, $salary_avg, $salary_high
            );
            $this->db->query($query, $data);
            $search_id = $this->db->insert_id();
            
            // Output results as HTML if it is a demo
            if ($this->input->get_post('action') == "demo") {
                echo "<style>* { font-family: \"Arial\"; font-size: 14px; }</style>";
                echo "<b>Search Results</b><br/><br/>";
                echo "Number of Matches: ".$num_of_matches."<br/>";
                echo "Salary (Low): HKD ".$salary_low."<br/>";
                echo "Salary (Avg): HKD ".$salary_avg."<br/>";
                echo "Salary (High): HKD ".$salary_high."<br/><br/>";
                $i = 1;
                foreach ($candidates as $c) {
                    echo "<b>".$i.". ".$c['name']."</b><br/>";
                    echo "Current Job: ".$c['current_job']."<br/>";
                    echo "Current Employer: ".$c['current_employer']."<br/>";
                    echo "Current Salary: HKD ".$c['current_salary']."<br/><br/>";
                    $i++;
                }
                return;
            }
            
            // Output result
            $output = array();
            $output['feed_id'] = strval($search_id);
            $output['num_of_matches'] = strval($num_of_matches);
            $output['salary_low'] = strval($salary_low);
            $output['salary_avg'] = strval($salary_avg);
            $output['salary_high'] = strval($salary_high);
            $output['candidates'] = $candidates;
            
            $this->outputlib->output(STATUS_OK, '', $output);

        }
    }
    
    // Remove a feed if the user no longer needs it
    public function remove_feed() {
        $token = $this->input->get_post('token');
        $feed_id = $this->input->get_post('feed_id');
        
        // Check if essential parameters are provided
        $params = array($token, $feed_id);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        $feed_arr = array();
		$feed_arr = explode(",",$feed_id);
        /*$query = "
            UPDATE ".TABLE_CACHE_CANDIDATE_SEARCH."
            SET active = 0
            WHERE
                user_id = ?
            AND id = ?
        ";*/
		$query = "
            UPDATE jobs
            SET status = 0
            WHERE
                user_id = ?
            AND id = ?
        ";
		if($feed_arr){
			foreach($feed_arr as $key=>$val){
				$data = array($user['id'], $val);
                $this->db->query($query, $data);
			}
		}
        
        
        $this->outputlib->output(STATUS_OK, '', array());
        return;
    }

    // Get the candidates in a feed
    public function get_feed_candidates() {
        $token = $this->input->get_post('token');
        $user = $this->userlib->getUserProfile($token);

        $feed_id = $this->input->get_post('feed_id');
        
        // Check if essential parameters are provided
        $params = array($token, $feed_id);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $query = "
            SELECT results
            FROM ".TABLE_CACHE_CANDIDATE_SEARCH."
            WHERE
                user_id = ?
            AND id = ?
            AND active = 1
        ";
        $data = array($user['id'], $feed_id);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        
        if (count($result) == 0) {
            $this->outputlib->error($this->lang->line('error_no_such_feed'));
        }
        
        $output = $result[0];
        if ($output['results'] == "") {
			$output['results'] = array();
		} else {
			$output['results'] = json_decode($output['results']);
		}
		
        $this->outputlib->output(STATUS_OK, '', $output);
    }

    // Retrieve a list of candidate feeds for this user
    public function get_feeds() {
        $token = $this->input->get_post('token');
        $user = $this->userlib->getUserProfile($token);
        
        $query = "
            SELECT
                id AS feed_id,
                title,
                industry,
                location,
                salary_min,
                salary_max,
                post_date
            FROM jobs
            WHERE
                user_id = ? and status = 1
            ORDER BY id DESC
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $output = array();
        foreach ($results as $row) {
			$startdate=strtotime($row['post_date']);
			$enddate=strtotime(date('y-m-d h:i:s',time()));
			$days=round(($enddate-$startdate)/86400)+1;
			if($days<=30){
				$row['titles'] = $row['title'];
				$row['industries'] = $row['industry'];
				$row['locations'] = $row['location'];
				$row['days']=30-$days;
				$output[] = $row;
			}
        }

        $this->outputlib->output(STATUS_OK, '', $output);
        return;
    }

    // API for retrieving a list of new candidates
    public function get_new_candidates() {
        $token = $this->input->get_post('token');   
        $user = $this->userlib->getUserProfile($token);
        
        $query = "
            SELECT
                candidates.id,
                candidates.name,
                candidates.current_salary,
                candidates.current_job,
                candidates.current_employer,
                candidates.profile_picture,
                GROUP_CONCAT(dl.name_en SEPARATOR '##') AS location
            FROM
                (
                    SELECT
                        c.user_id,
                        c.create_time
                    FROM
                        ".TABLE_USERS_NEW_CANDIDATES." AS c
                        LEFT OUTER JOIN ".TABLE_CANDIDATE_ACTIONS." AS ca
                            ON (
                                c.candidate_id = ca.candidate_id
                            AND ca.user_id = ?
                            )
                    WHERE
                        IF(ca.action IS NULL, 0, ca.action) != 2
                ) new_candidates
                    LEFT OUTER JOIN ".TABLE_USERS." candidates
                    ON new_candidates.user_id = candidates.id
                        
                        LEFT OUTER JOIN ".TABLE_USERS_LOCATIONS." ul
                            ON ul.user_id = candidates.id
                            LEFT OUTER JOIN ".TABLE_DATA_LOCATIONS." dl
                                ON ul.location_code = dl.id
            
            GROUP BY candidates.id
            ORDER BY new_candidates.create_time DESC
        ";
        
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $output = array();
        foreach ($results as $row) {
            
            // Prepare the location information of the candidate
            if ($row['location'] == "") {
                $row['location'] = array();
            } else {
                $row['location'] = explode("##", $row['location']);
            }
            
            // Prepare the profile picture of the user
            if ($row['profile_picture'] == "") {
                $row['profile_picture'] = DEFAULT_PROFILE_PICTURE;
            } else {
                $row['profile_picture'] = USER_PROFILE_PICTURE_URL.$row['profile_picture'];
            }
            
            $output[] = $row;
        }
        
        $this->outputlib->output(STATUS_OK, '', $output);
    }

	public function get_remove_candidates() {
        $token = $this->input->get_post('token');
        
        // Check parameters
        if ($this->helperlib->hasEmptyParams(array($token))) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        $user = $this->userlib->getUserProfile($token);
        if (!$user) {
            $this->outputlib->error($this->lang->line('error_token_not_exists'));
        }
        
        // Retrieve saved candidates
        $query = "
            SELECT
                xcandidates.*
            FROM
                (
                    SELECT *
                    FROM ".TABLE_CANDIDATE_ACTIONS."
                    WHERE 
                        action = ".ACTION_HIDE_CANDIDATE."
                    AND user_id = ?
                ) saved
                    JOIN
                (
                    SELECT
                        id,name,profile_picture,current_job,current_employer,current_salary
                    FROM
                       users
                ) xcandidates
                    ON xcandidates.id = saved.candidate_id
            ORDER BY saved.timestamp DESC
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $output = array();
		foreach ($results as $row) {
            
            // Prepare the location information of the candidate
            // if ($row['location'] == "") {
                // $row['location'] = array();
            // } else {
                // $row['location'] = explode("##", $row['location']);
            // }
            
            // Prepare the profile picture of the user
            if ($row['profile_picture'] == "") {
                $row['profile_picture'] = DEFAULT_PROFILE_PICTURE;
            } else {
                $row['profile_picture'] = USER_PROFILE_PICTURE_URL.$row['profile_picture'];
            }
            
            $output[] = $row;
        }
        $this->outputlib->output(STATUS_OK, '', $output);
    }
	
	public function remove_removed_candidate() {
        $token = $this->input->get_post('token');
        $candidate_id = $this->input->get_post("candidate_id");
        
        $params = array($candidate_id);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        
        // Keep record in database
        $query = "
            DELETE FROM ".TABLE_CANDIDATE_ACTIONS."
            WHERE
                user_id = ?
            AND candidate_id = ?
            AND action = ?
        ";
        $data = array($user['id'], $candidate_id, ACTION_HIDE_CANDIDATE);
        $this->db->query($query, $data);
        
        $this->outputlib->output(STATUS_OK, '', array());
    }
}
