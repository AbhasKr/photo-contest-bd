<?php
session_start();
header('Content-type: application/json');

require_once('settings.php');
require_once('model.php');
require_once('google-api.php');

$command = $_REQUEST['command'];

try {
	$app = new ApplicationObject();
	
	switch($command) {		
		case 'GetSettings':
			if($_SESSION['admin'] != 1)
				throw new Exception(NULL, 401);

			$settings = $app->GetSettings();
			echo json_encode($settings);
			
			break;

		case 'SetSettings':
			if($_SESSION['admin'] != 1)
				throw new Exception(NULL, 401);

			$settings = array();

			if(isset($_POST['game_rules'])) {
				$settings['GAME_RULES'] = stripslashes($_POST['game_rules']);
			}

			if(isset($_FILES['background_music']) || isset($_POST['background_music'])) {
				if(isset($_POST['background_music'])) {
					$background_music = '';
					unlink('background-music/background-music.mp3');
				}
				
				if(isset($_FILES['background_music'])) {
					$background_music = $_FILES['background_music']['name'];
					move_uploaded_file($_FILES["background_music"]["tmp_name"], "background-music/background-music.mp3");
				}
				$settings['BACKGROUND_MUSIC'] = $background_music;
			}

			$app->SetSettings($settings);

			echo json_encode(array('error' => 0));
			
			break;

		case 'GetLevels':
			if($_SESSION['admin'] != 1)
				throw new Exception(NULL, 401);

			$levels = $app->GetLevels();
			
			echo json_encode($levels);

			break;

		case 'CreateLevel':
			if($_SESSION['admin'] != 1)
				throw new Exception(NULL, 401);

			$level_id = $app->CreateLevel();
			copy("images/upload-image.jpg", "images/levels/" . $level_id . ".jpg");

			echo json_encode(array('error' => 0, 'level_id' => $level_id));

			break;

		case 'EditLevelImage':
			if($_SESSION['admin'] != 1)
				throw new Exception(NULL, 401);

			move_uploaded_file($_FILES["level_image"]["tmp_name"], "images/levels/" . $_POST['level_id'] . ".jpg");

			echo json_encode(array('error' => 0));

			break;

		case 'GetLogos':
			if($_SESSION['admin'] != 1)
				throw new Exception(NULL, 401);

			$logos = $app->GetLogos($_GET['level_id']);
			
			echo json_encode($logos);

			break;

		case 'EditLogo':
			if($_SESSION['admin'] != 1)
				throw new Exception(NULL, 401);
			
			if(isset($_FILES['logo_image']))
				move_uploaded_file($_FILES["logo_image"]["tmp_name"], "images/logos/" . $_POST['level_id'] . "-" . $_POST['logo_id'] . ".jpg");

			$data = $app->EditLogo(trim($_POST['level_id']), trim($_POST['logo_id']), trim(stripslashes($_POST['logo_hint_1'])), trim(stripslashes($_POST['logo_hint_2'])), trim(stripslashes($_POST['logo_description'])), trim(stripslashes($_POST['logo_answers'])));

			echo json_encode(array('error' => 0, 'level_visible' => $data['level_visible'], 'logo_answers' => $data['logo_answers']));

			break;

		case 'InitializeUser':
			$all_levels = $app->GetUserViewLevels();
			$settings = $app->GetSettings();
			$hint_tokens = $app->GetUserHintTokens($_SESSION['user_id']);

			$user_levels = $app->GetUserLevels($_SESSION['user_id']); 
			$levels = array();
			for($i=0;$i<sizeof($user_levels);$i++) { 
				$logos = array();
				for($j=0;$j<60;$j+=3) { 
					$logos[($j/3+1)] = array('answered' => substr($user_levels[$i]['level_logos'], $j, 1), 'hint_1' => substr($user_levels[$i]['level_logos'], ($j+1), 1), 'hint_2' => substr($user_levels[$i]['level_logos'], ($j+2), 1));
				}
				$levels[$user_levels[$i]['level_id']] = $logos;
			}

			echo json_encode(array('levels' => $all_levels, 'user_levels' => $levels, 'hint_tokens' => $hint_tokens, 'game_rules' => $settings['game_rules'], 'background_music' => $settings['background_music']));

			break;

		case 'GetHint1':
			$data = $app->GetLogoHint($_SESSION['user_id'], $_GET['level_id'], $_GET['logo_id'], 1);

			echo json_encode(array('error' => 0, 'hint' => $data['hint'], 'deduct_token' => $data['deduct_token']));

			break;

		case 'GetHint2':
			$data = $app->GetLogoHint($_SESSION['user_id'], $_GET['level_id'], $_GET['logo_id'], 2);

			echo json_encode(array('error' => 0, 'hint' => $data['hint'], 'deduct_token' => $data['deduct_token']));

			break;

		case 'ResetUser':
			$app->ResetUser($_SESSION['user_id']);

			echo json_encode(array('error' => 0));

			break;

		case 'CheckAnswer':
			$data = $app->CheckAnswer($_POST['level_id'], $_POST['logo_id'], $_POST['answer_to_check'], $_SESSION['user_id']);

			echo json_encode(array('error' => 0, 'correct' => $data['correct'], 'answered' => $data['answered'], 'next_level' => $data['next_level']));

			break;

		case 'GetLogoDescription':
			$description = $app->GetLogoDescription($_POST['level_id'], $_POST['logo_id'], $_SESSION['user_id']);

			echo json_encode(array('error' => 0, 'description' => $description));

			break;

		case 'SaveUserInformation' :
			if($_POST['birthday'] != '') {
				$birthyear = array_pop(explode('/', $_POST['birthday']));
				$age = date('Y') - $birthyear;
			}
			else {
				$age = '';
			}

			try {
				$gauth = new GoogleApi();
				$access_token = $gauth->GetRefreshedAccessToken(GOOGLE_CLIENT_ID, GOOGLE_REFRESH_TOKEN, GOOGLE_CLIENT_SECRET);
				$gauth->AddRowToWorksheet(GOOGLE_WORKSHEET_LIST_FEED_URL, array('id', 'name', 'email', 'age', 'gender'), array($_POST['id'], $_POST['name'], $_POST['email'], $age, $_POST['gender']), $access_token);
				$_SESSION['new_user'] = 0;
				echo json_encode(array('error' => 0));
			}
			catch(Exception $e) {
				throw new Exception(NULL, 501);
			}
	}
}
catch(Exception $e) {
	echo json_encode(array('error' => 1, 'code' => $e->getCode(), 'message' => ErrorMessages($e->getCode())));
	exit();
}

?>