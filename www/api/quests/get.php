<?php
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json');

/*
 * API_NAME: Get Quest Info
 * API_DESCRIPTION: Method will be returned full quest info
 * API_ACCESS: authorized users
 * API_INPUT: questid - integer, Identificator of the quest
 * API_INPUT: token - string, token
 */

$curdir = dirname(__FILE__);
include_once ($curdir."/../api.lib/api.base.php");
include_once ($curdir."/../api.lib/api.game.php");
include_once ($curdir."/../../config/config.php");

$response = APIHelpers::startpage($config);

if (!APIHelpers::issetParam('questid'))
	APIHelpers::showerror(1065, 'Not found parameter "questid"');

$questid = APIHelpers::getParam('questid', 0);

if (!is_numeric($questid))
	APIHelpers::showerror(1066, 'parameter "questid" must be numeric');

$conn = APIHelpers::createConnection($config);

$response['result'] = 'ok';
$userid = APISecurity::userid();
$response['userid'] = $userid;

$quest_params = array();
$quest_filters = array();

$quest_filters[] = 'q.idquest = ?';
$quest_params[] = $questid;

if(APISecurity::isUser() && !APISecurity::isAdmin()){
	$quest_filters[] = 'q.min_score <= ?';
	$quest_params[] = APISecurity::score();
	
	$quest_filters[] = 'q.state = ?';
	$quest_params[] = 'open';
}

if(!APISecurity::isUser() && !APISecurity::isAdmin()){
	$quest_filters[] = 'q.state = ?';
	$quest_params[] = 'open';
}

$quest_filters[] = '((g.date_start < NOW() AND g.date_stop > NOW()) OR (g.date_restart < NOW()))';

$quest_filters_text = implode(' AND ', $quest_filters);
$quest_info = array();

try {
	$stmt = $conn->prepare('SELECT *, q.state as quest_state, g.state as game_state FROM quest q INNER JOIN games g ON q.gameid = g.id WHERE '.$quest_filters_text);
	$stmt->execute($quest_params);

	if($row = $stmt->fetch()) {
		$quest_info = array(
			'id' => $row['idquest'],
			'score' => $row['score'],
			'min_score' => $row['min_score'],
			'name' => $row['name'],
			'subject' => $row['subject'],
			'text' => $row['text'],
			'state' => $row['quest_state'],
			'author' => $row['author'],
			'gameid' => $row['gameid']
		);

		$response['questid'] = $row['idquest'];
		$response['game'] = array(
			'id' => $row['gameid'],
			'title' => $row['title'],
			'maxscore' => $row['maxscore']
		);
	} else {
		APIHelpers::showerror(1148, 'Quest not found or closed');
	}
	$response['result'] = 'ok';
	$response['permissions']['edit'] = APISecurity::isAdmin();
	$response['permissions']['delete'] = APISecurity::isAdmin();
	$response['permissions']['export'] = APISecurity::isAdmin();
	$response['permissions']['pass'] = APISecurity::isUser() || APISecurity::isAdmin();
} catch(PDOException $e) {
	APIHelpers::showerror(1067, $e->getMessage());
}

$gameid = $quest_info['gameid'];

// detection status of quest
if($userid == 0){
	$quest_info['status'] = 'open';
}else{
	try{
		$stmt = $conn->prepare('SELECT * FROM users_quests WHERE questid = ? AND userid = ?');
		$stmt->execute(array($questid, $userid));
		if($row = $stmt->fetch()){
			$quest_info['status'] = 'completed';
			$quest_info['dt_passed'] = $row['dt_passed'];
		}else{
			$quest_info['status'] = 'open';
		}
	}catch(PDOException $e){
		APIHelpers::showerror(1067, $e->getMessage());
	}
}

$response['data'] = $quest_info;
$response['attempts'] = array();

// answers
if($userid != 0){
	
	try{
		$stmt = $conn->prepare('SELECT answer_try, datetime_try, levenshtein FROM tryanswer WHERE idquest = ? AND iduser = ? ORDER BY datetime_try DESC');
		$stmt->execute(array($questid, $userid));
		while($row = $stmt->fetch()){
			$response['attempts'][] = array(
				'dt' => $row['datetime_try'],
				'text' => $row['answer_try'],
				'levenshtein' => $row['levenshtein'],
			);
		}
	}catch(PDOException $e){
		APIHelpers::showerror(1067, $e->getMessage());
	}			
}


// statistics
$response['statistics'] = array();

// solved
try{
	$stmt = $conn->prepare('SELECT count(id) as cnt FROM tryanswer_backup WHERE idquest = ? AND passed = "Yes"');
	$stmt->execute(array($questid));
	if($row = $stmt->fetch()){
		$response['statistics']['solved'] = $row['cnt'];
	}
}catch(PDOException $e){
	APIHelpers::showerror(1067, $e->getMessage());
}

// tries_solved
try{
	$stmt = $conn->prepare('SELECT count(id) as cnt FROM tryanswer_backup WHERE idquest = ? AND passed = "No"');
	$stmt->execute(array($questid));
	if($row = $stmt->fetch()){
		$response['statistics']['tries_solved'] = $row['cnt'];
	}
}catch(PDOException $e){
	APIHelpers::showerror(1067, $e->getMessage());
}

// tries_nosolved
try{
	$stmt = $conn->prepare('SELECT count(id) as cnt FROM tryanswer WHERE idquest = ? AND passed = "No"');
	$stmt->execute(array($questid));
	if($row = $stmt->fetch()){
		$response['statistics']['tries_nosolved'] = $row['cnt'];
	}
}catch(PDOException $e){
	APIHelpers::showerror(1067, $e->getMessage());
}

// users who solved
$response['statistics']['users'] = array();
try{
	$stmt = $conn->prepare('SELECT u.id, u.nick, u.logo FROM tryanswer_backup ta INNER JOIN users u ON u.id = ta.iduser WHERE idquest = ? AND passed = "Yes"');
	$stmt->execute(array($questid));
	while($row = $stmt->fetch()){
		$response['statistics']['users'][] = array(
			'id' =>  $row['id'],
			'nick' =>  htmlspecialchars($row['nick']),
			'logo' =>  $row['logo'],
		);
	}
}catch(PDOException $e){
	APIHelpers::showerror(1067, $e->getMessage());
}

APIHelpers::endpage($response);
