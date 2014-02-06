<?php

function ErrorMessages($error_code) {
	switch($error_code) {
		case 101: return 'Error : Server failed. It might be possible that your DB credentials are wrong.'; 
		
		case 102: return 'Error : Server failed [102]';

		case 103: return 'Error : Server failed [103]';

		case 201: return 'Error : Cannot create a new level. Settings of the last level are not filled';

		case 301: return 'Error : Cannot get hint. No hint tokens are available';

		case 302: return 'Error : Cannot get hint. User has not entered this level';

		case 304: return 'Error : Cannot get answer. User has not entered this level';

		case 305: return 'Error : Cannot get description. User has not answered this logo';

		case 401: return 'Error : Unauthorized request';

		case 501: return 'Error : Failed to add row to spreadsheet';
	}
}



class DatabaseOperations
{
	private $dbh;
	
	private $result;
	
	public function __construct() {
		$this->dbh = new mysqli(SERVER_NAME, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DBNAME); 
		if($this->dbh->connect_errno) 
			throw new Exception(NULL, 101);
	}
	
	public function GetDatabaseHandle() {
		return $this->dbh;
	}
	
	public function GetResultSet() {
		return $this->result;
	}
	
	public function GetNumRows() {
		return $this->result->num_rows;
	}
	
	public function GetNextResultRow() {
		return $this->result->fetch_assoc();
	}
	
	public function StartTransaction() {
		$this->dbh->autocommit(FALSE);
	}
	
	public function EndTransaction() {
		$this->dbh->commit();
	}
	
	public function ExecuteSQuery($query) {
		$this->result = $this->dbh->query($query);
		if(!$this->result)
			throw new Exception(NULL, 102);
	}
	
	public function ExecuteUIDQuery($query, $confirmation = 1) {
		$this->result = $this->dbh->query($query);
		if(!$this->result)
			throw new Exception(NULL, 102);
		if($confirmation == 1)
			if($this->dbh->affected_rows == 0)
				throw new Exception(NULL, 103);
	}
}



class Rounds 
{
	private $_dbops;
	
	public function __construct() {
		$this->_dbops = new DatabaseOperations();
	}

	public function GetCurrentRound() {
		$query = "SELECT * FROM pca_rounds WHERE current_round=1";
		$this->_dbops->ExecuteSQuery($query);	
		
		if($this->_dbops->GetNumRows() == 0) {
			$current_round = -1;
		} 
		else {
			$current_round = $this->_dbops->GetNextResultRow();
		}

		return $current_round;
	}

	public function GetPreviousRound($current_round_id) {
		$query = "SELECT * FROM pca_rounds WHERE round_id<" . $current_round_id . " LIMIT 0,1";
		$this->_dbops->ExecuteSQuery($query);	
		
		if($this->_dbops->GetNumRows() == 0) {
			$previous_round = -1;
		}
		else {
			$previous_round = $this->_dbops->GetNextResultRow();
		}

		return $previous_round;
	}

	public function GetNextRound($current_round_id) {
		$query = "SELECT * FROM pca_rounds WHERE round_id>" . $current_round_id . " LIMIT 0,1";
		$this->_dbops->ExecuteSQuery($query);	
		
		if($this->_dbops->GetNumRows() == 0) {
			$next_round = -1;
		}
		else {
			$next_round = $this->_dbops->GetNextResultRow();
		}

		return $next_round;
	}

	/*
	1) cannot be a final round if there is an existing final round
	*/
	public function CreateRoundCheck() {

	}

	/*
	1) start time should be after the end time of the previous round 
	*/
	public function CreateRound() {

	}

	/*
	Finished rounds
	1) no editing 

	Present round
	1) no change in round type if any voting has heppened 
	2) no change in start time if there any voting has happened 
	3) cannot be a final round if there is an existing final round
	4) no change in vote limit if the vote limit has already been achieved for some user work
	5) no reduction in winner limit in case the winner limit has already been achieved

	Upcoming rounds
	2) cannot be a final round if there is an existing final round
	*/
	public function EditRoundCheck() {

	}

