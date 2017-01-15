<?php

/**
	Copyright 2017 Justin Rouzier

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.

 **/

function encode_name($name) {
	$name = mb_strtolower($name, 'UTF-8');
	$name = str_replace(' ', '', $name);
	//$name = rawurlencode($name); //Pas utile, conflit avec getIDFromName
	return $name;
}

	//API
	$db_infos = parse_ini_file("cfg.ini");
	$api_key = $db_infos['api_key'];

	echo "<strong>Utilisez l'option refetch_players pour actualiser les rangs + joueurs inscrits </strong>";
	echo "<br />";
	echo "<br />";


	function api_call($path) {
		global $api_key;

		$curl_url = "https://euw.api.pvp.net/api/lol/euw/" . $path . '?api_key=' . $api_key;

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $curl_url);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($curl);
		if ($result === false) $result = curl_error($curl);
		curl_close($curl);

		$result_array = json_decode($result, true);

		sleep(1);

		return ($result_array);
	}

	$csvFile = file('pseudos-prod.csv');
	$playersFromFile = [];
	foreach ($csvFile as $line) {
		$playersFromFile[] = str_getcsv($line);
	}
	//print_r($csvFile);

	$playersName = [];
	foreach ($playersFromFile as $data) {
		$playersName[encode_name($data[0])] = array (
			"name" => $data[0],
			"encoded_name" => encode_name($data[0]),
			"team" => $data[1]
			);
	}
	/*
	echo "<pre>";
	print_r($playersName);
	echo "<pre />";*/

	//On va recuperer les IDs de tous les joueurs UNIQUEMENT si demande
	if (isset($_GET['refetch_players']) && $_GET['refetch_players'] == true) {

		function getIdFromNames($playersName, $playersToCheck) {
			$playersToCheck = rtrim($playersToCheck, ',');
			$api_call_path = "v1.4/summoner/by-name/" . $playersToCheck;
			$result = api_call($api_call_path);
			echo "Variable playerstocheck = $playersToCheck <br />";
			//echo "Resultat du call API ID from name";
			//print_r($result);

			$playersToCheck_array = explode(',', $playersToCheck);

			$playersID = [];
			foreach($playersToCheck_array as $player) {
				if (array_key_exists($player, $result)) {
					$id = $result[$player]['id'];
					$playersID[$id] = array (
						"name" => $playersName[$player]['name'],
						"encoded_name" => $playersName[$player]['encoded_name'],
						"team" => $playersName[$player]['team'],
						"id" => $result[$player]['id'],
						"summonerLevel" =>$result[$player]['summonerLevel'],
						"ranked_solo" => "",
						"note" => "");
				} else {
					echo "Le joueur <strong>" . $playersName[$player]['name'] . "</strong> est introuvable dans la BDD Riot<br />";
				}
			}

			return $playersID;
		}

		$count = 0;
		$playersToCheck = "";
		$players = [];
		foreach ($playersName as $player) {
			if ($count === 39) {
				$return_array = getIdFromNames($playersName, $playersToCheck);
				//print_r($return_array);

				$players = $players + $return_array;

				$playersToCheck = "";
				$count = 0;
			} else {
				$playersToCheck .= $player['encoded_name'] . ",";
				$count++;
			}
		}
		if ($count != 0) {
			$return_array = getIdFromNames($playersName, $playersToCheck);
			$players = $players + $return_array;
		}
	/*
		echo "\$players apres hydratation de l'ID <pre>";
		print_r($players);
		echo "<pre />";
	*/
		sleep(1); //reset de la limite de l'API Riot pour les cles de dev

		//On a maintenant tous les IDs Riot des joueurs du tournoi, yay !
		//On va recuperer les informations de leur niveau classe en solo
		//Limite API : 10 joueurs par call. - 32 calls necessaires pour 320 joueurs

		function getLevelFromID(&$players, $playersToCheck) {

			$playersToCheck = rtrim($playersToCheck, ',');
			$api_call_path = "v2.5/league/by-summoner/" . $playersToCheck . "/entry";
			echo "APICALL : $api_call_path <br />";
			$result = api_call($api_call_path);
			//print_r($result);

			$playersToCheck_array = explode(',', $playersToCheck);
			foreach($playersToCheck_array as $player) {
				if (array_key_exists($player, $result)) {
					$players[$player]['ranked_solo'] = 
					$result[strval($player)];
				} else {
					echo "Le joueur <strong>" . $players[$player]['name'] . "</strong> n'est pas classe en SOLO RANKED<br />";
				}
			}
		}

		$count = 0;
		$playersToCheck = "";
		foreach ($players as $player) {
			if ($count === 9) {
				getLevelFromID($players, $playersToCheck);
				$playersToCheck = "";
				$count = 0;
			} else {
				$playersToCheck .= $player['id'] . ",";
				$count++;
			}
		}
		if ($count != 0) {
			getLevelFromID($players, $playersToCheck);
		}

		file_put_contents('data.bin', serialize($players));
		unset($players);
	}

	$players = unserialize(file_get_contents('data.bin'));

	//On Calcule maintenant la note de chaque joueur
	foreach($players as &$player) {
		if (!empty($player['ranked_solo'][0]['tier'])) {
			$tier = $player['ranked_solo'][0]['tier'];
			$division = $player['ranked_solo'][0]['entries'][0]['division'];

			if ($tier == "BRONZE") {
				$player['note'] = 13;
			} else if ($tier == "SILVER") {
				$player['note'] = 12;
			} else if ($tier == "GOLD" && ($division == "V" || $division == "IV")) {
				$player['note'] = 11;
			} else if ($tier == "GOLD" && $division == "III" ) {
				$player['note'] = 10;
			} else if ($tier == "GOLD" && ($division == "I" || $division == "II")) {
				$player['note'] = 9;
			} else if ($tier == "PLATINUM" && ($division == "V" || $division == "IV")) {
				$player['note'] = 8;
			} else if ($tier == "PLATINUM" && $division == "III") {
				$player['note'] = 7;
			} else if ($tier == "PLATINUM" && ($division == "I" || $division == "II")) {
				$player['note'] = 6;
			} else if ($tier == "DIAMOND" && ($division == "V" || $division == "IV")) {
				$player['note'] = 5;
			} else if ($tier == "DIAMOND" && $division == "III") {
				$player['note'] = 4;
			} else if ($tier == "DIAMOND" && ($division == "I" || $division == "II")) {
				$player['note'] = 3;
			} else if ($tier == "MASTER") {
				$player['note'] = 2;
			} else if ($tier == "CHALLENGER") {
				$player['note'] = 1;
			}
		} else {
			$player['note'] = "0";
		}
	}

	//Petite fonction pour obtenir la couleur en fonction de la note

	function get_background_color($note) {
		switch ($note) {
			case 1:
				return "black";
			case 2:
				return "black";
			case 3:
				return "black";
			case 4:
				return "purple";
			case 5:
				return "purple";
			case 6:
				return "red";
			case 7:
				return "orange";
			case 8:
				return "orange";
			case 9:
				return "yellow";
			case 10:
				return "blue";
			case 11:
				return "blue";
			case 12:
				return "green";
			case 13:
				return "green";
			return "white";
		}
	}

	//On va creer un tableau teams avec les informations des joueurs

	function getTeamNote($players, $team) {
		$playersNote = [];

		foreach($players as $player) {
			if ($player['note'] != 0 && $player['team'] == $team) {
				$playersNote[] = $player['note'];
			}
		}
		return (int)(ceil(array_sum($playersNote) / count($playersNote)));
	}

	// On affiche un super tableau maintenant

?>

<table width="1200px">
	<tr>
		<th>Pseudo</th>
		<th>Equipe</th>
		<th>Rang Solo</th>
		<th>Note</th>
		<th>Moyenne d'equipe</th>
	</tr>
<?php
$lastTeam = "";
foreach ($players as $player) {	
	$teamNote = getTeamNote($players, $player['team']);

	echo "<tr>";
	echo "<td>" . $player['name'] . "</td>";
	echo "<td>" . $player['team'] . "</td>";
	if (!empty($player['ranked_solo'][0]['tier'])) {
		echo "<td>" . $player['ranked_solo'][0]['tier'] . " - Division " . $player['ranked_solo'][0]['entries'][0]['division'] . "</td>";
	} else { echo "<td>Non classe</td>"; }
	echo "<td style=\"text-decoration:underline; text-align:center; background-color: ". get_background_color($player['note']) .";\" ><mark>" . $player['note'] . "</mark></td>";
	echo "<td style=\"text-decoration:underline; text-align:center; background-color: ". get_background_color($teamNote) .";\" ><mark>" . $teamNote . "</mark></td>";
	echo "";

	echo "<tr />";
}