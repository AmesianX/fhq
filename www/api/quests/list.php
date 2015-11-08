<?php
/*
 * API_NAME: Quest List
 * API_DESCRIPTION: Method will be returned quest list
 * API_ACCESS: all
 * API_INPUT: token - string, token
 * API_INPUT: filter_open - boolean, filter by open quests (it not taked)
 * API_INPUT: filter_current - boolean, filter by in progress quests (taked)
 * API_INPUT: filter_completed - boolean, filter by completed quest (finished quests)
 * API_INPUT: filter_subjects - string, filter by subjects quests (for example: "hashes,trivia" and etc. also look types)
 */

$curdir = dirname(__FILE__);
include_once ($curdir."/../api.lib/api.base.php");
include_once ($curdir."/../api.lib/api.security.php");
include_once ($curdir."/../api.lib/api.helpers.php");
include_once ($curdir."/../api.lib/api.game.php");
include_once ($curdir."/../../config/config.php");

$response = APIHelpers::startpage($config);

// APIHelpers::checkAuth();

$conn = APIHelpers::createConnection($config);

$message = '';

if(APIGame::id() != 0){
	if (!APIGame::checkGameDates($message))
		APIHelpers::showerror(1094, $message);
}

if (APIGame::id() == 0 && APISecurity::isLogged())
	APIHelpers::showerror(1095, "Game was not selected.");

// TODO: must be added filters
$conn = APIHelpers::createConnection($config);

$response['result'] = 'ok';

$response['status']['open'] = 0;
$response['status']['current'] = 0;
$response['status']['completed'] = 0;

$response['filter']['open'] = APIHelpers::getParam('filter_open', true);
$response['filter']['current'] = APIHelpers::getParam('filter_current', true);
$response['filter']['completed'] = APIHelpers::getParam('filter_completed', false);

$response['filter']['open'] = filter_var($response['filter']['open'], FILTER_VALIDATE_BOOLEAN);
$response['filter']['current'] = filter_var($response['filter']['current'], FILTER_VALIDATE_BOOLEAN);
$response['filter']['completed'] = filter_var($response['filter']['completed'], FILTER_VALIDATE_BOOLEAN);

$response['gameid'] = APIGame::id();

// APIHelpers::showerror(9999, "12");
$userid = APISecurity::userid();
$response['userid'] = $userid;

// APIHelpers::showerror(9999, "111");

$filters = array();

if(APIGame::id() != 0){
	$filters[] = 'quest.gameid = '.APIGame::id(); // todo check sqlinj
}

if(!APISecurity::isAdmin()){
	$filters[] = 'quest.state = "open"';
}

if(!APISecurity::isAdmin()){
	'quest.min_score <= '.APISecurity::score();
}

$filters_text = implode(' AND ', $filters);