	/*
	Present round
	1) end time of a present round should not be greater than the start time of the next round

	Upcoming rounds
	1) start time should not be greater than the end time of the previous round
	*/
	public function EditRound() {

	}

	/*
	Finished Rounds : Can be deleted
	Present Round : Cannot be deleted if any voting has heppened 
	Upcoming Rounds : Can be deleted
	*/
	public function DeleteRoundCheck() {

	}

	public function DeleteRound() {

	}
}



class Events 
{
	private $rounds_ob;
	private $user_works_ob;
	private $_dbops;
	
	public function __construct() {
		$this->rounds_ob = new Rounds();
		$this->user_works_ob = new UserWorks();
		$this->_dbops = new DatabaseOperations();
	}

	/* 
	Check whether current round or next rounds ended/started/ongoing
	Check current round ended :
	1) by start_ts & end_ts
	2) current_round [ In case no request is made between start_ts & end_ts ]
	*/
	public function RoundStatusCheck() {
		$current_round = $rounds_ob->GetCurrentRound();
		
		/* Rounds database is empty */
		if($current_round == -1) {
			return $current_round;
		}

		$current_ts = time();
		/* Current round is valid by timestamp [ actual ongoing round ] */
		if($current_ts >= $current_round['start_ts'] && $current_ts <= $current_round['end_ts']) {
			$current_round['round_started'] = 1;
			$current_round['round_ended'] = 0;
			return $current_round;
		}
		/* Current round is not valid by timestamp. Find the actual ongoing round */
		else {
			/* Current round has not started */
			if($current_ts < $current_round['start_ts']) {
				$current_round['round_started'] = 0;
				$current_round['round_ended'] = 0;
				return $current_round;
			}

			/* Current round has ended */
			if($current_ts > $current_round['end_ts']) {
				/* Current round is the final round */
				if($current_round['final_round'] == 1) {
					$current_round['round_started'] = 1;
					$current_round['round_ended'] = 1;
					return $current_round;
				}

				$current_round['round_started'] = 1;
				$current_round['round_ended'] = 1;
				$starting_round = $current_round;
				while(1) {
					$next_round = $rounds_ob->GetNextRound($starting_round['round_id']);   

					/* All rounds have ended, but no final round given */
					if($next_round == -1) {
						return $starting_round;
					}

					$this->user_works_ob->RoundEndedAction($starting_round, $next_round);
					
					/* Next round has started */
					if($current_ts >= $next_round['start_ts']) {					
						/* Next round has not ended */
						if($current_ts < $next_round['end_ts']) {
							$next_round['round_started'] = 1;
							$next_round['round_ended'] = 0;
							return $next_round;
						}
						/* Next round has ended */
						else {
							$next_round['round_started'] = 1;
							$next_round['round_ended'] = 1;
							$starting_round = $next_round;
						}
					}
					/* Next round has not yet started */
					else {
						$starting_round['round_started'] = 0;
						$starting_round['round_ended'] = 0;
						return $starting_round;
					}
				}
			}
		}
	}

