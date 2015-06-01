<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Job extends CI_Controller {

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
        
        $this->loglib->log(0);
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
    
    
    // Job search function
    public function search() {
        $uuid = $this->input->get_post('uuid');    
        $token = $this->input->get_post('token');
        $titles = $this->input->get_post('titles');
        $industries = $this->input->get_post('industries');
        $locations = $this->input->get_post('locations');
        $target_salary = $this->input->get_post('target_salary');
        $company = $this->input->get_post('company');
        $offset = $this->input->get_post('offset');
        $limit = $this->input->get_post('limit');
		$all = $this->input->get_post('all');
        
        // Check if essential parameters are provided
        $params = array($token, $target_salary);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        if (!$offset) $offset = 0;
        if (!$limit) $limit = SEARCH_PAGE_SIZE;
        
        $user = $this->userlib->getUserProfile($token);
        
        // Get the saved jobs for this user
        $query = "
            SELECT job_id
            FROM action_jobs
            WHERE
                action = 1
            AND user_id = ?
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        $saved_jobs = array();
        foreach ($results as $row) {
            $saved_jobs[$row['job_id']] = 1;
        }
        
        // Prepare the parameters for search
        if (!$titles) $titles = "[]";
        if (!$industries) $industries = "[]";
        if (!$locations) $locations = "[]";

        $titles = array_map("trim", json_decode($titles));
        $industries = array_map("trim", json_decode($industries));
        $locations = array_map("trim", json_decode($locations));

        sort($titles);
        sort($industries);
        sort($locations);
        
        // Clean target salary
        $target_salary = str_replace("$", "", $target_salary);
        $target_salary = str_replace(",", "", $target_salary);
        
        if (!is_numeric($target_salary)) {
            $target_salary = 0;
        }
        
        // Generate a hash for this search
        // First sort the titles, industries and locations
        $hash = md5(
            $token.
            implode(",",$titles).
            implode(",",$industries).
            implode(",",$locations).
            $company.
            $target_salary
        );
        
        // Check if the search has already been done
        $query = "
            SELECT *
            FROM ".TABLE_CACHE_JOB_SEARCH."
            WHERE
                user_id = ?
            AND hash = ?
        ";
        $data = array($user['id'], $hash);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        // If there is previous result, retrieve it and skip querying DB
        if (count($results) > 0) {
            $salary_min = $results[0]['salary_min'];
            $salary_max = $results[0]['salary_max'];
            $jobs = json_decode($results[0]['results'], true);
        
        // Else, query DB with the search parameters
        } else {
            
            // Prepare company filter condition
            if ($company) {
                $company_filter = "AND jobs.company = '".$company."' ";
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
                $locations_filter = "WHERE name_en IN (".$this->prepareInParams($locations).")";
            } else {
                $locations_filter = "";
            }
            
            // Prepare industries filter condition
            if (count($industries) > 0) {
			if($all==1){
					$industries_filter = "";
				}else{
					$industries_filter = "AND jobs.industry IN (".$this->prepareInParams($industries).")";
				}
                
            } else {
                $industries_filter = "";
            }
            
            $query = "
                SELECT
                    xjobs.*
                FROM
                    (
                        SELECT *
                        FROM jobs_title_to_jobsid
                        ".$titles_filter."
                    ) xtitles
                        JOIN
                    (
                        SELECT
                            jobs.id,
                            jobs.title,
                            jobs.company,
                            jobs.salary_min AS salary,
                            jobs.industry,
                            jobs.post_date AS date_post,
                            jobs.show_salary AS show_salary,
                            xlocs.name_en AS location
                        FROM
                            (
                                SELECT id, name_en
                                FROM data_locations
                                ".$locations_filter."
                            ) xlocs
                                LEFT OUTER JOIN jobs_location_code xlcodes
                                ON xlocs.id = xlcodes.location_code
                                    LEFT OUTER JOIN jobs jobs
                                    ON xlcodes.job_id = jobs.id
										LEFT OUTER JOIN ".TABLE_JOB_ACTIONS." ja 
										ON jobs.id = ja.job_id
                        WHERE
						jobs.salary_min >= ".$target_salary."
                        ".$industries_filter."
                        ".$company_filter."
                    ) xjobs
                        ON xjobs.id = xtitles.job_id
                ORDER BY xjobs.salary DESC
                LIMIT 100;
            ";

            $results = $this->db->query($query);
            $results = $results->result_array();
            
            $salary_min = 0;
            $salary_max = 0;
            if (count($results) > 0) {
                $salary_min = 99999999;
                $salary_max = 0;
                foreach ($results as $row) {
                    if ($row['salary'] <= $salary_min) $salary_min = $row['salary'];
                    if ($row['salary'] >= $salary_max) $salary_max = $row['salary'];
                }
            }
            $hiddenCompanies = $this->getHiddenCompanies($user['id']);
            
            $jobs = array();
            foreach ($results as $row) {
				if (!in_array($row['company'], $hiddenCompanies)) {
					$row['company_logo'] = DEFAULT_COMPANY_LOGO;
					$row['branch'] = '';
					$row['summary'] = '...';
					$row['date_due'] = '2015-06-30';
					if ($row['show_salary'] == 0){
						$row['salary'] = '0';
					}
                    
                    if (array_key_exists($row['id'], $saved_jobs)) {
                        $row['is_saved'] = "1";
                    } else {
                        $row['is_saved'] = "0";
                    }
                    $row['is_new'] = "0";
                    
					$jobs[] = $row;
				}
            }
            
            // Insert results into the cache table
            $query = "
                INSERT INTO ".TABLE_CACHE_JOB_SEARCH."
                (
                    user_id, timestamp, hash,
                    titles, industries, locations, company, target_salary,
                    results, salary_min, salary_max
                )
                VALUES
                (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $data = array(
                $user['id'], $hash,
                implode(",",$titles), implode(",",$industries), implode(",", $locations), $company, $target_salary,
                json_encode($jobs), $salary_min, $salary_max);
            $this->db->query($query, $data);
        }
        
        // Return results based on offset and limit
        $jobs = array_slice($jobs, $offset, $limit);
        
        // Return results for demo
        if ($this->input->get_post('action') == "demo") {
            echo "<style>* { font-family: \"Arial\"; font-size: 14px; }</style>";
            echo "<b>Search Results</b><br/><br/>";
            echo "Salary (Min): HKD ".$salary_min."<br/>";
            echo "Salary (Max): HKD ".$salary_max."<br/><br/>";
            $i = 1;
            foreach ($jobs as $job) {
                echo "<b>".$i.". (".$job['id'].") ".$job['title']."</b><br/>";
                echo "Industry: ".$job['industry']."<br/>";
                echo "Company: ".$job['company']."<br/>";
                echo "Salary: HKD ".$job['salary']."<br/>";
                echo "Location: ".$job['location']."<br/>";
                echo "Show Salary: ".$job['show_salary']."</br></br>";
                $i++;
            }
            return;
        }
        
        $login = $this->userlib->isLoggedIn($user['id'], $uuid);        
        
        $output = array();
        $output['salary_min'] = strval($salary_min);
        $output['salary_max'] = strval($salary_max);
	    //$output['jobs'] = $login ? $jobs : array();
        $output['jobs'] = $jobs;
	    $output['num_of_jobs'] = count($jobs);
        $this->outputlib->output(STATUS_OK, '', $output);
    }

	function getHiddenCompanies($user_id){
		// Get number of jobs    
    	$query = "	SELECT company
    				FROM ".TABLE_USERS_HIDDEN_COMPANIES."
    				WHERE user_id = ?
    				";
    	$data = array($user_id); 
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
	
		$output = array();
		foreach ($results as $row){
			array_push($output, $row['company']);
		}
		
		return $output;
	}

    // API for retrieving the number of updates
    public function get_num_of_updates(){
        $token = $this->input->get_post("token");
        $user = $this->userlib->getUserProfile($token);
    
		// Get number of jobs    
    	$query = "
            SELECT count(*) AS cnt
    		FROM ".TABLE_USERS_NEW_JOBS."
    		WHERE user_id = ?
    	";
    	$data = array($user['id']); 
        $results_jobs = $this->db->query($query, $data);
        $results_jobs = $results_jobs->result_array();

		// Get number of candidates
    	$query = "
            SELECT count(*) AS cnt
    		FROM ".TABLE_USERS_NEW_CANDIDATES."
            WHERE user_id = ?
    	";
    	$data = array($user['id']);
        $results_candidates = $this->db->query($query, $data);
        $results_candidates = $results_candidates->result_array();

    	// Output
        $output = array();
        $output["num_of_updates"] = $results_jobs[0]['cnt'] + $results_candidates[0]['cnt'];
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
    // API for retrieving a summary of new candidates and jobs
    public function get_summary_of_updates(){
        $token = $this->input->get_post("token");
        $user = $this->userlib->getUserProfile($token);
    
		// Get number of jobs    
    	$query = "	SELECT keyword, count(*) as number, search_id
    				FROM ".TABLE_USERS_NEW_JOBS."
    				WHERE user_id = ?
    				GROUP BY keyword
    				";
    	$data = array($user['id']); 
        $results_jobs = $this->db->query($query, $data);
        $results_jobs = $results_jobs->result_array();

		$jobs = array();
		foreach ($results_jobs as $row) {
			$item = array();
			$item['title'] = $row["keyword"];
			$item['number'] = $row["number"];
			$item['search_id'] = $row["search_id"];
			array_push($jobs, $item);
		}

		// Get number of candidates
    	$query = "	SELECT keyword, count(*) as number, search_id
    				FROM ".TABLE_USERS_NEW_CANDIDATES."
    				WHERE user_id = ?
    				GROUP BY keyword
    				";
    	$data = array($user['id']);
        $results_candidates = $this->db->query($query, $data);
        $results_candidates = $results_candidates->result_array();

		$candidates = array();
		foreach ($results_candidates as $row) {
			$item = array();
			$item['title'] = $row["keyword"];
			$item['number'] = $row["number"];
			$item['search_id'] = $row["search_id"];
			array_push($candidates, $item);
		}

    	// Output
        $output = array();
        $output["jobs"] = $jobs;
        $output["candidates"] = $candidates;
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
    // API for retrieving a list of new jobs
    // (Call this API after a notification is pushed to the app)
    public function get_new_jobs() {
        $search_id = $this->input->get_post("search_id");       
        
        $query = "
            SELECT
                j.id,
                j.title,
                j.company,
                j.salary_min AS salary,
                j.show_salary,
                j.industry,
                j.post_date AS date_post,
                GROUP_CONCAT(dloc.name_en SEPARATOR ', ') AS location
            FROM
                (
                    SELECT xj.*
                    FROM
                        ".TABLE_USERS_NEW_JOBS." xj
                        LEFT OUTER JOIN ".TABLE_JOB_ACTIONS." ja
                            ON xj.job_id = ja.job_id
                    WHERE
                        IF(ja.action IS NULL, 0, ja.action) != 2
                ) nj
                    LEFT OUTER JOIN jobs j
                    ON nj.job_id = j.id
                        LEFT OUTER JOIN jobs_location_code loc
                        ON j.id = loc.job_id
                            LEFT OUTER JOIN data_locations dloc
                            ON loc.location_code = dloc.id
            WHERE
                DATE(nj.create_time) = DATE(NOW())
            GROUP BY j.id
            ORDER BY nj.create_time DESC
        ";
        $data = array($search_id);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $salary_min = 0;
        $salary_max = 0;
        if (count($results) > 0) {
            $salary_min = 99999999;
            $salary_max = 0;
            foreach ($results as $row) {
                if ($row['salary'] <= $salary_min) $salary_min = $row['salary'];
                if ($row['salary'] >= $salary_max) $salary_max = $row['salary'];
            }
        }
        
        $jobs = array();
        foreach ($results as $row) {
            $row['company_logo'] = DEFAULT_COMPANY_LOGO;
            $row['branch'] = '';
            $row['summary'] = '...';
            $row['date_due'] = '2015-06-30';
            $jobs[] = $row;
        }
        
        $output = array();
        $output['salary_min'] = strval($salary_min);
        $output['salary_max'] = strval($salary_max);
        $output['jobs'] = $jobs;
        $this->outputlib->output(STATUS_OK, '', $output);
    }
        
    
    public function get_details() {
        $job_id = $this->input->get_post('job_id');
        $token = $this->input->get_post('token');

        $params = array($job_id);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        
        // Check whether the job has been saved by the user
        $query = "
            SELECT *
            FROM action_jobs
            WHERE
                action = 1
            AND user_id = ?
            AND job_id = ?
        ";
        $data = array($user['id'], $job_id);
        $results = $this->db->query($query, $data);
        if ($results->num_rows() > 0) {
            $is_saved = "1";
        } else {
            $is_saved = "0";
        }
        
        // Check whether this job is new
        $is_new = "0";
        
		// Get details of the job
    	$query = "
			SELECT
				j.id,
				j.title,
				j.industry,
				j.company,
				IF(com.descriptions IS NULL, '', com.descriptions) AS company_description,
				IF(com.logo IS NULL, '', com.logo) AS company_logo,
				'' AS branch,
				j.location,
				j.salary_min,
				j.salary_max,
                j.show_salary,
				j.link,
				j.short_desc,
				j.post_date AS date_post,
				'' AS date_due,
				j.status
    		FROM
				".TABLE_JOBS." AS j
				LEFT OUTER JOIN companies com
					ON j.company = com.name
    		WHERE j.id = ?
    	";
    	$data = array($job_id);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
		
        if (count($results) == 0) {
            $this->outputlib->error($this->lang->line('message_no_such_job'));
            return;
        }
		$job = $results[0];
		
		$job["salary"] = "$".$job["salary_min"]."-$".$job["salary_max"];
		unset($job["salary_min"]);
		unset($job["salary_max"]);
        if ($job["show_salary"] == 0) {
            $job["salary"] = 0;
        } 
		
		if ($job["company_logo"] == "") {
			$job["company_logo"] = DEFAULT_COMPANY_LOGO;
		}
		
		$job["is_saved"] = $is_saved;
		$job["is_new"] = 0;
		
        $this->outputlib->output(STATUS_OK, '', $job);
    }
    
    public function get_job_titles () {
        $lang = $this->input->get_post('lang');
        $keyword = $this->input->get_post('keyword');
        $keyword = trim(strtoupper($keyword));
        

        $query = "
            SELECT title
            FROM ".TABLE_JOB_TITLE_INDEX."
            WHERE keyword = ?
            ORDER BY prob DESC
       
        ";
        $data = array($keyword);
        $rows = $this->db->query($query, $data);
        $rows = $rows->result_array();
        
        $output = array();
        foreach ($rows as $row) {
            $output[] = $row['title'];
        }
        
        $this->outputlib->output(STATUS_OK, '', $output);
        return;
    }
	
	public function get_all_job_titles () {
         $lang = $this->input->get_post('lang');
        $query = "
            SELECT title
            FROM ".TABLE_JOB_TITLE_INDEX."
            ORDER BY prob DESC
			
        ";
		
        $rows = $this->db->query($query);
        $rows = $rows->result_array();
        
        $output = array();
        foreach ($rows as $row) {
            $output[] = $row['title'];
        }
        $this->outputlib->output(STATUS_OK, '',$output);
    }
    
    public function get_job_companies () {
        $lang = $this->input->get_post('lang');
        $keyword = $this->input->get_post('keyword');
        $keyword = trim(strtoupper($keyword));
        
        $query = "
            SELECT company
            FROM ".TABLE_JOB_COMPANY_INDEX."
            WHERE keyword = ?
            ORDER BY prob DESC
            LIMIT 20
        ";
        $data = array($keyword);
        $rows = $this->db->query($query, $data);
        $rows = $rows->result_array();
        
        $output = array();
        foreach ($rows as $row) {
            $output[] = $row['company'];
        }
        
        $this->outputlib->output(STATUS_OK, '', $output);
        return;
    }
    
    
    public function get_industries() {
        $lang = $this->input->get_post('lang');
        $keyword = $this->input->get_post('keyword');
        $keyword = trim(strtoupper($keyword));
        
        $query = "  
            SELECT industry
            FROM ".TABLE_JOB_INDUSTRY_INDEX."
            WHERE keyword = ?
            ORDER BY
                score DESC,
                industry ASC
            LIMIT 20
        ";
        $data = array($keyword);
        $rows = $this->db->query($query, $data);
        $rows = $rows->result_array();
        
        $output = array();
        foreach ($rows as $row) {
            $output[] = $row['industry'];
        }
        
        $this->outputlib->output(STATUS_OK, '', $output);
        return;
    }
    
    public function get_locations() {
        $lang = $this->input->get_post('lang');
        $query = "
            SELECT *
            FROM ".TABLE_DATA_LOCATIONS."
            ORDER BY id ASC
        ";
        $rows = $this->db->query($query);
        $rows = $rows->result_array();
        
        $output = array();
        foreach ($rows as $row) {
            $output[] = $row['name_'.$lang];
        }
        
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
    
    // ======================================================
    // New APIs for feed-based job search
    
    // Create a new job feed
    public function create_feed() {
        $token = $this->input->get_post('token');
        $titles = $this->input->get_post('titles');
        $industries = $this->input->get_post('industries');
        $locations = $this->input->get_post('locations');
        $target_salary = $this->input->get_post('target_salary');
        $company = $this->input->get_post('company');
        
        // Check if essential parameters are provided
        $params = array($token, $target_salary);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        
        // Prepare the parameters for search
        if (!$titles) $titles = "[]";
        if (!$industries) $industries = "[]";
        if (!$locations) $locations = "[]";

        $titles = array_map("trim", json_decode($titles));
        $industries = array_map("trim", json_decode($industries));
        $locations = array_map("trim", json_decode($locations));
        sort($titles);
        sort($industries);
        sort($locations);
        
        // Clean target salary
        $target_salary = str_replace("$", "", $target_salary);
        $target_salary = str_replace(",", "", $target_salary);
        
        if (!is_numeric($target_salary)) {
            $target_salary = 0;
        }
        
        // Generate a hash for this search
        // First sort the titles, industries and locations
        $hash = md5(
            $token.
            implode(",",$titles).
            implode(",",$industries).
            implode(",",$locations).
            $company.
            $target_salary
        );
        
        // Check if the search has already been done
        $query = "
            SELECT *
            FROM ".TABLE_CACHE_JOB_SEARCH."
            WHERE
                user_id = ?
            AND hash = ?
        ";
        $data = array($user['id'], $hash);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        // If there is previous result, retrieve it and skip querying DB
        if (count($results) > 0) {
            $feed_id = $results[0]['id'];
            $salary_min = $results[0]['salary_min'];
            $salary_max = $results[0]['salary_max'];
            $jobs = json_decode($results[0]['results'], true);
        
        // Else, query DB with the search parameters
        } else {
            
            // Prepare company filter condition
            if ($company) {
                $company_filter = "AND jobs.company = '".$company."' ";
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
                $locations_filter = "WHERE name_en IN (".$this->prepareInParams($locations).")";
            } else {
                $locations_filter = "";
            }
            
            // Prepare industries filter condition
            if (count($industries) > 0) {
                $industries_filter = "AND jobs.industry IN (".$this->prepareInParams($industries).")";
            } else {
                $industries_filter = "";
            }
            
            $query = "
                SELECT
                    xjobs.*
                FROM
                    (
                        SELECT *
                        FROM jobs_title_to_jobsid
                        ".$titles_filter."
                    ) xtitles
                        JOIN
                    (
                        SELECT
                            jobs.id,
                            jobs.title,
                            jobs.company,
                            jobs.salary_min AS salary,
                            jobs.industry,
                            jobs.post_date AS date_post,
                            jobs.show_salary AS show_salary,
                            xlocs.name_en AS location
                        FROM
                            (
                                SELECT id, name_en
                                FROM data_locations
                                ".$locations_filter."
                            ) xlocs
                                LEFT OUTER JOIN jobs_location_code xlcodes
                                ON xlocs.id = xlcodes.location_code
                                    LEFT OUTER JOIN jobs jobs
                                    ON xlcodes.job_id = jobs.id
                        WHERE
                            jobs.salary_min >= ".$target_salary."
                            ".$industries_filter."
                        ".$company_filter."
                    ) xjobs
                        ON xjobs.id = xtitles.job_id
                ORDER BY xjobs.salary DESC
                LIMIT 100;
            ";
            $results = $this->db->query($query);
            $results = $results->result_array();
            
            $salary_min = 0;
            $salary_max = 0;
            if (count($results) > 0) {
                $salary_min = 99999999;
                $salary_max = 0;
                foreach ($results as $row) {
                    if ($row['salary'] <= $salary_min) $salary_min = $row['salary'];
                    if ($row['salary'] >= $salary_max) $salary_max = $row['salary'];
                }
            }
            $hiddenCompanies = $this->getHiddenCompanies($user['id']);
            
            $jobs = array();
            foreach ($results as $row) {
                if (!in_array($row['company'], $hiddenCompanies)) {
                    $row['company_logo'] = DEFAULT_COMPANY_LOGO;
                    $row['branch'] = '';
                    $row['summary'] = '...';
                    $row['date_due'] = '2015-06-30';
                    if ($row['show_salary'] == '0'){
                        $row['salary_max'] = '';
                        $row['salary_min'] = '';
                    }
                    $jobs[] = $row;
                }
            }
            
            // Insert results into the cache table
            $query = "
                INSERT INTO ".TABLE_CACHE_JOB_SEARCH."
                (
                    user_id, timestamp, hash,
                    titles, industries, locations, company, target_salary,
                    results, salary_min, salary_max
                )
                VALUES
                (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $data = array(
                $user['id'], $hash,
                json_encode($titles), json_encode($industries), json_encode($locations), $company, $target_salary,
                json_encode($jobs), $salary_min, $salary_max);
            $this->db->query($query, $data);
            $feed_id = $this->db->insert_id();
        }
        
        $output = array();
        $output['feed_id'] = strval($feed_id);
        $output['salary_min'] = strval($salary_min);
        $output['salary_max'] = strval($salary_max);
        $output['num_of_jobs'] = strval(count($jobs));
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
    
    // Retrieve a list of feeds for this user
    public function get_feeds() {
        $token = $this->input->get_post('token');
        $user = $this->userlib->getUserProfile($token);
        
        $query = "
            SELECT
                id AS feed_id,
                titles,
                industries,
                locations,
                company,
                target_salary,
                timestamp AS create_datetime
            FROM ".TABLE_CACHE_JOB_SEARCH."
            WHERE
                user_id = ?
            AND active = 1
            ORDER BY create_datetime DESC
        ";
        $data = array($user['id']);
        $results = $this->db->query($query, $data);
        $results = $results->result_array();
        
        $this->outputlib->output(STATUS_OK, '', $results);
        return;
    }
    
    
    // Get the jobs in a feed
    public function get_feed_jobs() {
        $token = $this->input->get_post('token');
        $feed_id = $this->input->get_post('feed_id');
        
        // Check if essential parameters are provided
        $params = array($token, $feed_id);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        
        $query = "
            SELECT *
            FROM ".TABLE_CACHE_JOB_SEARCH."
            WHERE
                user_id = ?
            AND id = ?
        ";
        $data = array($user['id'], $feed_id);
        $result = $this->db->query($query, $data);
        $result = $result->result_array();
        
        if (count($result) == 0) {
            $this->outputlib->error($this->lang->line('error_no_such_feed'));
        }
        
        $feed = $result[0];
        
        $output = array();
        $output['feed_id'] = strval($feed['id']);
        $output['salary_min'] = strval($feed['salary_min']);
        $output['salary_max'] = strval($feed['salary_max']);
        $output['jobs'] = json_decode($feed['results']);
        $output['num_of_jobs'] = strval(count($output['jobs']));
        $this->outputlib->output(STATUS_OK, '', $output);
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
		$query = "
            UPDATE ".TABLE_CACHE_JOB_SEARCH."
            SET active = 0
            WHERE
                user_id = ?
            AND id =? 
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
    
	
	// ======================================================
    // New APIs for Post job
    
    // Create a new job
    public function post_job() {
        $token = $this->input->get_post('token');
        $title = $this->input->get_post("title");
        $industry = $this->input->get_post("industry");
        $location = $this->input->get_post("location");
        $company = $this->input->get_post("company");
        $salary_min = $this->input->get_post("salary_min");
        $salary_max = $this->input->get_post("salary_max");
        $show_salary = $this->input->get_post("salary_show");
        $desc = $this->input->get_post("desc");
       
		
        // Check if essential parameters are provided
        $params = array($token, $salary_min, $salary_max);
        if ($this->helperlib->hasEmptyParams($params)) {
            $this->outputlib->error($this->lang->line('error_missing_parameters'));
        }
        
        $user = $this->userlib->getUserProfile($token);
        
            $query = "
                INSERT INTO jobs
                (
                    user_id, jobsdb_id,
                    title, industry, location, company,
                    short_desc, salary_min, salary_max, post_date, show_salary
                )
                VALUES
                (?, 0, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ";
            $data = array(
                $user['id'],
                $title, $industry, $location, $company,
                $desc, $salary_min, $salary_max, $show_salary);
            $this->db->query($query, $data);
            $job_id = $this->db->insert_id();    
  
        $output = array();
        $output['job_id'] = $job_id;
		
		$output['transaction_id']=date("ymdhis",time()).$job_id;
        $this->outputlib->output(STATUS_OK, '', $output);
    }
    
    // Get posted job
    public function get_posted_jobs() {
        $token = $this->input->get_post('token');

        $user = $this->userlib->getUserProfile($token);
        
        $query = "
			SELECT
                j.id,
                j.title,
                j.industry,
                j.company,
                '' AS branch,
                CONCAT('$', salary_min, ' - $', salary_max) AS salary,
                IF(j.link IS NULL, '', j.link) AS link,
                j.short_desc,
                j.post_date AS date_post,
                j.location,
                GROUP_CONCAT(d.name_en SEPARATOR '##') AS locations
			FROM
                jobs j
                LEFT OUTER JOIN jobs_location_code jloc
                    ON j.id = jloc.job_id
                    LEFT OUTER JOIN data_locations d
                        ON jloc.location_code = d.id
            WHERE
                j.user_id = ?
            AND j.status = 1
            GROUP BY j.id
            ORDER BY j.last_update DESC
		";
		$data = array($user['id']);
		$result = $this->db->query($query, $data);
        $result = $result->result_array();
        		
		$output = array();
        
        foreach ($result as $row) {
            
            // Prepare the location of the job
            // If job has its location in the record, extract it
            if ($row['locations'] != "") {
                $locations = explode("##", $row['locations']);
            } else {
                $locations = array();
            }
            if ($row['location'] != "") {
                if (!in_array($row['location'], $locations)) {
                    $locations[] = $row['location'];
                }
            }
            unset($row['locations']);
            
            $row['location'] = $locations;
            $row['company_logo'] = DEFAULT_COMPANY_LOGO;
            $row['date_due'] = "2015-06-30";
            
            $output[] = $row;
		}
        
        $this->outputlib->output(STATUS_OK, '', $output);

	}
}