// calculate count summary
try {
	$stmt = $conn->prepare('
			SELECT
				count(quest.idquest) as cnt
			FROM
				quest
			WHERE
				'.$filters_text.'
	');
	$stmt->execute();
	if($row = $stmt->fetch())
		$response['status']['summary'] = $row['cnt'];
} catch(PDOException $e) {
	APIHelpers::showerror(1096, $e->getMessage());
}

// calculate open tasks
try {
	$query = '
			SELECT
				count(quest.idquest) as cnt
			FROM
				quest
			LEFT JOIN users_quests ON users_quests.questid = quest.idquest AND users_quests.userid = ?
			WHERE
				'.$filters_text.'
				AND isnull(users_quests.dt_passed)
	';

	if($userid==0){
		$query = '
				SELECT
					count(quest.idquest) as cnt
				FROM
					quest
				WHERE
					'.$filters_text.'
		';
	}
	// $response['query_open'] = $query;
	$stmt1 = $conn->prepare($query);
	$stmt1->execute(array(APISecurity::userid()));
	if($row = $stmt1->fetch())
		$response['status']['open'] = $row['cnt'];
} catch(PDOException $e) {
	APIHelpers::showerror(1097, $e->getMessage());
}

// calculate completed tasks
try {
	$stmt = $conn->prepare('
			SELECT
				count(quest.idquest) as cnt
			FROM
				quest
			INNER JOIN 
				users_quests ON users_quests.questid = quest.idquest AND users_quests.userid = ?
			WHERE
				'.$filters_text.'
	');
	if($userid==0){
		$query = '
				SELECT
					count(quest.idquest) as cnt
				FROM
					quest
				LEFT JOIN
					users_quests ON users_quests.questid = quest.idquest
				WHERE
					'.$filters_text.'
		';
	}
	
	$stmt->execute(array(APISecurity::userid()));
	if($row = $stmt->fetch())
		$response['status']['completed'] = $row['cnt'];
} catch(PDOException $e) {
	APIHelpers::showerror(1099, $e->getMessage());
}

// calculate count of types
try {
	$stmt = $conn->prepare('
			SELECT
				quest.subject,
				count(quest.idquest) as cnt
			FROM
				quest
			WHERE
				'.$filters_text.'
			GROUP BY
				quest.subject
	');
	$stmt->execute(array(APIGame::id()));
	while($row = $stmt->fetch())
	{
		$response['subjects'][$row['subject']] = $row['cnt'];
	}
} catch(PDOException $e) {
	APIHelpers::showerror(1100, $e->getMessage());
}

/*$userid = APIHelpers::getParam('userid', 0);*/
$params = array(APISecurity::userid());

// filter by status
$arrWhere_status = array();

if ($response['filter']['open'])
	$arrWhere_status[] = '(isnull(users_quests.dt_passed))';

if ($response['filter']['completed'])
	$arrWhere_status[] = '(not isnull(users_quests.dt_passed))';

$where_status = '';

if (count($arrWhere_status) > 0)
	$where_status = ' AND ('.implode(' OR ', $arrWhere_status).')';

// filter by subjects
$filter_subjects = getParam('filter_subjects', '');
$filter_subjects = explode(',', $filter_subjects);
$arrWhere_subjects = array();
foreach ($filter_subjects as $k){
	if (strlen($k) > 0) {
		$arrWhere_subjects[] = 'quest.subject = ?';
		$params[] = $k;
	}
}
if (count($arrWhere_subjects) > 0)
	$where_status .= ' AND ('.implode(' OR ', $arrWhere_subjects).')';

$query = '
			SELECT 
				quest.idquest,
				quest.name,
				quest.score,
				quest.subject,
				quest.state,
				quest.gameid,
				quest.author,
				quest.text,
				quest.count_user_solved,
				users_quests.dt_passed
			FROM 
				quest
			LEFT JOIN 
				users_quests ON users_quests.questid = quest.idquest AND users_quests.userid = ?
			WHERE
				'.$filters_text.'
				'.$where_status.'
			ORDER BY
				quest.subject, quest.score ASC, quest.score
		';

if($userid==0){
	$query = '
			SELECT 
				quest.idquest,
				quest.name,
				quest.score,
				quest.subject,
				quest.state,
				quest.gameid,
				quest.author,
				quest.text,
				quest.count_user_solved
			FROM 
				quest
			WHERE
				'.$filters_text.'
			ORDER BY
				quest.subject, quest.score ASC, quest.score
		';
}

try {
	$stmt = $conn->prepare($query);
	$stmt->execute($params);
	while($row = $stmt->fetch())
	{
		$status = 'open';
		
		if (!isset($row['dt_passed']))
			$status = 'open';
		else if ($row['dt_passed'] == null)
			$status = 'open';
		else
			$status = 'completed';

		$response['data'][] = array(
			'questid' => $row['idquest'],
			'score' => $row['score'],
			'name' => $row['name'],
			'text' => $row['text'],
			'author' => $row['author'],
			'gameid' => $row['gameid'],
			'subject' => $row['subject'],
			// 'dt_passed' => $row['dt_passed'],
			'state' => $row['state'],
			'solved' => $row['count_user_solved'],
			'status' => $status,
		);
	}
	$response['result'] = 'ok';
	$response['permissions']['insert'] = APISecurity::isAdmin();
	
} catch(PDOException $e) {
	APIHelpers::showerror(1101, $e->getMessage());
}

APIHelpers::endpage($response);