	/* 
	End the current round
	Check the number of entries in the next round
	Move the appropriate number of entries from given round to given round
	*/
	public function RoundEndedAction($current_round, $next_round) {
		$query = "UPDATE pca_rounds SET current_round=0 WHERE round_id=" . $current_round['round_id'];
		$this->_dbops->ExecuteUIDQuery($query);	
		$query = "UPDATE pca_rounds SET current_round=1 WHERE round_id=" . $next_round['round_id'];
		$this->_dbops->ExecuteUIDQuery($query);	

		$query = "SELECT count(*) FROM pca_rounds WHERE round_id=" . $next_round['round_id'];
		$this->_dbops->ExecuteSQuery($query);	
		
		$previous_winners = $this->_dbops->GetNextResultRow();
		$num_more_winners = $current_round['winner_limit'] - $previous_winners;

		if($num_more_winners > 0) {
			$query = "SELECT work_id,this_round_points FROM pca_user_works WHERE round_id=" . $next_round['round_id'] . " ORDER BY this_round_points DESC, updated_ts ASC LIMIT 0," . $num_more_winners;
			$this->_dbops->ExecuteSQuery($query);		
		}
		
		$user_works = array();
		for($i=0; $i<$this->_dbops->GetNumRows(); $i++) {
			$user_works[] = $this->_dbops->GetNextResultRow();
		}

		for($i=0; $i<sizeof($user_works); $i++) {
			$current_ts = time();

			$query = "UPDATE pca_user_works SET round_id=" . $next_round['round_id'] . ",this_round_points=0,updated_ts=" . $current_ts . " WHERE work_id=" . $user_works[$i]['work_id'];
			$this->_dbops->ExecuteUIDQuery($query);	

			$query = "INSERT INTO pca_user_works_history VALUES(" .
						$user_works[$i]['work_id'] .
						$current_round['round_id'] . "," .
						$user_works[$i]['this_round_points'] . "," .
						$current_ts . 
					 ")";
			$this->_dbops->ExecuteUIDQuery($query);	
		} 
	}

	/* 
	Check whether next round started 
	*/
	public function NextRoundStartedCheck() {
		
	}

	/* 
	Start the next round
	*/
	public function NextRoundStartedAction() {
		
	}

	/*
	Check whether user work has the required no of points
	If it has the points - check the no of entries in the next round
	*/
	public function UserWorkMoveCheck() {

	}

	/*
	Move user works from one round to the next
	Add the user works to history
	*/
	public function UserWorkMoveAction() {

	}
}



class UserWorks 
{
	public function __construct() {

	}

	/* 
	Check whether user work can be uploaded => end time of first round has not ended 
	*/
	public function CreateUserWorkCheck() {

	}

	/*
	Add user work to the first round 
	*/
	public function CreateUserWork() {

	}

	/*
	Edit properties of user work [ user_name, work_description & thumbnail_updated_ts ]
	*/
	public function EditUserWork() {

	}

	/*
	Delete user work 
	*/
	public function DeleteUserWork() {

	}

	/*
	Add user vote to a user work
	Increment user vote of the work in the current round
	*/
	public function UserVoting() {

	}

	/*
	Add judge vote to a user work
	Increment judge vote of the work in the current round
	*/
	public function JudgeVoting() {
		
	}
}





class ApplicationObject
{
	private $_dbops;
	
	public function __construct() {
		$this->_dbops = new DatabaseOperations();
	}
	
	public function CreateAppTables() {
		$this->_dbops->StartTransaction();
		
		$query = "DROP TABLE IF EXISTS la_levels";
		$this->_dbops->ExecuteSQuery($query);	
		
		$query = "CREATE TABLE la_levels(
					level_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					level_visible TINYINT UNSIGNED, 
					level_ts INT UNSIGNED NOT NULL
				)AUTO_INCREMENT=1001 ENGINE=InnoDB";
		$this->_dbops->ExecuteSQuery($query);	
		
		$query = "DROP TABLE IF EXISTS la_logos";
		$this->_dbops->ExecuteSQuery($query);
		
		$query = "CREATE TABLE la_logos(
					level_id INT UNSIGNED NOT NULL,
					logo_id TINYINT UNSIGNED NOT NULL,
					logo_hint_1 VARCHAR(2000) CHARACTER SET utf8  NULL,
					logo_hint_2 VARCHAR(2000) CHARACTER SET utf8 NULL,
					logo_description VARCHAR(2000) CHARACTER SET utf8 NULL,
					logo_answers VARCHAR(2000) CHARACTER SET utf8 NULL,
					INDEX(level_id)
				)ENGINE=InnoDB";
		$this->_dbops->ExecuteSQuery($query);	
		
		$query = "DROP TABLE IF EXISTS la_settings";
		$this->_dbops->ExecuteSQuery($query);	
		
		$query = "CREATE TABLE la_settings(
					name VARCHAR(50) NOT NULL,
					value VARCHAR(5000) NULL
				)ENGINE=InnoDB";
		$this->_dbops->ExecuteSQuery($query);	
		
		$query = "DROP TABLE IF EXISTS la_users";
		$this->_dbops->ExecuteSQuery($query);	
		
		$query = "CREATE TABLE la_users(
					user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
					hint_tokens INT UNSIGNED NOT NULL,
					last_unlocked_level INT UNSIGNED NULL
				)ENGINE=InnoDB";
		$this->_dbops->ExecuteSQuery($query);	
		
		$query = "DROP TABLE IF EXISTS la_users_logos";
		$this->_dbops->ExecuteSQuery($query);	
		
		$query = "CREATE TABLE la_users_logos(
					user_id BIGINT UNSIGNED NOT NULL,
					level_id INT UNSIGNED NOT NULL,
					level_logos VARCHAR(60) NOT NULL,
					INDEX(user_id)
				)ENGINE=InnoDB";
		$this->_dbops->ExecuteSQuery($query);	

		$query = "INSERT INTO la_settings VALUES('BACKGROUND_MUSIC', ''),('GAME_RULES', '');"; 
		$this->_dbops->ExecuteUIDQuery($query);	
		
		$this->_dbops->EndTransaction();
	}

	public function GetSettings() {
		$query = "SELECT * FROM la_settings";
		$this->_dbops->ExecuteSQuery($query);	
		
		$settings = array();
		for($i=0; $i<$this->_dbops->GetNumRows(); $i++) {
			$row = $this->_dbops->GetNextResultRow();
			$settings[strtolower($row['name'])] = ($row['value'] == NULL ? '' : $row['value']);
		}
		
		return $settings;
	}
	
	public function SetSettings($settings) {
		$this->_dbops->StartTransaction();
		$handle = $this->_dbops->GetDatabaseHandle();
		
		$query = "SELECT * FROM la_settings";
		$this->_dbops->ExecuteSQuery($query);	
		
		foreach($settings as $k => $v) {
			$query = "UPDATE la_settings SET value='" . $handle->real_escape_string($v) . "' WHERE name='" . $k . "'";
			$this->_dbops->ExecuteUIDQuery($query, 0);	
		}
		
		$this->_dbops->EndTransaction();
	}
	
	public function GetLevels() {
		$query = "SELECT * FROM la_levels";
		$this->_dbops->ExecuteSQuery($query);	
		
		$levels = array();
		for($i=0; $i<$this->_dbops->GetNumRows(); $i++) {
			$row = $this->_dbops->GetNextResultRow();
			$levels[] = $row;
		}
		
		return $levels;
	}

	public function CreateLevel() {
		$this->_dbops->StartTransaction();

		$query = "SELECT count(*) FROM la_levels WHERE level_visible=0";
		$this->_dbops->ExecuteSQuery($query);	
		
		$row = $this->_dbops->GetNextResultRow();
		if($row['count(*)'] != 0) 
			throw new Exception(NULL, 201);

		$query = "INSERT INTO la_levels VALUES(" .
					"NULL, " .
					0 . "," .
					time() . 
				 ")";
		$this->_dbops->ExecuteUIDQuery($query);	

		$query = "SELECT last_insert_id()";
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();
		$level_id = $row['last_insert_id()'];

		$values = '';
		for($i=1; $i<=20; $i++) {
			$values .= '(' . $level_id . ',' . $i . ',' . '"", "", "", ""),';
		}

		$query = "INSERT INTO la_logos VALUES" . rtrim($values, ","); 
		$this->_dbops->ExecuteUIDQuery($query);	

		$this->_dbops->EndTransaction();

		return $level_id;
	}

	public function GetLogos($level_id) {
		$handle = $this->_dbops->GetDatabaseHandle();

		$query = "SELECT * FROM la_logos WHERE level_id=" .  $handle->real_escape_string($level_id);
		$this->_dbops->ExecuteSQuery($query);	
		
		$logos = array();
		for($i=0; $i<$this->_dbops->GetNumRows(); $i++) {
			$row = $this->_dbops->GetNextResultRow();
			if($row['logo_answers'] != '')
				$row['logo_answers'] = json_decode('"' . $row['logo_answers'] . '"');
			$logos[] = $row;
		}
		
		return $logos;

	}
	
	public function EditLogo($level_id, $logo_id, $logo_hint_1, $logo_hint_2, $logo_description, $logo_answers) {
		$this->_dbops->StartTransaction();
		$handle = $this->_dbops->GetDatabaseHandle();

		/* Filter answers to consider for utf-8 
		Split by end of line
		Remove blanks from ends
		Convert multiple blanks in between to single blanks 
		If answer is blank, remove it
		*/
		$answers = preg_split("/" . PHP_EOL . "/", $logo_answers);	
		for($i=0; $i<sizeof($answers); $i++) {
			$answers[$i] = trim($answers[$i]);
			$answers[$i] = preg_replace('/[\s]+/', ' ', $answers[$i]);
			if(trim($answers[$i]) == '')
				unset($answers[$i]);
		}
		$logo_answers = trim(json_encode(implode(PHP_EOL, array_values($answers))), '"');

		$level_id = $handle->real_escape_string($level_id);
		$logo_id = $handle->real_escape_string($logo_id);
		$logo_hint_1 = $handle->real_escape_string($logo_hint_1);
		$logo_hint_2 = $handle->real_escape_string($logo_hint_2);
		$logo_description = $handle->real_escape_string($logo_description);

		/* Check how many logos are not filled before updating this logo */
		$query = "SELECT count(*) FROM la_logos WHERE level_id=" .  $level_id . " AND logo_description=''";
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();
		$not_filled_logos_before = $row['count(*)'];

		/* Update logo */
		$query = "UPDATE la_logos SET logo_hint_1='" . $logo_hint_1 . "', logo_hint_2='" . $logo_hint_2 . "', logo_description='" . $logo_description . "', logo_answers='" . $handle->real_escape_string($logo_answers) . "' WHERE level_id=" . $level_id . " AND logo_id=" . $logo_id;
		$this->_dbops->ExecuteUIDQuery($query, 0);	

		/* Check how many logos are not filled after updating this logo */
		$query = "SELECT count(*) FROM la_logos WHERE level_id=" .  $level_id . " AND logo_description=''";
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();
		$not_filled_logos_after = $row['count(*)'];
		
		/* If all logos are filled after updating this logo */
		if($not_filled_logos_after == 0) {
			/* And before updating this logo, not all logos were filled => updating this logo causes level to be visible */
			if($not_filled_logos_after != $not_filled_logos_before) {
				/* Make level visible */
				$query = "UPDATE la_levels SET level_visible=1 WHERE level_id=" . $level_id;
				$this->_dbops->ExecuteUIDQuery($query, 0);	

				/* Get no of visible levels */
				$query = "SELECT count(*) FROM la_levels WHERE level_visible=1";
				$this->_dbops->ExecuteSQuery($query);	
				$row = $this->_dbops->GetNextResultRow();
				$num_levels = $row['count(*)'];
				
				/* Find users who have no levels OR were at the last existing level */
				$query = "SELECT user_id FROM la_users WHERE last_unlocked_level " . ($num_levels == 1 ? "IS NULL" : "=0"); 
				$this->_dbops->ExecuteSQuery($query);	
				
				$users = array();
				for($i=0; $i<$this->_dbops->GetNumRows(); $i++) {
					$row = $this->_dbops->GetNextResultRow();
					$users[] = $row['user_id'];
				}

				if(sizeof($users) != 0) {
					/* Update last unlocked level of users with this level */
					$query = "UPDATE la_users SET last_unlocked_level=" . $level_id . " WHERE last_unlocked_level " . ($num_levels == 1 ? "IS NULL" : "=0");
					$this->_dbops->ExecuteUIDQuery($query);	

					/* Add this level to users who have no levels OR were at the last existing level */
					$values = '';
					for($i=0; $i<sizeof($users); $i++) {
						$values .= "(" . $users[$i] . "," . $level_id . ",'000000000000000000000000000000000000000000000000000000000000'),"; 
					}	
					$values = rtrim($values, ",");
					$query = "INSERT INTO la_users_logos VALUES" . $values; 
					$this->_dbops->ExecuteUIDQuery($query);	
				}
			}
			$level_visible = 1;
		}
		else 
			$level_visible = 0;

		$this->_dbops->EndTransaction();

		return array('level_visible' => $level_visible, 'logo_answers' => json_decode('"' . $logo_answers . '"'));
	}

	public function GetUserViewLevels() {
		$query = "SELECT level_id FROM la_levels WHERE level_visible=1";
		$this->_dbops->ExecuteSQuery($query);	
		
		$levels = array();
		for($i=0; $i<$this->_dbops->GetNumRows(); $i++) {
			$row = $this->_dbops->GetNextResultRow();
			$levels[] = $row['level_id'];
		}
		
		return $levels;
	}

	public function GetUserLevels($user_id) {
		$query = "SELECT * FROM la_users_logos WHERE user_id=" . $user_id;
		$this->_dbops->ExecuteSQuery($query);	
		
		$user_levels = array();
		for($i=0; $i<$this->_dbops->GetNumRows(); $i++) {
			$row = $this->_dbops->GetNextResultRow();
			$user_levels[] = $row;
		}
		
		return $user_levels;
	}

	public function GetLogoHint($user_id, $level_id, $logo_id, $hint_no) {
		$this->_dbops->StartTransaction();
		$handle = $this->_dbops->GetDatabaseHandle();

		/* Get level logos to check for hint flag */
		$query = "SELECT level_logos FROM la_users_logos WHERE user_id=" . $user_id . " AND level_id=" . $handle->real_escape_string($level_id);
		$this->_dbops->ExecuteSQuery($query);	
		
		if($this->_dbops->GetNumRows() == 0)
			throw new Exception(NULL, 302);

		$row = $this->_dbops->GetNextResultRow();
		$level_logos = $row['level_logos'];

		/* Hint flag for this hint for this logo */
		$hint_flag = substr($level_logos, ((3*($logo_id-1))+$hint_no), 1);
		/* If hint has not been taken before */
		if($hint_flag == '0') {
			/* Check for hint tokens */
			$hint_tokens = $this->GetUserHintTokens($user_id);
			if($hint_tokens == 0)
				throw new Exception(NULL, 301);

			/* Update level logos */
			$level_logos[((3*($logo_id-1))+$hint_no)] = '1';
			$query = "UPDATE la_users_logos SET level_logos='" . $level_logos . "' WHERE user_id=" . $user_id . " AND level_id=" . $handle->real_escape_string($level_id);
			$this->_dbops->ExecuteUIDQuery($query);	
			
			/* Deduct 1 hint token */
			$this->SetUserHintTokens($user_id, -1);

			$deduct_token = 1;
		}
		else {
			$deduct_token = 0;
		}

		/* Get the hint */
		$query = "SELECT logo_hint_" . $hint_no . " FROM la_logos WHERE level_id=" . $handle->real_escape_string($level_id) . " AND logo_id=" . $logo_id; 
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();

		$this->_dbops->EndTransaction();

		return array('hint' => $row['logo_hint_' . $hint_no], 'deduct_token' => $deduct_token);
	}

	public function AddUserNewLevel($user_id, $level_id) {
		$level_logos = '';
		for($i=0; $i<60; $i++) {
			$level_logos .= '0';
		}

		$query = "INSERT INTO la_users_logos VALUES(" . $user_id . ", " . $level_id . ", '" . $level_logos . "')"; 
		$this->_dbops->ExecuteUIDQuery($query);	
	}

	public function GetUserHintTokens($user_id) {
		$query = "SELECT hint_tokens FROM la_users WHERE user_id=" . $user_id;
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();
		
		return $row['hint_tokens'];
	}

	public function SetUserHintTokens($user_id, $hint_tokens_change) {
		$query = "UPDATE la_users SET hint_tokens=hint_tokens+" . $hint_tokens_change . " WHERE user_id=" . $user_id;
		$this->_dbops->ExecuteUIDQuery($query);	
	}

	public function UserExists($user_id) {
		$query = "SELECT count(*) FROM la_users WHERE user_id=" . $user_id;
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();
		
		return $row['count(*)'];
	}
	
	public function AddUser($user_id) {
		$this->_dbops->StartTransaction();

		/* Find the first level */
		$query = "SELECT level_id FROM la_levels WHERE level_visible=1 LIMIT 0,1";
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();

		/* If level exits : Add first level for new user */
		if($this->_dbops->GetNumRows() != 0) {
			$last_unlocked_level = $row['level_id'];
			$query = "INSERT INTO la_users_logos VALUES(" .
					$user_id . ", " . 
					$last_unlocked_level . ",'" .
					'000000000000000000000000000000000000000000000000000000000000' . "'" .
				 ")"; 
			$this->_dbops->ExecuteUIDQuery($query);	 
		}
		else {
			$last_unlocked_level = 'NULL';
		}

		/* Add user */
		$query = "INSERT INTO la_users VALUES(" .
					$user_id . ", " . 
					10 . ", " .
					$last_unlocked_level .
				 ")";
		$this->_dbops->ExecuteUIDQuery($query);	

		$this->_dbops->EndTransaction();
	}

	public function ResetUser($user_id) {
		$this->_dbops->StartTransaction();

		/* Delete user's levels */
		$query = "DELETE FROM la_users_logos WHERE user_id=" . $user_id;
		$this->_dbops->ExecuteUIDQuery($query, 0);	

		/* Find the first level */
		$query = "SELECT level_id FROM la_levels WHERE level_visible=1 LIMIT 0,1";
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();

		/* If level exits : Add first level for user */
		if($this->_dbops->GetNumRows() != 0) {
			$last_unlocked_level = $row['level_id'];
			$query = "INSERT INTO la_users_logos VALUES(" .
					$user_id . ", " . 
					$last_unlocked_level . ",'" .
					'000000000000000000000000000000000000000000000000000000000000' . "'" .
				 ")"; 
			$this->_dbops->ExecuteUIDQuery($query);	
		}
		else {
			$last_unlocked_level = 'NULL';
		}

		/* Update hint tokens & last unlocked level of the user */
		$query = "UPDATE la_users SET hint_tokens=10,last_unlocked_level=" . $last_unlocked_level . " WHERE user_id=" . $user_id;
		$this->_dbops->ExecuteUIDQuery($query, 0);	

		$this->_dbops->EndTransaction();
	}

	public function CheckAnswer($level_id, $logo_id, $answer_to_check, $user_id) {
		$this->_dbops->StartTransaction();
		$handle = $this->_dbops->GetDatabaseHandle();

		$level_id = $handle->real_escape_string($level_id);
		$logo_id = $handle->real_escape_string($logo_id);

		/* Filter answer to be checked */
		$answer_to_check = preg_replace('/[\s]+/', ' ', trim($answer_to_check));

		/* Get level logos of the user for this level */
		$query = "SELECT level_logos FROM la_users_logos WHERE user_id=" . $user_id . " AND level_id=" . $level_id;
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();
		if($this->_dbops->GetNumRows() == 0)
			throw new Exception(NULL, 304);
		$level_logos = $row['level_logos'];

		/* Get answers for this logo */
		$query = "SELECT logo_answers FROM la_logos WHERE level_id=" . $level_id . " AND logo_id=" . $logo_id;
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();
		
		/* Brrak answers by new line */
		$answers = explode('\n', $row['logo_answers']);	
		
		/* Check for correct answer */
		$correct = 0;
		for($i=0; $i<sizeof($answers); $i++) {
			/* Check for utf-8 answer */
			$pos = strpos($answers[$i], '\u');
			
			/* If ascii, do a regex compare */
			if($pos === FALSE) { 
				if(preg_match('/' . $answers[$i] . '/i', $answer_to_check)) {
					$correct = 1;
					break;
				}
			}
			/* If utf-8, json encode the guess and do a string compare */
			else {
				if(trim(json_encode($answer_to_check), '"') == $answers[$i]) {
					$correct = 1;
					break;
				}
			}
		}

		/* If answer is correct */
		if($correct == 1) {
			/* Update answer flag for this logo */
			$level_logos[((3*($logo_id-1)))] = '1';
			$query = "UPDATE la_users_logos SET level_logos='" . $level_logos . "' WHERE user_id=" . $user_id . " AND level_id=" . $level_id;
			$this->_dbops->ExecuteUIDQuery($query);	

			/* Find the no of correct logos for this level */
			$logos_answered = 0;
			for($j=0; $j<60; $j+=3) { 
				if(substr($level_logos, $j, 1) == '1')
					$logos_answered++;
			}

			/* If correct logos is 15 => Next level is unlocked */
			if($logos_answered == 15) {
				/* Get next level */
				$query = "SELECT level_id FROM la_levels WHERE level_visible=1 AND level_id>" . $level_id . " LIMIT 0,1";
				$this->_dbops->ExecuteSQuery($query);	
				
				/* If there is no next level */
				if($this->_dbops->GetNumRows() == 0) {
					$next_level = -1;
				}
				/* If there is a next level */
				else {
					$row = $this->_dbops->GetNextResultRow();
					$next_level = $row['level_id'];
					$query = "INSERT INTO la_users_logos VALUES(" .
								$user_id . ", " . 
								$next_level . ",'" .
								'000000000000000000000000000000000000000000000000000000000000' . "'" .
				 			")"; 
					$this->_dbops->ExecuteUIDQuery($query);	
				}

				$query = "UPDATE la_users SET hint_tokens=hint_tokens+10,last_unlocked_level=" . ($next_level == -1 ? 0 : $next_level) . " WHERE user_id=" . $user_id;
				$this->_dbops->ExecuteUIDQuery($query);	
			}
			else {
				$next_level = -1;	
			}
		}
		else {
			$logos_answered = -1;
			$next_level = -1;
		}

		$this->_dbops->EndTransaction();

		return array('correct' => $correct, 'answered' => $logos_answered, 'next_level' => $next_level);
	}

	public function GetLogoDescription($level_id, $logo_id, $user_id) {
		$handle = $this->_dbops->GetDatabaseHandle();

		$query = "SELECT level_logos FROM la_users_logos WHERE user_id=" . $user_id . " AND level_id=" . $handle->real_escape_string($level_id);
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();
		if($this->_dbops->GetNumRows() == 0)
			throw new Exception(NULL, 304);
		$level_logos = $row['level_logos'];

		if(substr($level_logos, (3*($logo_id-1)), 1) == '0')
			throw new Exception(NULL, 305);

		$query = "SELECT logo_description FROM la_logos WHERE level_id=" . $handle->real_escape_string($level_id) . " AND logo_id=" . $handle->real_escape_string($logo_id);
		$this->_dbops->ExecuteSQuery($query);	
		$row = $this->_dbops->GetNextResultRow();
		
		return $row['logo_description'];
	}
}

?>